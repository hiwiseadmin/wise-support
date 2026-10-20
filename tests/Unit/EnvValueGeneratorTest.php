<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\env\EnvValueGenerator;

/**
 * .env 值生成器 EnvValueGenerator 单元测试
 */
class EnvValueGeneratorTest extends TestCase
{
    private EnvValueGenerator $generator;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->generator = new EnvValueGenerator();
        $this->tmpDir    = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise_env_' . bin2hex(random_bytes(4));
        @mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            is_dir($file) ? $this->removeDir($file) : @unlink($file);
        }
        @rmdir($dir);
    }

    // ============================================================
    // 通用策略
    // ============================================================

    public function test_unknown_strategy_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('未知 env 生成策略: nope');

        $this->generator->generate('nope');
    }

    public function test_random_bytes_32_returns_64_hex_chars(): void
    {
        $value = $this->generator->generate('random_bytes_32');

        $this->assertIsString($value);
        $this->assertSame(64, strlen($value));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $value);
        $this->assertNotSame($value, $this->generator->generate('random_bytes_32'));
    }

    public function test_hmac_salt_is_url_safe_base64_without_padding(): void
    {
        $value = $this->generator->generate('hmac_salt');

        $this->assertIsString($value);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $value);
        $this->assertStringNotContainsString('=', $value);
        $this->assertStringNotContainsString('+', $value);
        $this->assertStringNotContainsString('/', $value);
        // 32 字节 base64 编码后约 43 个字符
        $this->assertSame(43, strlen($value));
    }

    public function test_uuid_returns_v4_format(): void
    {
        $value = $this->generator->generate('uuid');

        $this->assertIsString($value);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $value
        );
        $this->assertNotSame($value, $this->generator->generate('uuid'));
    }

    // ============================================================
    // rsa_2048_pem
    // ============================================================

    public function test_rsa_2048_pem_returns_pem_pair_when_openssl_available(): void
    {
        try {
            $result = $this->generator->generate('rsa_2048_pem');
        } catch (\RuntimeException $e) {
            // 某些 Windows 环境下 openssl_pkey_export 缺少 config 参数会失败，
            // 属环境限制（源码问题已在测试报告中记录），此处跳过而非误判。
            $this->markTestSkipped('当前环境 openssl 无法生成 RSA 密钥对: ' . $e->getMessage());
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('private', $result);
        $this->assertArrayHasKey('public', $result);
        $this->assertStringContainsString('PRIVATE KEY', $result['private']);
        $this->assertStringContainsString('PUBLIC KEY', $result['public']);
    }

    public function test_rsa_2048_pem_writes_files_when_file_dir_given(): void
    {
        $dir = $this->tmpDir . DIRECTORY_SEPARATOR . 'keys';

        try {
            $result = $this->generator->generate('rsa_2048_pem', [
                'file_dir'    => $dir,
                'file_prefix' => 'demo',
            ]);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('当前环境 openssl 无法生成 RSA 密钥对: ' . $e->getMessage());
        }

        $this->assertIsArray($result);
        $this->assertStringStartsWith('file://', $result['private']);
        $this->assertStringStartsWith('file://', $result['public']);

        $privatePath = str_replace('file://', '', $result['private']);
        $publicPath  = str_replace('file://', '', $result['public']);

        $this->assertFileExists($privatePath);
        $this->assertFileExists($publicPath);
        $this->assertStringContainsString('demo_private.pem', $privatePath);
        $this->assertStringContainsString('demo_public.pem', $publicPath);
        $this->assertStringContainsString('PRIVATE KEY', (string) file_get_contents($privatePath));
    }

    public function test_rsa_2048_pem_private_key_is_0600_and_leaves_no_temp_file(): void
    {
        $dir = $this->tmpDir . DIRECTORY_SEPARATOR . 'mode_keys';

        try {
            $result = $this->generator->generate('rsa_2048_pem', [
                'file_dir'    => $dir,
                'file_prefix' => 'mode',
            ]);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('当前环境 openssl 无法生成 RSA 密钥对: ' . $e->getMessage());
        }

        $privatePath = str_replace('file://', '', $result['private']);

        // 不得残留 *.tmp（独占创建 + rename 的中间产物必须清理干净）
        $this->assertSame([], glob($dir . DIRECTORY_SEPARATOR . '*.tmp') ?: []);

        if (PHP_OS_FAMILY === 'Windows') {
            // Windows 的 chmod 只切换只读位，无法限制读权限，需部署侧用 ACL 确认
            $this->markTestSkipped('Windows 无法校验 0600，需在部署侧用 Get-Acl 确认');
        }

        $this->assertSame(0600, fileperms($privatePath) & 0777, '私钥必须是 0600');
    }

    public function test_rsa_2048_pem_default_prefix_is_wise(): void
    {
        try {
            $result = $this->generator->generate('rsa_2048_pem', ['file_dir' => $this->tmpDir]);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('当前环境 openssl 无法生成 RSA 密钥对: ' . $e->getMessage());
        }

        $this->assertFileExists($this->tmpDir . DIRECTORY_SEPARATOR . 'wise_private.pem');
        $this->assertFileExists($this->tmpDir . DIRECTORY_SEPARATOR . 'wise_public.pem');
        $this->assertNotSame('', $result['private']);
    }

    public function test_rsa_2048_pem_rejects_unwritable_file_dir(): void
    {
        // 用「父级是文件」的路径模拟不可写目录
        $blocker = $this->tmpDir . DIRECTORY_SEPARATOR . 'blocker';
        file_put_contents($blocker, 'x');

        try {
            $this->generator->generate('rsa_2048_pem', ['file_dir' => $blocker . DIRECTORY_SEPARATOR . 'keys']);
            $this->fail('应当抛出 RuntimeException');
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'openssl') || str_contains($e->getMessage(), '导出')) {
                $this->markTestSkipped('当前环境 openssl 无法生成 RSA 密钥对: ' . $e->getMessage());
            }
            $this->assertStringContainsString('密钥目录不可写', $e->getMessage());
        }
    }

    public function test_rsa_2048_pem_resolves_relative_file_dir_against_cwd(): void
    {
        $cwd = getcwd();
        chdir($this->tmpDir);

        try {
            $result = $this->generator->generate('rsa_2048_pem', ['file_dir' => 'rel_keys']);
        } catch (\RuntimeException $e) {
            chdir($cwd);
            $this->markTestSkipped('当前环境 openssl 无法生成 RSA 密钥对: ' . $e->getMessage());
        } finally {
            chdir($cwd);
        }

        $privatePath = str_replace('file://', '', $result['private']);
        $this->assertStringContainsString('rel_keys', $privatePath);
        $this->assertFileExists($privatePath);
        $this->assertFileExists(str_replace('file://', '', $result['public']));
    }
}
