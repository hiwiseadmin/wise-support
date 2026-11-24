<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use think\Console;
use wise\support\traits\RegistersMigrations;

/**
 * 迁移路径注册 Trait 单元测试
 *
 * registerMigrations() 只登记 Console::starting 回调，真正的注册逻辑
 * 在 CLI 启动时执行；测试中通过反射取出回调手动触发。
 */
class RegistersMigrationsTest extends TestCase
{
    private MigrationConfigStub $config;
    private RegistersMigrationsFixture $fixture;

    private function migrationDir(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
    }

    /**
     * 自定义迁移目录（相对包根 `tests/`）
     */
    private function customMigrationDir(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations_v2';
    }

    protected function setUp(): void
    {
        $this->config  = new MigrationConfigStub();
        $this->fixture = new RegistersMigrationsFixture((object) ['config' => $this->config]);
    }

    protected function tearDown(): void
    {
        $this->removeMigrationDir();
    }

    private function removeMigrationDir(): void
    {
        foreach ([$this->migrationDir(), $this->customMigrationDir()] as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }

        $parent = dirname($this->migrationDir());
        if (is_dir($parent)) {
            @rmdir($parent);
        }
    }

    /**
     * 取出最后注册的 Console::starting 回调并执行
     */
    private function runLastStartCallback(): void
    {
        $property = new \ReflectionProperty(Console::class, 'startCallbacks');
        $property->setAccessible(true);
        $callbacks = $property->getValue();

        $this->assertNotEmpty($callbacks, '应至少注册一个 Console::starting 回调');

        $callback = $callbacks[count($callbacks) - 1];
        $callback();
    }


    public function test_register_migrations_pushes_console_start_callback(): void
    {
        $property = new \ReflectionProperty(Console::class, 'startCallbacks');
        $property->setAccessible(true);
        $before = count($property->getValue());

        $this->fixture->register();

        $this->assertCount($before + 1, $property->getValue());
    }

    public function test_callback_skips_when_migration_dir_missing(): void
    {
        $this->removeMigrationDir();
        $this->fixture->register();

        $this->runLastStartCallback();

        $this->assertSame([], $this->config->sets);
    }

    public function test_callback_registers_migration_path(): void
    {
        @mkdir($this->migrationDir(), 0700, true);
        $this->fixture->register();

        $this->runLastStartCallback();

        $expected = $this->migrationDir();
        $this->assertCount(1, $this->config->sets);
        $this->assertSame($expected, $this->config->sets[0][0]['paths'][0]);
        $this->assertSame('migration', $this->config->sets[0][1]);
    }

    public function test_callback_does_not_duplicate_existing_path(): void
    {
        @mkdir($this->migrationDir(), 0700, true);
        $this->config->data['migration'] = ['paths' => [$this->migrationDir()]];
        $this->fixture->register();

        $this->runLastStartCallback();

        $this->assertSame([], $this->config->sets, '已注册过的迁移路径不应重复写入');
    }

    public function test_callback_appends_to_existing_paths(): void
    {
        @mkdir($this->migrationDir(), 0700, true);
        $this->config->data['migration'] = ['paths' => ['/other/migrations']];
        $this->fixture->register();

        $this->runLastStartCallback();

        $this->assertSame(
            ['/other/migrations', $this->migrationDir()],
            $this->config->sets[0][0]['paths']
        );
    }


    public function test_custom_relative_path_resolves_against_package_root(): void
    {
        @mkdir($this->customMigrationDir(), 0700, true);
        $this->fixture->register('database/migrations_v2');

        $this->runLastStartCallback();

        $this->assertSame($this->customMigrationDir(), $this->config->sets[0][0]['paths'][0]);
    }

    public function test_custom_absolute_path_is_used_as_is(): void
    {
        @mkdir($this->customMigrationDir(), 0700, true);
        $this->fixture->register($this->customMigrationDir());

        $this->runLastStartCallback();

        $this->assertSame($this->customMigrationDir(), $this->config->sets[0][0]['paths'][0]);
    }

    public function test_empty_string_falls_back_to_default_dir(): void
    {
        @mkdir($this->migrationDir(), 0700, true);
        $this->fixture->register('');

        $this->runLastStartCallback();

        $this->assertSame($this->migrationDir(), $this->config->sets[0][0]['paths'][0]);
    }

    public function test_path_is_normalized(): void
    {
        @mkdir($this->customMigrationDir(), 0700, true);
        @mkdir(dirname($this->customMigrationDir()) . DIRECTORY_SEPARATOR . 'other', 0700, true);

        $this->fixture->register('./database/other/../migrations_v2/');

        $this->runLastStartCallback();

        $this->assertSame($this->customMigrationDir(), $this->config->sets[0][0]['paths'][0]);

        @rmdir(dirname($this->customMigrationDir()) . DIRECTORY_SEPARATOR . 'other');
    }

    public function test_path_escaping_package_root_is_rejected(): void
    {
        $this->fixture->register('../../outside-migrations');

        $this->expectException(\InvalidArgumentException::class);
        $this->runLastStartCallback();
    }

    public function test_custom_path_skips_when_dir_missing(): void
    {
        $this->removeMigrationDir();
        $this->fixture->register('database/not-exists');

        $this->runLastStartCallback();

        $this->assertSame([], $this->config->sets);
    }
}


class MigrationConfigStub
{
    public array $data = [];
    /** @var array<int, array{0:array,1:string}> */
    public array $sets = [];

    /**
     * 支持 "migration.paths" 形式的点号路径读取
     */
    public function get(string $name = '', mixed $default = null): mixed
    {
        if ($name === '') {
            return $this->data;
        }

        $value = $this->data;
        foreach (explode('.', $name) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function set(array $config, string $name = ''): void
    {
        $this->sets[]      = [$config, $name];
        $this->data[$name] = $config;
    }
}

class RegistersMigrationsFixture
{
    use RegistersMigrations;

    public function __construct(public object $app)
    {
    }

    public function register(?string $path = null): void
    {
        $this->registerMigrations($path);
    }
}
