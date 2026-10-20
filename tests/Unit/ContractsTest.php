<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\auth\GuardInterface;
use wise\support\circuit\CircuitBreakerExceptionInterface;
use wise\support\config\ConfigCheckInterface;
use wise\support\connector\ConnectorInterface;
use wise\support\contracts\EnvSeedProvider;
use wise\support\contracts\MenuItemProvider;
use wise\support\contracts\StorageDriverInterface;
use wise\support\exception\ErrorCodeInterface;
use wise\support\exception\ExceptionRendererInterface;
use wise\support\lock\LockInterface;
use wise\support\rate\RateLimitInterface;
use wise\support\ws\ChannelInterface;
use wise\support\ws\WsServerInterface;

/**
 * 框架层契约（接口）与身份提供方常量单元测试
 *
 * 这些接口本身不含可执行逻辑，测试目的是锁定契约形态：
 * 一旦方法签名/常量被误删或改名，引用方会在编译期即刻暴露。
 */
class ContractsTest extends TestCase
{
    public function test_all_contracts_are_declared(): void
    {
        $interfaces = [
            GuardInterface::class,
            LockInterface::class,
            RateLimitInterface::class,
            WsServerInterface::class,
            ChannelInterface::class,
            ConnectorInterface::class,
            ConfigCheckInterface::class,
            ErrorCodeInterface::class,
            ExceptionRendererInterface::class,
            EnvSeedProvider::class,
            MenuItemProvider::class,
            StorageDriverInterface::class,
            CircuitBreakerExceptionInterface::class,
        ];

        foreach ($interfaces as $interface) {
            $this->assertTrue(interface_exists($interface), "{$interface} 应存在");
        }
    }

    // ============================================================
    // StorageDriverInterface
    // ============================================================

    public function test_storage_driver_methods(): void
    {
        $expected = [
            'upload', 'delete', 'url', 'exists', 'size', 'mimeType', 'getDriverName',
            'copy', 'move', 'listFiles', 'makeDirectory', 'deleteDirectory',
            'temporaryUrl', 'deleteMultiple', 'lastModified', 'getMetadata',
            'setVisibility', 'getVisibility',
        ];

        $reflection = new \ReflectionClass(StorageDriverInterface::class);

        foreach ($expected as $method) {
            $this->assertTrue($reflection->hasMethod($method), "StorageDriverInterface::{$method} 应存在");
        }
        $this->assertCount(count($expected), $reflection->getMethods());
    }

    // ============================================================
    // MenuItemProvider / EnvSeedProvider
    // ============================================================

    public function test_menu_item_provider_methods(): void
    {
        $reflection = new \ReflectionClass(MenuItemProvider::class);

        foreach (['ident', 'containers', 'items'] as $method) {
            $this->assertTrue($reflection->hasMethod($method), "MenuItemProvider::{$method} 应存在");
        }
    }

    public function test_env_seed_provider_declares_static_env_seeds(): void
    {
        $method = new \ReflectionMethod(EnvSeedProvider::class, 'envSeeds');

        $this->assertTrue($method->isStatic());
        $this->assertTrue($method->isPublic());
        $this->assertSame('array', (string) $method->getReturnType());
    }

    // ============================================================
    // 其余契约方法
    // ============================================================

    /**
     * 断言接口方法集合与期望完全一致（忽略声明顺序）
     */
    private function assertMethodsEqual(array $expected, string $interface): void
    {
        $actual = array_map(
            fn(\ReflectionMethod $m) => $m->getName(),
            (new \ReflectionClass($interface))->getMethods()
        );

        $this->assertEqualsCanonicalizing($expected, $actual, "{$interface} 方法集合应一致");
    }

    public function test_guard_interface_methods(): void
    {
        $this->assertMethodsEqual(
            ['user', 'id', 'check', 'guest', 'setUser', 'getName', 'resetUser'],
            GuardInterface::class
        );
    }

    public function test_lock_interface_methods(): void
    {
        $this->assertMethodsEqual(['acquire', 'release', 'isAcquired'], LockInterface::class);
    }

    public function test_rate_limit_interface_methods(): void
    {
        $this->assertMethodsEqual(['attempt', 'status', 'reset'], RateLimitInterface::class);
    }

    public function test_channel_interface_methods(): void
    {
        $this->assertMethodsEqual(['getName', 'onMessage', 'onJoin', 'onLeave'], ChannelInterface::class);
    }

    public function test_ws_server_interface_methods(): void
    {
        $expected = ['start', 'stop', 'reload', 'send', 'close', 'count', 'isOnline', 'getFds', 'getName'];

        foreach ($expected as $method) {
            $this->assertTrue((new \ReflectionClass(WsServerInterface::class))->hasMethod($method));
        }
    }

    public function test_circuit_breaker_exception_extends_throwable(): void
    {
        $reflection = new \ReflectionClass(CircuitBreakerExceptionInterface::class);

        $this->assertTrue($reflection->implementsInterface(\Throwable::class));
        $this->assertTrue($reflection->hasMethod('getRetryAfter'));
    }

    public function test_config_check_interface_methods(): void
    {
        $this->assertMethodsEqual(['id', 'package', 'level', 'check'], ConfigCheckInterface::class);
    }

    public function test_error_code_interface_methods(): void
    {
        $this->assertTrue((new \ReflectionClass(ErrorCodeInterface::class))->hasMethod('defaultMessage'));
    }

    public function test_exception_renderer_interface_methods(): void
    {
        $method = new \ReflectionMethod(ExceptionRendererInterface::class, 'render');

        $this->assertSame('array', (string) $method->getReturnType());
        $this->assertSame(\Throwable::class, $method->getParameters()[0]->getType()->getName());
    }

    public function test_connector_interface_methods(): void
    {
        $expected = [
            'getId', 'getName', 'getType', 'getDescription', 'getActions',
            'execute', 'healthCheck', 'getConfigSchema', 'setConfig', 'getConfig',
        ];

        $reflection = new \ReflectionClass(ConnectorInterface::class);

        foreach ($expected as $method) {
            $this->assertTrue($reflection->hasMethod($method), "ConnectorInterface::{$method} 应存在");
        }
    }
}
