<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\crypto\OpenSslConfig;

/**
 * OpenSSL 配置定位器 OpenSslConfig 单元测试
 */
class OpenSslConfigTest extends TestCase
{
    private ?string $originalEnvConf = null;
    private string $tmpCnf;

    protected function setUp(): void
    {
        $this->originalEnvConf = getenv('OPENSSL_CONF') ?: null;
        $this->tmpCnf = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise_openssl_' . bin2hex(random_bytes(4)) . '.cnf';
        file_put_contents($this->tmpCnf, "# test config\n");

        $this->resetCache();
    }

    protected function tearDown(): void
    {
        if ($this->originalEnvConf === null) {
            putenv('OPENSSL_CONF');
        } else {
            putenv('OPENSSL_CONF=' . $this->originalEnvConf);
        }
        @unlink($this->tmpCnf);
        $this->resetCache();
    }

    /**
     * 清除静态缓存，使下一次 path() 重新探测候选路径
     */
    private function resetCache(): void
    {
        foreach (['resolved' => null, 'resolvedDone' => false] as $name => $value) {
            $property = new \ReflectionProperty(OpenSslConfig::class, $name);
            $property->setAccessible(true);
            $property->setValue(null, $value);
        }
    }


    public function test_path_returns_existing_file(): void
    {
        $path = OpenSslConfig::path();

        $this->assertNotNull($path, '正常环境应能定位到 openssl.cnf（含随包兜底）');
        $this->assertFileExists($path);
    }

    public function test_path_prefers_open_ssl_conf_env_variable(): void
    {
        putenv('OPENSSL_CONF=' . $this->tmpCnf);
        $this->resetCache();

        $this->assertSame($this->tmpCnf, OpenSslConfig::path());
    }

    public function test_path_ignores_env_variable_pointing_to_missing_file(): void
    {
        putenv('OPENSSL_CONF=' . $this->tmpCnf . '.missing');
        $this->resetCache();

        $path = OpenSslConfig::path();

        $this->assertNotSame($this->tmpCnf . '.missing', $path);
        if ($path !== null) {
            $this->assertFileExists($path);
        }
    }

    public function test_path_is_cached(): void
    {
        $first = OpenSslConfig::path();

        putenv('OPENSSL_CONF=' . $this->tmpCnf);

        $this->assertSame($first, OpenSslConfig::path());
    }

    public function test_path_returns_null_when_nothing_matches(): void
    {
        $resolved = new \ReflectionProperty(OpenSslConfig::class, 'resolved');
        $resolved->setAccessible(true);
        $resolved->setValue(null, null);
        $done = new \ReflectionProperty(OpenSslConfig::class, 'resolvedDone');
        $done->setAccessible(true);
        $done->setValue(null, true);

        $this->assertNull(OpenSslConfig::path());
    }


    public function test_with_config_appends_config_key(): void
    {
        $options = OpenSslConfig::withConfig(['private_key_bits' => 2048]);

        $this->assertSame(2048, $options['private_key_bits']);
        if (OpenSslConfig::path() !== null) {
            $this->assertSame(OpenSslConfig::path(), $options['config']);
        }
    }

    public function test_with_config_keeps_options_when_path_unavailable(): void
    {
        $done = new \ReflectionProperty(OpenSslConfig::class, 'resolvedDone');
        $done->setAccessible(true);
        $done->setValue(null, true);
        $resolved = new \ReflectionProperty(OpenSslConfig::class, 'resolved');
        $resolved->setAccessible(true);
        $resolved->setValue(null, null);

        $options = OpenSslConfig::withConfig(['private_key_type' => OPENSSL_KEYTYPE_RSA]);

        $this->assertSame(['private_key_type' => OPENSSL_KEYTYPE_RSA], $options);
        $this->assertArrayNotHasKey('config', $options);
    }


    /**
     * 随包 openssl.cnf 必须自成闭环：RSA(2048/4096) 与 EC(prime256v1) 都能生成并导出
     *
     * 该用例直接对随包文件做 openssl 调用，不受 path() 定位结果影响，
     * 用于防止「精简配置把 EC 写坏」这类回归。
     */
    public function test_bundled_config_supports_rsa_and_ec(): void
    {
        $bundled = dirname(__DIR__, 2) . '/resources/openssl.cnf';
        $this->assertFileExists($bundled, '随包 openssl.cnf 必须存在');

        $cases = [
            'RSA-2048' => ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            'RSA-4096' => ['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            'EC-prime256v1' => ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'],
        ];

        foreach ($cases as $label => $options) {
            $options['config'] = $bundled;

            while (openssl_error_string() !== false) {
            }

            $resource = @openssl_pkey_new($options);
            $this->assertNotFalse($resource, "随包配置应能生成 {$label} 密钥：" . openssl_error_string());

            $privateKey = '';
            $exported = openssl_pkey_export($resource, $privateKey, null, $options);
            $this->assertTrue($exported, "随包配置应能导出 {$label} 私钥");
            $this->assertStringContainsString('BEGIN PRIVATE KEY', (string) $privateKey);

            $details = openssl_pkey_get_details($resource);
            $this->assertIsArray($details);
            $this->assertStringContainsString('BEGIN PUBLIC KEY', (string) ($details['key'] ?? ''));
        }
    }

    public function test_cannot_be_instantiated(): void
    {
        $constructor = new \ReflectionMethod(OpenSslConfig::class, '__construct');

        $this->assertTrue($constructor->isPrivate());
    }
}
