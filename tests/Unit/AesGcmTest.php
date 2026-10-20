<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\crypto\AesGcm;

/**
 * AesGcm AES-256-GCM 加解密单元测试
 */
class AesGcmTest extends TestCase
{
    private const PLAINTEXT = 'Hello, WiseAdmin! 你好世界';

    public function test_encrypt_decrypt_roundtrip(): void
    {
        $gcm = new AesGcm();
        $key = $gcm->generateKey();
        $iv = $gcm->generateIv();

        $enc = $gcm->encrypt(self::PLAINTEXT, $key, $iv);
        $dec = $gcm->decrypt($enc['ciphertext'], $key, $iv, $enc['tag']);

        $this->assertEquals(self::PLAINTEXT, $dec);
    }

    public function test_encrypt_decrypt_with_aad(): void
    {
        $gcm = new AesGcm();
        $key = $gcm->generateKey();
        $iv = $gcm->generateIv();
        $aad = 'context-12345';

        $enc = $gcm->encrypt(self::PLAINTEXT, $key, $iv, $aad);
        $dec = $gcm->decrypt($enc['ciphertext'], $key, $iv, $enc['tag'], $aad);

        $this->assertEquals(self::PLAINTEXT, $dec);
    }

    public function test_wrong_aad_fails(): void
    {
        $gcm = new AesGcm();
        $key = $gcm->generateKey();
        $iv = $gcm->generateIv();

        $enc = $gcm->encrypt(self::PLAINTEXT, $key, $iv, 'right-aad');

        $this->expectException(\RuntimeException::class);
        $gcm->decrypt($enc['ciphertext'], $key, $iv, $enc['tag'], 'wrong-aad');
    }

    public function test_wrong_key_fails(): void
    {
        $gcm = new AesGcm();
        $key1 = $gcm->generateKey();
        $key2 = $gcm->generateKey();
        $iv = $gcm->generateIv();

        $enc = $gcm->encrypt(self::PLAINTEXT, $key1, $iv);

        $this->expectException(\RuntimeException::class);
        $gcm->decrypt($enc['ciphertext'], $key2, $iv, $enc['tag']);
    }

    public function test_wrong_tag_fails(): void
    {
        $gcm = new AesGcm();
        $key = $gcm->generateKey();
        $iv = $gcm->generateIv();

        $enc = $gcm->encrypt(self::PLAINTEXT, $key, $iv);
        $enc['tag'][0] = chr(ord($enc['tag'][0]) ^ 0xFF); // flip bits

        $this->expectException(\RuntimeException::class);
        $gcm->decrypt($enc['ciphertext'], $key, $iv, $enc['tag']);
    }

    public function test_generate_key_length(): void
    {
        $gcm = new AesGcm();
        $key = $gcm->generateKey();

        $this->assertEquals(32, strlen($key));
    }

    public function test_generate_iv_length(): void
    {
        $gcm = new AesGcm();
        $iv = $gcm->generateIv();

        $this->assertEquals(12, strlen($iv));
    }

    public function test_generated_keys_are_random(): void
    {
        $gcm = new AesGcm();

        $key1 = $gcm->generateKey();
        $key2 = $gcm->generateKey();

        $this->assertNotEquals($key1, $key2);
    }

    public function test_generated_ivs_are_random(): void
    {
        $gcm = new AesGcm();

        $iv1 = $gcm->generateIv();
        $iv2 = $gcm->generateIv();

        $this->assertNotEquals($iv1, $iv2);
    }

    public function test_invalid_key_length_throws(): void
    {
        $gcm = new AesGcm();

        $this->expectException(\InvalidArgumentException::class);
        $gcm->encrypt('test', 'short-key', $gcm->generateIv());
    }

    public function test_invalid_iv_length_throws(): void
    {
        $gcm = new AesGcm();

        $this->expectException(\InvalidArgumentException::class);
        $gcm->encrypt('test', $gcm->generateKey(), 'short-iv');
    }

    public function test_invalid_tag_length_throws_on_decrypt(): void
    {
        $gcm = new AesGcm();

        $this->expectException(\InvalidArgumentException::class);
        $gcm->decrypt('cipher', $gcm->generateKey(), $gcm->generateIv(), 'short-tag');
    }

    public function test_encrypt_binary_data(): void
    {
        $gcm = new AesGcm();
        $key = $gcm->generateKey();
        $iv = $gcm->generateIv();

        $binary = random_bytes(1024);
        $enc = $gcm->encrypt($binary, $key, $iv);
        $dec = $gcm->decrypt($enc['ciphertext'], $key, $iv, $enc['tag']);

        $this->assertEquals($binary, $dec);
    }

    public function test_encrypt_empty_string(): void
    {
        $gcm = new AesGcm();
        $key = $gcm->generateKey();
        $iv = $gcm->generateIv();

        $enc = $gcm->encrypt('', $key, $iv);
        $dec = $gcm->decrypt($enc['ciphertext'], $key, $iv, $enc['tag']);

        $this->assertEquals('', $dec);
    }
}
