<?php

declare(strict_types=1);

namespace wise\support\crypto;

/**
 * AES-256-GCM 加解密底层封装
 *
 * 提供 GCM 模式认证加密（AEAD），调用 PHP OpenSSL 扩展。
 *
 * 特性：
 * - AES-256-GCM：256 位密钥 + 12 字节随机 IV
 * - 自动生成随机密钥和 IV
 * - 加密返回 ciphertext + tag（认证标签）
 * - 解密自动校验 GCM tag（防篡改）
 * - 每字节 AAD 性能开销不可忽略（AAD ≤ 16 字节建议）
 *
 * 使用示例：
 * <pre>
 * $gcm  = new AesGcm();
 * $key  = $gcm->generateKey();
 * $iv   = $gcm->generateIv();
 * $enc  = $gcm->encrypt('hello', $key, $iv, 'aad-data');
 * $dec  = $gcm->decrypt($enc['ciphertext'], $key, $iv, $enc['tag'], 'aad-data');
 * </pre>
 *
 * @package wise\support\crypto
 */
class AesGcm
{
    /**
     * AES 密钥长度（字节）：256 bits
     */
    public const KEY_LENGTH = 32;

    /**
     * GCM IV 长度（字节）：12 bytes（NIST SP 800-38D 推荐）
     */
    public const IV_LENGTH = 12;

    /**
     * GCM tag 长度（字节）：16 bytes（最高安全级别）
     */
    public const TAG_LENGTH = 16;

    /**
     * OpenSSL 加密算法标识
     */
    private const ALGO = 'aes-256-gcm';

    /**
     * 生成随机 AES-256 密钥
     *
     * 使用 PHP 7+ random_bytes()，基于系统 CSPRNG。
     *
     * @return string 32 字节随机密钥
     */
    public function generateKey(): string
    {
        return random_bytes(self::KEY_LENGTH);
    }

    /**
     * 生成随机 IV
     *
     * GCM 模式下 IV 必须是 12 字节（96 bits），每次加密使用新的随机 IV。
     * 违反此规则（同一密钥下 IV 重复）将导致 GCM 安全性完全崩溃。
     *
     * @return string 12 字节随机 IV
     */
    public function generateIv(): string
    {
        return random_bytes(self::IV_LENGTH);
    }

    /**
     * AES-256-GCM 加密
     *
     * @param string $plaintext 明文数据
     * @param string $key       32 字节密钥
     * @param string $iv        12 字节初始化向量
     * @param string $aad       附加认证数据（Additional Authenticated Data），可选
     *                          用于绑定上下文（如时间戳、nonce），防止密文在其它上下文重放
     * @return array{ciphertext: string, tag: string} 密文 + GCM 认证标签
     * @throws \InvalidArgumentException 密钥/IV 长度不正确时抛出
     */
    public function encrypt(string $plaintext, string $key, string $iv, string $aad = ''): array
    {
        $this->validateKeyAndIv($key, $iv);

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::ALGO,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException(
                'AES-256-GCM encrypt failed: ' . openssl_error_string()
            );
        }

        return [
            'ciphertext' => $ciphertext,
            'tag'        => $tag,
        ];
    }

    /**
     * AES-256-GCM 解密
     *
     * 自动校验 GCM 认证标签，如果标签不匹配（数据被篡改）则抛出异常。
     *
     * @param string $ciphertext 密文数据
     * @param string $key        32 字节密钥（必须与加密时相同）
     * @param string $iv         12 字节 IV（必须与加密时相同）
     * @param string $tag        16 字节 GCM 认证标签
     * @param string $aad        附加认证数据（必须与加密时相同）
     * @return string 明文数据
     * @throws \InvalidArgumentException 密钥/IV 长度不正确时抛出
     * @throws \RuntimeException 认证标签校验失败（数据被篡改）
     */
    public function decrypt(string $ciphertext, string $key, string $iv, string $tag, string $aad = ''): string
    {
        $this->validateKeyAndIv($key, $iv);

        if (strlen($tag) !== self::TAG_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('GCM tag must be %d bytes, got %d', self::TAG_LENGTH, strlen($tag))
            );
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::ALGO,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad
        );

        if ($plaintext === false) {
            throw new \RuntimeException(
                'AES-256-GCM decrypt failed: ' . openssl_error_string()
            );
        }

        return $plaintext;
    }

    /**
     * 验证密钥和 IV 长度
     *
     * @throws \InvalidArgumentException
     */
    private function validateKeyAndIv(string $key, string $iv): void
    {
        if (strlen($key) !== self::KEY_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('AES key must be %d bytes, got %d', self::KEY_LENGTH, strlen($key))
            );
        }

        if (strlen($iv) !== self::IV_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('GCM IV must be %d bytes, got %d', self::IV_LENGTH, strlen($iv))
            );
        }
    }
}
