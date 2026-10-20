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

    // ============================================================
    // path()
    // ============================================================

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

        // 改变环境变量但不清缓存 → 结果保持不变
        putenv('OPENSSL_CONF=' . $this->tmpCnf);

        $this->assertSame($first, OpenSslConfig::path());
    }

    public function test_path_returns_null_when_nothing_matches(): void
    {
        // 强制标记为「已解析且无结果」，模拟兜底配置缺失的极端环境
        $resolved = new \ReflectionProperty(OpenSslConfig::class, 'resolved');
        $resolved->setAccessible(true);
        $resolved->setValue(null, null);
        $done = new \ReflectionProperty(OpenSslConfig::class, 'resolvedDone');
        $done->setAccessible(true);
        $done->setValue(null, true);

        $this->assertNull(OpenSslConfig::path());
    }

    // ============================================================
    // withConfig()
    // ============================================================

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

    public function test_cannot_be_instantiated(): void
    {
        $constructor = new \ReflectionMethod(OpenSslConfig::class, '__construct');

        $this->assertTrue($constructor->isPrivate());
    }
}
