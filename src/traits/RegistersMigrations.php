<?php
declare(strict_types=1);

namespace wise\support\traits;

/**
 * 迁移路径注册 Trait
 *
 * 供包的 ServiceProvider 在 boot() 中调用，将包内迁移目录注册到全局迁移扫描路径
 * （config('migration.paths')）。
 *
 * 从此不再需要 publishMigrations() 复制文件，迁移引擎（WiseMigrate）直接扫描各包的原
 * 始迁移目录。
 *
 * 用法：
 *
 *   use \wise\support\traits\RegistersMigrations;
 *
 *   public function boot(): void
 *   {
 *       $this->registerMigrations();                     // 默认 {包根}/database/migrations
 *       $this->registerMigrations('database/migrations-v2'); // 自定义（相对包根）
 *       $this->registerMigrations('D:/data/migrations');     // 自定义（绝对路径，须在包根内）
 *   }
 *
 * 说明：
 *   - 路径解析以**包根**（ServiceProvider 所在文件向上两级）为基准，因此移动包目录、
 *     vendor 与 path 仓库（软链）两种安装形态都无需改配置；
 *   - 相对路径按包根拼接，绝对路径原样使用，两者都会先归一化（处理 `.` / `..` /
 *     混合分隔符），归一化后**必须仍位于包根内**，否则抛 InvalidArgumentException
 *     （防止配置失误把宿主目录下的迁移卷进来）；
 *   - 目录不存在时静默跳过；同一路径重复注册会自动去重；
 *   - 只在真正执行 CLI 命令时生效（`Console::starting`），HTTP / Swoole worker 天然跳过。
 */
trait RegistersMigrations
{
    /**
     * 默认迁移目录（相对包根）
     */
    public const DEFAULT_MIGRATIONS_DIR = 'database/migrations';

    /**
     * 将当前包的迁移目录注册到全局迁移扫描路径
     *
     * @param string|null $path 迁移目录；null/空串 = 默认 `{包根}/database/migrations`
     * @return void
     */
    protected function registerMigrations(?string $path = null): void
    {
        \think\Console::starting(function () use ($path) {
            $this->registerMigrationsNow($path);
        });
    }

    /**
     * 实际的迁移路径注册逻辑（在 Console::starting 回调中执行）
     *
     * @param string|null $path 迁移目录（由 registerMigrations() 透传）
     */
    private function registerMigrationsNow(?string $path = null): void
    {
        $migrationDir = $this->resolveMigrationDir($path);

        if (!is_dir($migrationDir)) {
            return;
        }

        $paths = $this->app->config->get('migration.paths', []);
        if (!in_array($migrationDir, $paths, true)) {
            $paths[] = $migrationDir;
            $this->app->config->set(['paths' => $paths], 'migration');
        }
    }

    /**
     * 解析迁移目录为归一化后的绝对路径
     *
     * @param string|null $path 用户声明的目录（相对包根 / 绝对路径 / null）
     * @return string
     * @throws \InvalidArgumentException 解析后越出包根
     */
    private function resolveMigrationDir(?string $path): string
    {
        $packageRoot = $this->migrationPackageRoot();

        if ($path === null || trim($path) === '') {
            return $this->normalizeMigrationPath(
                $packageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::DEFAULT_MIGRATIONS_DIR)
            );
        }

        $path = trim($path);
        $isAbsolute = str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;

        $candidate = $isAbsolute
            ? $path
            : $packageRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        $resolved = $this->normalizeMigrationPath($candidate);

        if (!$this->isInsideMigrationPackage($resolved, $packageRoot)) {
            throw new \InvalidArgumentException(sprintf(
                'registerMigrations(): 迁移目录 "%s" 解析为 "%s"，越出包根 "%s"，已拒绝注册。',
                $path,
                $resolved,
                $packageRoot
            ));
        }

        return $resolved;
    }

    /**
     * 包根目录：ServiceProvider 所在文件向上两级
     *
     * 例：`packages/wiseadmin/wise-url/src/UrlServiceProvider.php` → 包根
     * （vendor / path 仓库两种安装形态一致，软链也不影响）。
     *
     * 插件不走本 trait：建表迁移由插件引擎接管（`plugins/{ident}/database/migrations`）。
     *
     * 改为 protected 以便子类覆盖：当 ServiceProvider 不在 `src/` 根、而深了一层
     * （如 `wise-auth/src/auth/AuthServiceProvider.php`，命名空间 `wise\auth`，
     * 包根需向上三级）时，覆盖本方法即可，不必改动通用约定。
     */
    protected function migrationPackageRoot(): string
    {
        $reflection = new \ReflectionClass($this);

        return dirname((string) $reflection->getFileName(), 2);
    }

    /**
     * 路径归一化：统一分隔符，消解 `.` / `..` 与重复分隔符（不要求目录已存在）
     */
    private function normalizeMigrationPath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        $prefix = '';
        if (preg_match('#^([A-Za-z]:)' . preg_quote(DIRECTORY_SEPARATOR, '#') . '#', $path, $m) === 1) {
            $prefix = strtoupper($m[1]) . DIRECTORY_SEPARATOR;
            $path   = substr($path, strlen($m[0]));
        } elseif (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $prefix = DIRECTORY_SEPARATOR;
        }

        $segments = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $prefix . implode(DIRECTORY_SEPARATOR, $segments);
    }

    /**
     * 判断路径是否位于包根之内（Windows 下大小写不敏感）
     */
    private function isInsideMigrationPackage(string $path, string $packageRoot): bool
    {
        $path        = $this->normalizeMigrationPath($path);
        $packageRoot = rtrim($this->normalizeMigrationPath($packageRoot), DIRECTORY_SEPARATOR);

        if (DIRECTORY_SEPARATOR === '\\') {
            $path        = strtolower($path);
            $packageRoot = strtolower($packageRoot);
        }

        return $path === $packageRoot
            || str_starts_with($path, $packageRoot . DIRECTORY_SEPARATOR);
    }
}
