<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use think\Container;
use wise\support\traits\ServiceTrait;

/**
 * 包 Service 配置合并 Trait 单元测试
 */
class ServiceTraitTest extends TestCase
{
    private TraitConfigStub $config;
    private string $configDir;
    private ServiceTraitFixture $fixture;

    protected function setUp(): void
    {
        $this->config    = new TraitConfigStub();
        $this->configDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise_cfg_' . bin2hex(random_bytes(4));
        @mkdir($this->configDir, 0700, true);

        Container::getInstance()->bind('config', $this->config);

        $this->fixture = new ServiceTraitFixture();
        $this->fixture->configDir = $this->configDir;
    }

    protected function tearDown(): void
    {
        Container::getInstance()->delete('config');
        foreach (glob($this->configDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->configDir);
    }

    private function writeConfig(string $name, string $content): void
    {
        file_put_contents($this->configDir . DIRECTORY_SEPARATOR . $name . '.php', $content);
    }

    // ============================================================
    // mergeConfig()
    // ============================================================

    public function test_merge_config_returns_empty_when_package_config_missing(): void
    {
        $this->assertSame([], $this->fixture->callMergeConfig('missing-pkg'));
        $this->assertSame([], $this->config->sets);
    }

    public function test_merge_config_merges_host_over_package_defaults(): void
    {
        $this->writeConfig('wise-x', '<?php return ["a" => 1, "nested" => ["x" => 1, "y" => 2], "list" => [1, 2]];');
        $this->config->data['wise-x'] = ['a' => 2, 'nested' => ['y' => 3], 'list' => [9]];

        $merged = $this->fixture->callMergeConfig('wise-x');

        $this->assertSame(2, $merged['a']);
        $this->assertSame(['x' => 1, 'y' => 3], $merged['nested']);
        // 列表整体替换：宿主 [9] 直接取代默认 [1, 2]，不残留尾部元素
        $this->assertSame([9], $merged['list']);
        $this->assertSame([[$merged, 'wise-x']], $this->config->sets);
    }

    public function test_merge_config_without_host_config_keeps_defaults(): void
    {
        $this->writeConfig('wise-y', '<?php return ["only" => "default"];');

        $merged = $this->fixture->callMergeConfig('wise-y');

        $this->assertSame(['only' => 'default'], $merged);
        $this->assertSame([['only' => 'default'], 'wise-y'], $this->config->sets[0]);
    }

    // ============================================================
    // loadPackageDefaultConfig()
    // ============================================================

    public function test_load_config_rejects_illegal_names(): void
    {
        foreach (['../evil', 'a..b', 'bad/name', 'bad name', 'bad;name'] as $name) {
            try {
                $this->fixture->callLoadConfig($name);
                $this->fail('非法配置名应抛出 InvalidArgumentException: ' . $name);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('非法的配置名', $e->getMessage());
            }
        }
    }

    public function test_load_config_accepts_dots_and_dashes(): void
    {
        $this->writeConfig('wise-game.core-v2', '<?php return ["ok" => true];');

        $this->assertSame(['ok' => true], $this->fixture->callLoadConfig('wise-game.core-v2'));
    }

    public function test_load_config_returns_null_for_non_array(): void
    {
        $this->writeConfig('scalar', '<?php return "not-an-array";');

        $this->assertNull($this->fixture->callLoadConfig('scalar'));
    }

    public function test_load_config_wraps_require_failure(): void
    {
        $this->writeConfig('boom', '<?php throw new \RuntimeException("config boom");');

        try {
            $this->fixture->callLoadConfig('boom');
            $this->fail('配置加载失败应抛出 RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to load package config "boom"', $e->getMessage());
            $this->assertStringContainsString('config boom', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    // ============================================================
    // arrayMergeRecursive()
    // ============================================================

    public function test_array_merge_recursive_replaces_list_wholesale(): void
    {
        $result = $this->fixture->callMergeRecursive(
            ['list' => [1, 2, 3], 'keep' => 'a'],
            ['list' => [9]]
        );

        // 列表整体替换：既不追加也不残留尾部元素
        $this->assertSame([9], $result['list']);
        $this->assertSame('a', $result['keep']);
    }

    public function test_array_merge_recursive_lets_host_clear_package_list(): void
    {
        $result = $this->fixture->callMergeRecursive(
            ['middleware' => [['WebMw'], ['AuthMw']]],
            ['middleware' => []]
        );

        // 宿主可用空列表显式清空包默认中间件（旧实现下会保留包默认值）
        $this->assertSame([], $result['middleware']);
    }

    public function test_array_merge_recursive_replaces_nested_list(): void
    {
        $result = $this->fixture->callMergeRecursive(
            ['groups' => ['auth' => ['middleware' => ['a', 'b']]]],
            ['groups' => ['auth' => ['middleware' => ['c']]]]
        );

        $this->assertSame(['c'], $result['groups']['auth']['middleware']);
    }

    public function test_array_merge_recursive_deep_merges_maps(): void
    {
        $result = $this->fixture->callMergeRecursive(
            ['a' => ['b' => ['c' => 1, 'd' => 2]]],
            ['a' => ['b' => ['d' => 3, 'e' => 4]]]
        );

        $this->assertSame(['c' => 1, 'd' => 3, 'e' => 4], $result['a']['b']);
    }

    public function test_array_merge_recursive_overwrites_non_array_with_array(): void
    {
        $result = $this->fixture->callMergeRecursive(['k' => 'scalar'], ['k' => ['nested' => 1]]);

        $this->assertSame(['nested' => 1], $result['k']);
    }

    public function test_array_merge_recursive_overwrites_array_with_non_array(): void
    {
        $result = $this->fixture->callMergeRecursive(['k' => ['nested' => 1]], ['k' => 'scalar']);

        $this->assertSame('scalar', $result['k']);
    }

    // ============================================================
    // getPackageConfigFilePath() 默认实现
    // ============================================================

    public function test_default_config_path_points_to_package_config_dir(): void
    {
        $path = (new ServiceTraitDefaultPathFixture())->callGetPath('wise-x');

        $this->assertStringEndsWith(
            DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'wise-x.php',
            $path
        );
    }
}

// ============================================================
// 测试替身与夹具
// ============================================================

class TraitConfigStub
{
    public array $data = [];
    /** @var array<int, array{0:array,1:string}> */
    public array $sets = [];

    public function get(string $name = '', mixed $default = null): mixed
    {
        return $this->data[$name] ?? $default;
    }

    public function set(array $config, string $name = ''): void
    {
        $this->sets[]        = [$config, $name];
        $this->data[$name]   = $config;
    }

    public function has(string $name): bool
    {
        return isset($this->data[$name]);
    }
}

class ServiceTraitFixture
{
    use ServiceTrait;

    public string $configDir = '';

    protected function getPackageConfigFilePath(string $configName): string
    {
        return $this->configDir . DIRECTORY_SEPARATOR . $configName . '.php';
    }

    public function callMergeConfig(string $configName): array
    {
        return $this->mergeConfig($configName);
    }

    public function callLoadConfig(string $configName): ?array
    {
        return $this->loadPackageDefaultConfig($configName);
    }

    public function callMergeRecursive(array $base, array $override): array
    {
        return $this->arrayMergeRecursive($base, $override);
    }
}

class ServiceTraitDefaultPathFixture
{
    use ServiceTrait;

    public function callGetPath(string $configName): string
    {
        return $this->getPackageConfigFilePath($configName);
    }
}
