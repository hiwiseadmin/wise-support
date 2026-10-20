<?php
declare(strict_types=1);

namespace wise\support\env;

use wise\support\crypto\OpenSslConfig;

/**
 * .env 值生成器 —— 封闭策略集
 *
 * 安装侧 EnvSeedService 调用本类，为契约中 type=secret 的项生成真实随机值。
 * 策略集合是「封闭」的：包不能在契约里注入任意代码，只能引用这里登记的名字，
 * 避免安装期执行不可信逻辑。
 *
 * 策略：
 *   - random_bytes_32 ：64 位十六进制随机串（通用密钥）
 *   - hmac_salt       ：URL-safe Base64 随机盐（设备指纹 / HMAC 盐）
 *   - uuid            ：UUID v4
 *   - rsa_2048_pem    ：RSA 2048 密钥对。
 *                       若不传 file_dir → 返回 ['private'=>PEM, 'public'=>PEM]（含换行，通常不直接进 .env）；
 *                       若传 file_dir  → 将密钥写入该目录（私钥在写入内容前即设为 0600，
 *                       并在 POSIX 上校验实际模式；无法限制为 0600 时删除该文件并抛错），返回
 *                       ['private'=>"file://绝对路径", 'public'=>"file://绝对路径"]，
 *                       可直接作为单行 env 值，契合 config 中 file:// 引用方式。
 */
class EnvValueGenerator
{
    /**
     * @param array<string,mixed> $options 仅消费已知键：file_dir（相对/绝对目录）、file_prefix
     * @return string|array<string,string>
     * @throws \InvalidArgumentException 未知策略
     */
    public function generate(string $strategy, array $options = []): string|array
    {
        return match ($strategy) {
            'random_bytes_32' => bin2hex(random_bytes(32)),
            'hmac_salt'       => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            'uuid'            => $this->uuid4(),
            'rsa_2048_pem'    => $this->rsaKeypair(
                $options['file_dir'] ?? null,
                $options['file_prefix'] ?? 'wise'
            ),
            default           => throw new \InvalidArgumentException("未知 env 生成策略: {$strategy}"),
        };
    }

    private function uuid4(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        $hex = bin2hex($d);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * 生成 RSA 2048 密钥对
     *
     * @return string|array<string,string>
     */
    private function rsaKeypair(?string $fileDir, string $prefix): string|array
    {
        // 上下文标识：让多条异常信息可定位到具体是哪个密钥对
        $ctx = 'RSA 密钥对' . ($prefix !== '' ? "[{$prefix}]" : '');

        if (!extension_loaded('openssl')) {
            throw new \RuntimeException("{$ctx}：openssl 扩展不可用，无法生成");
        }

        // Windows 下 openssl 扩展默认会去读取系统 openssl.cnf，缺失时
        // 报 error:80000003:system library::No such process，故统一由
        // OpenSslConfig 定位配置（含随包兜底）并显式传入。
        $res = openssl_pkey_new(OpenSslConfig::withConfig([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]));
        if ($res === false) {
            throw new \RuntimeException("{$ctx}：生成密钥对失败(openssl_pkey_new): " . openssl_error_string());
        }

        // export 不会继承 openssl_pkey_new 传入的 config，Windows 下需再次显式传入，
        // 否则报 error:80000003:system library::No such process
        $exported = openssl_pkey_export($res, $privatePem, null, OpenSslConfig::withConfig([]));
        if ($exported === false) {
            throw new \RuntimeException("{$ctx}：导出私钥失败: " . openssl_error_string());
        }
        $details = openssl_pkey_get_details($res);
        if ($details === false || !isset($details['key'])) {
            throw new \RuntimeException("{$ctx}：导出公钥失败: " . openssl_error_string());
        }
        $publicPem = $details['key'];

        if ($fileDir === null) {
            return ['private' => $privatePem, 'public' => $publicPem];
        }

        $absDir = $this->normalizeDir($fileDir);
        if (!is_dir($absDir)) {
            @mkdir($absDir, 0700, true);
        }
        if (!is_dir($absDir) || !is_writable($absDir)) {
            throw new \RuntimeException("{$ctx}：密钥目录不可写: {$absDir}");
        }

        $privPath = $absDir . DIRECTORY_SEPARATOR . $prefix . '_private.pem';
        $pubPath  = $absDir . DIRECTORY_SEPARATOR . $prefix . '_public.pem';

        // 私钥：同目录独占临时文件 → 写内容前先设 0600 → 原子 rename → 校验实际模式。
        // 不能先 file_put_contents 再 @chmod：file_put_contents 会用 0666 & ~umask 创建，
        // 私钥在 chmod 生效前就已落到磁盘（默认 umask 0022 时为 0644，其他本地账户可读），
        // 而 @chmod 的返回值被丢弃后无从发现「模式其实没生效」。
        $tmpPriv = $privPath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $fh = @fopen($tmpPriv, 'x');
        if ($fh === false) {
            throw new \RuntimeException("{$ctx}：创建私钥临时文件失败: {$tmpPriv}");
        }
        @chmod($tmpPriv, 0600);

        $written = fwrite($fh, $privatePem) === strlen($privatePem);
        fclose($fh);

        if (!$written || !@rename($tmpPriv, $privPath)) {
            @unlink($tmpPriv);
            throw new \RuntimeException("{$ctx}：写入私钥失败: {$privPath}");
        }

        // 校验实际生效的模式（Windows 的 chmod 只切换只读位，无法限制读权限，故跳过）
        if (PHP_OS_FAMILY !== 'Windows') {
            $mode = @fileperms($privPath);
            if ($mode === false || ($mode & 0077) !== 0) {
                @unlink($privPath);
                throw new \RuntimeException(
                    "{$ctx}：私钥权限无法限制为 0600（实际 "
                    . ($mode === false ? '未知' : decoct($mode & 0777))
                    . "），已删除该文件: {$privPath}"
                );
            }
        }

        if (file_put_contents($pubPath, $publicPem) === false) {
            throw new \RuntimeException("{$ctx}：写入公钥失败: {$pubPath}");
        }
        @chmod($pubPath, 0644);

        // 生成标准 file:// URI（统一正斜杠），Windows 下为 file:///D:/path/xx.pem
        $privAbs = str_replace('\\', '/', $privPath);
        $pubAbs  = str_replace('\\', '/', $pubPath);
        return [
            'private' => 'file://' . $privAbs,
            'public'  => 'file://' . $pubAbs,
        ];
    }

    private function normalizeDir(string $dir): string
    {
        // 已绝对路径直接返回
        if (str_starts_with($dir, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $dir)) {
            return rtrim($dir, '/\\');
        }
        // 相对路径按当前工作目录解析（库内不依赖 Think 全局函数，保证可独立测试）
        $cwd = getcwd();
        if ($cwd === false) {
            throw new \RuntimeException('无法确定当前工作目录，无法解析相对密钥目录: ' . $dir);
        }
        return rtrim($cwd, '/\\') . DIRECTORY_SEPARATOR . $dir;
    }
}
