<?php
declare(strict_types=1);

namespace wise\support\env;

use wise\support\contracts\EnvSeedProvider;

/**
 * 安装期 .env 密钥播种引擎（通用，置于 wise-support 共享工具包）
 *
 * 职责（方案二：包内声明契约 + 安装时按规则发现）：
 *   1. 扫描 packages/wiseadmin/ * /composer.json 的 extra.think.services，
 *      得到所有已注册 ServiceProvider 类。
 *   2. 过滤出实现 wise\support\contracts\EnvSeedProvider 的包（静态方法，无需实例化）。
 *   3. 收集各包 envSeeds() 返回的契约，按 type 幂等合并进 .env。
 *
 * 幂等规则（核心安全约束）：
 *   - 任意键若 .env 中已存在，绝不覆盖——保证重装 / 二次安装不会洗掉用户已生效的密钥。
 *   - secret  ：缺失时按 generator 生成真实随机值写入；
 *               generator 返回数组时（如 RSA 密钥对），经 spec['keys'] 映射为真实 env 键名。
 *   - external：缺失时写入一行带注释的空占位，提示用户手动填写（绝不写假凭证）。
 *   - static  ：缺失时写入 default。
 *
 * 设计为通用能力，安装命令（app/install）与各包的 Publish 命令均可复用。
 */
class EnvSeedService
{
    private EnvValueGenerator $generator;

    public function __construct(?EnvValueGenerator $generator = null)
    {
        $this->generator = $generator ?? new EnvValueGenerator();
    }

