<?php
declare(strict_types=1);

namespace wise\support\traits;

/**
 * 迁移路径注册 Trait
 *
 * 供 wise-* 包的 ServiceProvider 在 boot() 中调用，将包内 database/migrations/ 目录
 * 注册到全局迁移扫描路径（config('migration.paths')）。
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
 *       $this->registerMigrations();
 *   }
 */
trait RegistersMigrations
{
    /**
     * 将当前包的 database/migrations/ 目录注册到全局迁移扫描路径
     *
     * 通过 ReflectionClass 自动定位 ServiceProvider 所在文件，向上两级即为包根目录。
     * 迁移路径只需在真正执行 CLI 命令（php think migrate:*）前注册，迁移目录不存在时静默跳过。
     *
     * @return void
     */
    protected function registerMigrations(): void
    {
        // Swoole HTTP worker 中 runningInConsole() 恒为 true，原判断会误注册迁移路径；
        // 改用 Console::starting：仅在真正执行 CLI 命令时触发，HTTP worker 天然跳过。
        \think\Console::starting(function () {
            $this->registerMigrationsNow();
        });
    }

    /**
     * 实际的迁移路径注册逻辑（在 Console::starting 回调中执行）
     *
     * @return void
     */
    private function registerMigrationsNow(): void
    {
        $reflection = new \ReflectionClass($this);
        $packageRoot = dirname($reflection->getFileName(), 2);
        $migrationDir = $packageRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';

        if (!is_dir($migrationDir)) {
            return;
        }

        // 从全局配置读取已有路径，避免重复注册
        $paths = $this->app->config->get('migration.paths', []);
        if (!in_array($migrationDir, $paths, true)) {
            $paths[] = $migrationDir;
            $this->app->config->set(['paths' => $paths], 'migration');
        }
    }
}

