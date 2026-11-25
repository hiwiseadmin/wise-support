<?php
declare(strict_types=1);

namespace wise\support;

use think\Service;
use wise\support\config\ConfigCheckCollector;
use wise\support\context\RequestStateRegistry;
use wise\support\runtime\Runtime;
use wise\support\auth\AuthManager;
use wise\support\jwt\JwtManager;
use wise\support\ws\ConnectionManager;
use wise\support\ws\MessageRouter;
use wise\support\ws\handler\PingPongHeartbeat;

/**
 * Wise-Support 服务提供者
 *
 * 注册 wise-support 通用工具和基础设施的服务绑定。
 */
class SupportServiceProvider extends Service
{
    /**
     * 注册服务
     */
    public function register(): void
    {
        $this->app->bind(ConfigCheckCollector::class, function () {
            return new ConfigCheckCollector();
        });

        $this->app->bind(AuthManager::class, function () {
            return new AuthManager();
        });

        $this->app->bind('auth', function () {
            return $this->app->make(AuthManager::class);
        });

        $this->app->bind('jwt', function () {
            return new JwtManager();
        });

        $this->app->bind(ConnectionManager::class, function () {
            return new ConnectionManager();
        });

        $this->app->bind(MessageRouter::class, function () {
            $router = new MessageRouter();
            $router->setConnectionManager($this->app->make(ConnectionManager::class));
            return $router;
        });

        $this->registerExtensionPoints();
    }

    /**
     * 启动服务
     */
    public function boot(): void
    {
        $this->registerDefaultImplementations();

        $this->registerRequestStateResetters();

        $this->app->event->listen(\think\event\HttpEnd::class, static function (): void {
            if (Runtime::inCoroutine()) {
                return;
            }

            RequestStateRegistry::runAll();
        });
    }

    /**
     * 注册本包的请求级状态重置器
     *
     * 只在 boot（worker 级）执行一次。
     * 约定：一个包一个重置器类（粒度是「包」而非「状态项」），
     * 本包的 Once / ExtPoint / RequestContext 三处状态统一由
     * {@see \wise\support\context\SupportStateResetter} 清理。
     *
     * 优先级 100：基础设施层，必须早于依赖上下文的业务包（权限 150 / 配置 200）。
     */
    protected function registerRequestStateResetters(): void
    {
        RequestStateRegistry::register('wise-support', context\SupportStateResetter::class, 100);
    }

    /**
     * 注册 wise-support 内置扩展点定义
     *
     * 声明原则（与 ExtPoint::DYNAMIC_PREFIXES 的设计对应）：
     *
     *  1. 静态扩展点 —— 名称在编码期即可穷举（如 'connector'、'captcha.driver'），
     *     必须在此声明。声明后才具备：明确的调度策略、契约（contract）校验、
     *     以及在能力市场被启用/停用的能力（Capability::toggle() 会校验
     *     ExtPoint::defined()）。未声明就 register() 的扩展点，其策略会静默
     *     回落到 ExtPoint::getPolicy() 的默认值 multi，并写一条 error_log。
     *
     *  2. 动态扩展点 —— 名称由运行时输入拼接而成、无法穷举，因此不在此声明：
     *       bus.handler.{action}   action 取自 ServiceBus 消息
     *       ws.handler.{action}    action 取自 WebSocket 消息体的 action 字段
     *       slot.*                 插槽名由 SlotManager::define() 运行时注册
     *     它们由 ExtPoint 的 DYNAMIC_PREFIXES 豁免「未定义即注册」告警，
     *     且全命名空间共享同一策略（multi）、无统一契约，预先声明不增加任何信息。
     *
     *  3. 已由所属包自行声明的扩展点，此处不重复声明，避免契约被覆盖：
     *       image.driver              → wise\image（wise-attachment 包内）
     *
     *     说明：支付 / 短信 / 存储 / OAuth / 通知 / 身份等「外部系统交互」能力已统一
     *     迁入连接器体系（ExtPoints::EXTERNAL_CONNECTOR），不再各自声明扩展点。
     *
     *  4. BUS_HANDLER_PREFIX('bus.handler') 是命名空间前缀而不是扩展点本身，
     *     实际扩展点为运行时拼接的 'bus.handler.{action}'，故不在此声明。
     */
    protected function registerExtensionPoints(): void
    {
        ExtPoint::definePoint(
            ExtPoints::AUTH_GUARD,
            ExtPoint::POLICY_ROUTED,
            \wise\support\auth\GuardInterface::class,
        );

        ExtPoint::definePoint(
            ExtPoints::EXTERNAL_CONNECTOR,
            ExtPoint::POLICY_ROUTED,
            \wise\support\connector\ConnectorInterface::class,
        );


        ExtPoint::definePoint(
            ExtPoints::JWT_PROVIDER,
            ExtPoint::POLICY_SINGLE,
        );

        ExtPoint::definePoint(ExtPoints::DASHBOARD_WIDGET, ExtPoint::POLICY_MULTI);
        ExtPoint::definePoint(ExtPoints::MENU_ITEM, ExtPoint::POLICY_MULTI);
        ExtPoint::definePoint(ExtPoints::ADMIN_FRONTEND_MODULE, ExtPoint::POLICY_MULTI);
        ExtPoint::definePoint(ExtPoints::SEARCH_PROVIDER, ExtPoint::POLICY_MULTI);

        ExtPoint::definePoint(ExtPoints::SLOT_CONTENT, ExtPoint::POLICY_MULTI);

        ExtPoint::definePoint(
            ExtPoints::WS_HANDLER,
            ExtPoint::POLICY_MULTI,
        );

        ExtPoint::definePoint(
            ExtPoints::WS_HEARTBEAT,
            ExtPoint::POLICY_MULTI,
        );

        foreach (ExtPoints::ROUTE_POINTS as $point) {
            ExtPoint::definePoint($point, ExtPoint::POLICY_MULTI);
        }
    }

    /**
     * 注册 wise-support 内置的实现（Phase 4: 包内自注册）
     */
    protected function registerDefaultImplementations(): void
    {
        ExtPoint::register(ExtPoints::WS_HEARTBEAT, 'ping_pong', PingPongHeartbeat::class);
    }
}