    /**
     * 从 Composer 已安装包清单收集所有 ServiceProvider 类名
     *
     * 数据源：vendor/composer/installed.json 的 packages 数组（含每个包的 name 与
     * extra.think.services）。相较遍历 packages/ 或 vendor/ 目录，该清单由 Composer
     * 在 install/update 后精确生成，具备以下优势：
     *   1. 与目录布局解耦——无论包位于 packages/<pkg>/（path 仓库 symlink）还是
     *      vendor/<pkg>/（标准安装），乃至自定义 vendor-dir，均可统一发现；
     *   2. 天然覆盖所有声明 extra.think.services 的包（含第三方），而非仅 wiseadmin/*
     *      前缀，从而兼容任何实现了 EnvSeedProvider 契约的包。
     *
     * 注意：installed.json 在 `composer install --no-dev` 或 `--classmap-authoritative`
     * 等场景下仍由 Composer 维护；仅当清单文件缺失或解析失败时回退到空结果（不影响
     * 安装流程），但会通过 $warnings 引用输出告警，便于排查「密钥播种静默失效」问题。
     *
     * @param string $vendorDir vendor 目录（调用方传入项目根下的 vendor 目录绝对路径）
     * @param string[] $warnings 引用传出：缺失 / 解析失败等告警信息
     * @return string[]
     */
    public function discoverProviderClasses(string $vendorDir, array &$warnings = []): array
    {
        $installedFile = rtrim($vendorDir, '/\\') . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.json';
        if (!is_file($installedFile)) {
            $warnings[] = "Composer 已安装包清单不存在（{$installedFile}），跳过包级密钥播种。请先执行 composer install/update。";
            return [];
        }

        $raw = @file_get_contents($installedFile);
        if ($raw === false) {
            $warnings[] = "读取 Composer 已安装包清单失败：{$installedFile}";
            return [];
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || !isset($data['packages']) || !is_array($data['packages'])) {
            $warnings[] = "解析 Composer 已安装包清单失败：{$installedFile}（" . json_last_error_msg() . '）';
            return [];
        }

        $classes = [];
        foreach ($data['packages'] as $pkg) {
            if (!is_array($pkg)) {
                continue;
            }
            $services = $pkg['extra']['think']['services'] ?? [];
            foreach ((array) $services as $svc) {
                if (is_string($svc)) {
                    $classes[] = $svc;
                }
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * 收集实现 EnvSeedProvider 的包声明的契约
     *
     * @param string[] $providerClasses
     * @return array<string,array{type:string,generator?:?string,keys?:array<string,string>,default?:string,comment?:string,file_dir?:string}>
     */
    public function collectSpecs(array $providerClasses): array
    {
        $specs = [];
        foreach ($providerClasses as $class) {
            // 无需实例化：仅判断类是否实现接口 + 静态方法可调用
            if (!is_subclass_of($class, EnvSeedProvider::class)) {
                continue;
            }
            if (!is_callable([$class, 'envSeeds'])) {
                continue;
            }
            foreach ($class::envSeeds() as $key => $spec) {
                // 同一 env 键由多个包声明时，后扫描到的包覆盖先扫描的（后者覆盖前者）。
                // 由于最终写入阶段是幂等合并（.env 已存在即跳过），此处仅决定「以谁的契约为准」。
                $specs[$key] = $spec;
            }
        }
        return $specs;
    }

    /**
     * 一键播种：发现（discover）→ 收集（collect）→ 幂等合并（merge）进 .env
     *
     * 聚合上述三个步骤，供 CLI（command）与 Web（service）两种安装入口统一复用，
     * 避免各自重复编排。warnings 统一由返回值承载，由调用方按各自输出通道呈现
     * （command 走 $this->output，service 走 error_log / Log）。
     *
     * @param string $envPath .env 绝对路径
     * @param string|null $vendorDir vendor 目录；缺省时由本类所在位置推导项目根 + vendor
     * @return array{written:string[],skipped:string[],warnings:string[]}
     */
    public function seed(string $envPath, ?string $vendorDir = null): array
    {
        if ($vendorDir === null) {
            // 本类路径为 <root>/(packages|vendor)/wiseadmin/wise-support/src/env，
            // 向上 5 层即项目根（两种部署布局深度一致），拼接 vendor。
            $vendorDir = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'vendor';
        }

        $warnings  = [];
        $providers = $this->discoverProviderClasses($vendorDir, $warnings);
        $specs     = $this->collectSpecs($providers);
        $report    = $this->mergeIntoEnv($specs, $envPath);

        // 发现阶段的告警（installed.json 缺失/解析失败）并入最终报告
        $report['warnings'] = array_merge($warnings, $report['warnings']);
        return $report;
    }

    /**
     * 将契约幂等合并进 .env
     *
     * @param array<string,mixed> $specs
     * @param string $envPath .env 绝对路径
     * @return array{written:string[],skipped:string[],warnings:string[]}
     */
    public function mergeIntoEnv(array $specs, string $envPath): array
    {
        $report = ['written' => [], 'skipped' => [], 'warnings' => []];

        if (!file_exists($envPath)) {
            $report['warnings'][] = '.env 不存在（' . $envPath . '），跳过包级密钥播种';
            return $report;
        }

        $root = dirname($envPath);

        // 加锁避免并发安装竞态覆盖 .env。
        //
        // 锁文件放在系统临时目录（名字取 .env 绝对路径的散列），**不锁 .env 本身**：
        // 锁 .env 会在提交阶段持有目标文件句柄，而 Windows 下用 rename 覆盖「被本进程
        // 打开」的文件会失败，并可能把目标文件删除/清空（实测）。因此提交改为
        // 「同目录临时文件 + 原子 rename」，且提交时不得持有 .env 的句柄。
        $lockPath   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise-env-seed-' . md5($envPath) . '.lock';
        $lockHandle = @fopen($lockPath, 'c');
        if ($lockHandle === false || !@flock($lockHandle, LOCK_EX)) {
            if ($lockHandle !== false) {
                @fclose($lockHandle);
            }
            // 拿不到独占锁时绝不能在无锁状态下读改写 .env（并发安装会互相覆盖）
            $report['warnings'][] = '.env 播种锁不可用，已跳过（未在无锁状态下改写 .env）：' . $envPath;
            return $report;
        }

        // 快照必须在持锁之后读取：否则幂等判定的既存键集合可能已被其他写者失效
        $content      = (string) file_get_contents($envPath);
        $lines        = explode("\n", $content);
        $existingKeys = $this->extractKeys($lines);

        $append = '';
        $failures = [];
        foreach ($specs as $logicalKey => $spec) {
            $type    = $spec['type'] ?? 'static';
            $comment = $spec['comment'] ?? '';

            if ($type === 'secret') {
                $generated = $this->generateValue((string) $logicalKey, $spec, $root, $failures);
                if ($generated === null) {
                    continue;
                }
                if (is_array($generated)) {
                    $map = $spec['keys'] ?? [];
                    foreach ($generated as $genKey => $value) {
                        $realKey = $map[$genKey] ?? $genKey;
                        $this->applyKey($existingKeys, $realKey, (string) $value, $comment, $report, $append);
                    }
                } else {
                    $this->applyKey($existingKeys, (string) $logicalKey, $generated, $comment, $report, $append);
                }
            } elseif ($type === 'external') {
                $default = $spec['default'] ?? '';
                $this->applyKey($existingKeys, (string) $logicalKey, (string) $default, $comment, $report, $append, true);
            } else { // static
                $default = $spec['default'] ?? '';
                $this->applyKey($existingKeys, (string) $logicalKey, (string) $default, $comment, $report, $append);
            }
        }

        // 汇总 secret 生成失败为一条告警，避免刷屏
        if ($failures !== []) {
            $report['warnings'][] = 'secret 密钥生成失败 ' . count($failures) . ' 项：' . implode('；', $failures);
        }

        if ($append !== '') {
            $content = rtrim($content);
            $content .= "\n" . $append;

            // 同目录临时文件 + 原子 rename 提交。
            //
            // 不再就地 rewind/ftruncate(0)/fwrite：那会在 ftruncate 与 fwrite 之间的
            // 任何失败/短写下把 .env 截断（既有密钥一并丢失），且没有回滚。
            // 临时文件必须与 .env 同目录，rename 才是同一文件系统内的原子替换。
            $tmp = $envPath . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (@file_put_contents($tmp, $content) === false || !@rename($tmp, $envPath)) {
                @unlink($tmp);
                $report['warnings'][] = '.env 写入失败（原文件未被改动）：' . $envPath;
            }
        }

        if (isset($lockHandle) && $lockHandle !== false) {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }

        return $report;
    }

    /**
     * @param string $logicalKey 逻辑 env 键名（用于失败信息定位）
     * @param array<string,mixed> $spec
     * @param string $root 项目根目录
     * @param string[] $failures 引用传出：收集单条失败详情，由上层汇总为一条告警
     * @return string|array<string,string>|null
     */
    private function generateValue(string $logicalKey, array $spec, string $root, array &$failures): string|array|null
    {
        $strategy = $spec['generator'] ?? null;
        if (!$strategy) {
            $failures[] = "{$logicalKey}: 契约项缺少 generator";
            return null;
        }

        $options = $spec;
        if (!empty($spec['file_dir'])) {
            $options['file_dir'] = $this->resolveDir($root, (string) $spec['file_dir']);
        }

        try {
            return $this->generator->generate($strategy, $options);
        } catch (\Throwable $e) {
            $failures[] = "{$logicalKey}({$strategy}): " . $e->getMessage();
            return null;
        }
    }

    /**
     * @param array<string,bool> $existingKeys 键名 => true
     * @param array{written:string[],skipped:string[],warnings:string[]} $report
     */
    private function applyKey(array &$existingKeys, string $key, string $value, string $comment, array &$report, string &$append, bool $placeholder = false): void
    {
        if (isset($existingKeys[$key])) {
            $report['skipped'][] = $key;
            return;
        }

        // 注释统一使用「;」而非「#」：
        // PHP 的 parse_ini_file 对以「#」开头的行会做部分扫描，注释文本中若含
        // 半角括号等保留字符（如 "(HS256)"）会触发 "syntax error, unexpected '('"，
        // 导致整个 .env 解析失败；而「;」开头的行被完整视为注释，安全无副作用。
        if ($placeholder) {
            // 外部凭证：留空占位 + 注释，绝不写假值
            $append .= "\n; {$comment}\n; 请在下方填入真实值（当前为空）\n{$key} =\n";
        } else {
            $append .= "\n" . ($comment !== '' ? "; {$comment}\n" : '') . "{$key} = " . $this->quoteIfNeeded($value) . "\n";
        }

        $report['written'][] = $key;
        $existingKeys[$key]  = true;
    }

    /**
     * 解析相对目录为绝对路径（相对项目根）
     */
    private function resolveDir(string $root, string $dir): string
    {
        if (str_starts_with($dir, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $dir)) {
            return rtrim($dir, '/\\');
        }
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $dir;
    }

    /**
     * 含空白的值用双引号包裹，避免 .env 解析歧义
     */
    private function quoteIfNeeded(string $value): string
    {
        if (preg_match('/\s/', $value)) {
            return '"' . str_replace('"', '\\"', $value) . '"';
        }
        return $value;
    }

    /**
     * 从 .env 行中提取已存在的键名
     *
     * 兼容常见 .env 书写变体：
     *   - KEY=value / KEY = value（含空白）
     *   - KEY="value" / KEY='value'（引号包裹）
     *   - export KEY=value（shell 导出前缀）
     * 同时排除注释行（# / ; 开头），避免把注释里出现的键误判为「已存在」。
     *
     * @param string[] $lines
     * @return array<string,bool>
     */
    private function extractKeys(array $lines): array
    {
        $keys = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            // 跳过注释行与空行
            if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';') {
                continue;
            }
            // 可选 export 前缀 + 键名 + 可选空白 + =（值部分可带引号，不影响键名提取）
            if (preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $trimmed, $m)) {
                $keys[$m[1]] = true;
            }
        }
        return $keys;
    }
}
