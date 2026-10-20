<?php
declare(strict_types=1);

namespace wise\support\crypto;

/**
 * OpenSSL 配置定位器（跨平台）
 *
 * 背景：Windows 环境下 PHP 的 openssl 扩展在调用 openssl_pkey_new()
 * 等函数时，若系统未提供默认 openssl.cnf（XAMPP/WAMP/phpstudy 等常见），
 * 会报：
 *   error:80000003:system library::No such process
 *
 * 本类按优先级定位可用的 openssl.cnf，最终兜底到随包提供的最小配置，
 * 从而让密钥生成在任意环境都能成功。
 */
final class OpenSslConfig
{
    private static ?string $resolved = null;
    private static bool $resolvedDone = false;

    private function __construct()
    {
    }

    /**
     * 返回可用的 openssl.cnf 绝对路径；正常环境始终非 null（含随包兜底）。
     */
    public static function path(): ?string
    {
        if (self::$resolvedDone) {
            return self::$resolved;
        }
        self::$resolvedDone = true;

        foreach (self::candidates() as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                self::$resolved = $candidate;
                return self::$resolved;
            }
        }

        // 兜底：随包提供的最小配置（src/crypto → 包根 resources/openssl.cnf）
        $bundled = realpath(__DIR__ . '/../../resources/openssl.cnf');
        self::$resolved = $bundled !== false ? $bundled : null;

        return self::$resolved;
    }

    /**
     * 在给定的 openssl 参数上补充 config 项（若已定位到配置）
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function withConfig(array $options): array
    {
        $path = self::path();
        if ($path !== null) {
            $options['config'] = $path;
        }

        return $options;
    }

    /**
     * 候选 openssl.cnf 路径（按优先级排序）
     *
     * @return array<int,string>
     */
    private static function candidates(): array
    {
        $list = [];

        // 1. 环境变量显式指定
        $env = getenv('OPENSSL_CONF');
        if (is_string($env) && $env !== '') {
            $list[] = $env;
        }

        // 2. 与当前 PHP 二进制捆绑的 openssl.cnf（Windows 常见位置）
        if (defined('PHP_BINARY') && PHP_BINARY !== '') {
            $phpDir = dirname(PHP_BINARY);
            $list[] = $phpDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
            $list[] = $phpDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'openssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
        }

        // 3. *nix 常见系统路径
        $list[] = '/etc/ssl/openssl.cnf';
        $list[] = '/etc/pki/tls/openssl.cnf';
        $list[] = '/usr/lib/ssl/openssl.cnf';
        $list[] = '/usr/local/ssl/openssl.cnf';

        return $list;
    }
}
