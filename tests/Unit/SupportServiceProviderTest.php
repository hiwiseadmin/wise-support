<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\SupportServiceProvider;
use wise\support\auth\AuthManager;
use wise\support\auth\GuardInterface;
use wise\support\config\ConfigCheckCollector;
use wise\support\connector\ConnectorInterface;
use wise\support\contracts\StorageDriverInterface;
use wise\support\jwt\JwtManager;
use wise\support\ws\ConnectionManager;
use wise\support\ws\MessageRouter;
use wise\support\ws\handler\PingPongHeartbeat;

/**
 * 服务提供者 SupportServiceProvider 单元测试
 */
class SupportServiceProviderTest extends TestCase
{
    private \think\App $app;

    protected function setUp(): void
    {
        ExtPoint::reset();

        $this->app = new \think\App(dirname(__DIR__, 5));
        (new SupportServiceProvider($this->app))->register();
    }

    protected function tearDown(): void
    {
        ExtPoint::reset();
    }

    // ============================================================
    // 容器绑定
    // ============================================================

    public function test_register_binds_expected_services(): void
    {
        foreach ([
            ConfigCheckCollector::class,
            AuthManager::class,
            'auth',
            'jwt',
            ConnectionManager::class,
            MessageRouter::class,
        ] as $abstract) {
            $this->assertTrue($this->app->bound($abstract), "{$abstract} 应被绑定");
        }
    }

    public function test_services_are_singletons(): void
    {
        $this->assertSame(
            $this->app->make(AuthManager::class),
            $this->app->make(AuthManager::class)
        );
        $this->assertSame(
            $this->app->make(ConnectionManager::class),
            $this->app->make(ConnectionManager::class)
        );
        $this->assertSame(
            $this->app->make(ConfigCheckCollector::class),
            $this->app->make(ConfigCheckCollector::class)
        );
        $this->assertSame(
            $this->app->make(MessageRouter::class),
            $this->app->make(MessageRouter::class)
        );
    }

    public function test_auth_alias_resolves_to_same_manager(): void
    {
        $this->assertSame(
            $this->app->make(AuthManager::class),
            $this->app->make('auth')
        );
    }

    public function test_jwt_alias_resolves_to_jwt_manager(): void
    {
        $this->assertInstanceOf(JwtManager::class, $this->app->make('jwt'));
    }

    public function test_message_router_gets_connection_manager_injected(): void
    {
        $router  = $this->app->make(MessageRouter::class);
        $manager = $this->app->make(ConnectionManager::class);

        $property = new \ReflectionProperty($router, 'connectionManager');
        $property->setAccessible(true);

        $this->assertSame($manager, $property->getValue($router));
    }

    // ============================================================
    // 扩展点声明
    // ============================================================

    public function test_routed_points_are_declared_with_contracts(): void
    {
        $this->assertTrue(ExtPoint::defined(ExtPoints::AUTH_GUARD));
        $this->assertSame(ExtPoint::POLICY_ROUTED, ExtPoint::getPolicy(ExtPoints::AUTH_GUARD));
        $this->assertSame(GuardInterface::class, ExtPoint::getDefinition(ExtPoints::AUTH_GUARD)['contract']);

        $this->assertSame(ExtPoint::POLICY_ROUTED, ExtPoint::getPolicy(ExtPoints::EXTERNAL_CONNECTOR));
        $this->assertSame(
            ConnectorInterface::class,
            ExtPoint::getDefinition(ExtPoints::EXTERNAL_CONNECTOR)['contract']
        );
    }

    public function test_single_points_are_declared(): void
    {
        foreach ([
            ExtPoints::JWT_PROVIDER,
        ] as $point) {
            $this->assertTrue(ExtPoint::defined($point), "{$point} 应被声明");
            $this->assertSame(ExtPoint::POLICY_SINGLE, ExtPoint::getPolicy($point), $point);
        }
    }

    public function test_multi_points_are_declared(): void
    {
        foreach ([
            ExtPoints::DASHBOARD_WIDGET,
            ExtPoints::MENU_ITEM,
            ExtPoints::SEARCH_PROVIDER,
            ExtPoints::SLOT_CONTENT,
            ExtPoints::WS_HANDLER,
            ExtPoints::WS_HEARTBEAT,
        ] as $point) {
            $this->assertTrue(ExtPoint::defined($point), "{$point} 应被声明");
            $this->assertSame(ExtPoint::POLICY_MULTI, ExtPoint::getPolicy($point), $point);
        }
    }

    public function test_route_points_are_declared_as_multi(): void
    {
        foreach (ExtPoints::ROUTE_POINTS as $app => $point) {
            $this->assertTrue(ExtPoint::defined($point), "{$app} 路由扩展点应被声明");
            $this->assertSame(ExtPoint::POLICY_MULTI, ExtPoint::getPolicy($point), $point);
        }
    }

    public function test_route_points_have_no_contract_to_allow_closures(): void
    {
        foreach (ExtPoints::ROUTE_POINTS as $point) {
            $this->assertNull(ExtPoint::getDefinition($point)['contract'], $point);
        }
    }

    // ============================================================
    // 内置实现注册（boot）
    // ============================================================

    public function test_boot_registers_builtin_implementations(): void
    {
        (new SupportServiceProvider($this->app))->boot();

        $this->assertTrue(ExtPoint::has(ExtPoints::WS_HEARTBEAT, 'ping_pong'));
        $this->assertSame(
            PingPongHeartbeat::class,
            ExtPoint::get(ExtPoints::WS_HEARTBEAT, 'ping_pong')
        );
    }

    public function test_boot_does_not_register_undefined_points(): void
    {
        (new SupportServiceProvider($this->app))->boot();

        // 动态扩展点（bus.handler.* / ws.handler.*）不应在启动期被预先声明
        $this->assertFalse(ExtPoint::defined('bus.handler.order.created'));
        $this->assertFalse(ExtPoint::defined('ws.handler.game.frame'));
    }
}
