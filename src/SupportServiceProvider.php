<?php
declare(strict_types=1);

namespace wise\support;

use think\Service;
use wise\support\config\ConfigCheckCollector;
use wise\support\context\RequestContext;
use wise\support\auth\AuthManager;
use wise\support\jwt\JwtManager;
use wise\support\ws\ConnectionManager;
use wise\support\ws\MessageRouter;
use wise\support\ws\handler\PingPongHeartbeat;
use wise\support\exception\DefaultExceptionRenderer;

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
        // ConfigCheckCollector 单例：各包通过它注册配置检查项
        $this->app->bind(ConfigCheckCollector::class, function () {
            return new ConfigCheckCollector();
        });

        // AuthManager 单例
        // 所有 wise-* 包共享同一个 AuthManager，确保 Guard 缓存一致
        $this->app->bind(AuthManager::class, function () {
            return new AuthManager();
        });

        // 将 AuthManager 也绑定到 'auth' 别名（不走 Facade 时直接容器获取）
        $this->app->bind('auth', function () {
            return $this->app->make(AuthManager::class);
        });

        // JwtManager — JWT 引擎代理
        // 替代直接 app('jwt') 绑定，统一通过 ExtPoint('jwt.provider') 发现引擎
        // JWT Facade、TokenGuard 及其他组件均通过此代理获取 JWT 引擎
        $this->app->bind('jwt', function () {
            return new JwtManager();
        });

        // ConnectionManager 单例
        // 全局唯一的 WebSocket 连接池，管理所有连接
        $this->app->bind(ConnectionManager::class, function () {
            return new ConnectionManager();
        });

        // MessageRouter 单例
        // 全局唯一的消息路由器
        $this->app->bind(MessageRouter::class, function () {
            $router = new MessageRouter();
            $router->setConnectionManager($this->app->make(ConnectionManager::class));
            return $router;
        });

        // 注册标准扩展点（契约声明）
        $this->registerExtensionPoints();
    }

    /**
     * 启动服务
     */
    public function boot(): void
    {
        // 注册 wise-support 内置实现
        $this->registerDefaultImplementations();
    }

    /**
     * 注册 wise-support 内置扩展点定义
     *
     * 声明原则（与 ExtPoint::DYNAMIC_PREFIXES 的设计对应）：
     *
     *  1. 静态扩展点 —— 名称在编码期即可穷举（如 'payment.driver'、'connector'），
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
     *       image.driver              → wise-image
     *       captcha.driver            → wise-login-security
     *       oauth.driver              → wise-oauth
     *       notification.channel      → wise-notification
     *       workflow.pipe             → wise-workflow
     *       anti_addiction.identity   → wise-wx
     *
     *  4. BUS_HANDLER_PREFIX('bus.handler') 是命名空间前缀而不是扩展点本身，
     *     实际扩展点为运行时拼接的 'bus.handler.{action}'，故不在此声明。
     */
    protected function registerExtensionPoints(): void
    {
        // ── ROUTED 型扩展点：按名称精确查找 ──
        ExtPoint::definePoint(
            ExtPoints::AUTH_GUARD,
            ExtPoint::POLICY_ROUTED,
            \wise\support\auth\GuardInterface::class,
        );

        // connector: 契约由 wise-support 自身定义（脚手架生成的连接器已实现该接口）
        // 注意：绑定契约后，register() 会对字符串类名做 is_subclass_of 校验，
        // 不合规的连接器将在启动阶段抛 InvalidArgumentException（此前为静默通过）。
        ExtPoint::definePoint(
            ExtPoints::EXTERNAL_CONNECTOR,
            ExtPoint::POLICY_ROUTED,
            \wise\support\connector\ConnectorInterface::class,
        );

        // 登录安全用户解析器（当前项目改用容器绑定 wise_login_security.user_resolver）
        ExtPoint::definePoint(ExtPoints::LOGIN_USER_RESOLVER, ExtPoint::POLICY_ROUTED);

        // ── SINGLE 型扩展点：同一时刻只有一个活跃实现 ──
        ExtPoint::definePoint(ExtPoints::PAYMENT_DRIVER, ExtPoint::POLICY_SINGLE);
        ExtPoint::definePoint(ExtPoints::SMS_PROVIDER, ExtPoint::POLICY_SINGLE);

        // storage.driver 此前从未声明，wise-attachment 在 boot() 中直接 register()，
        // 导致策略回落为 multi（5 个驱动全部 active）。此处提前在 register() 阶段声明，
        // 保证其 boot() 执行时策略已生效。
        //
        // 契约 StorageDriverInterface 属框架层（wise\support\contracts），
        // 第三方实现方无需依赖 wise-attachment 即可提供存储驱动。
        ExtPoint::definePoint(
            ExtPoints::STORAGE_DRIVER,
            ExtPoint::POLICY_SINGLE,
            \wise\support\contracts\StorageDriverInterface::class,
        );

        ExtPoint::definePoint(ExtPoints::CACHE_DRIVER, ExtPoint::POLICY_SINGLE);
        ExtPoint::definePoint(ExtPoints::QUEUE_DRIVER, ExtPoint::POLICY_SINGLE);

        ExtPoint::definePoint(
            ExtPoints::JWT_PROVIDER,
            ExtPoint::POLICY_SINGLE,
        );

        ExtPoint::definePoint(
            ExtPoints::WS_SERVER,
            ExtPoint::POLICY_SINGLE,
        );

        ExtPoint::definePoint(
            ExtPoints::EXCEPTION_RENDERER,
            ExtPoint::POLICY_SINGLE,
            \wise\support\exception\ExceptionRendererInterface::class,
        );

        ExtPoint::definePoint(
            ExtPoints::LOG_DRIVER,
            ExtPoint::POLICY_SINGLE,
        );

        // 会员身份解析器：同一时刻只有一个活跃实现（宿主注入）。
        // 契约由 wise-member 包定义（wise\member\auth\contracts\MemberIdentityResolverInterface），
        // 此处不声明 contract，避免 support 层反向依赖 member 包。
        ExtPoint::definePoint(ExtPoints::MEMBER_IDENTITY_RESOLVER, ExtPoint::POLICY_SINGLE);

        // ── MULTI 型扩展点：所有实现并行存在 ──
        ExtPoint::definePoint(ExtPoints::DASHBOARD_WIDGET, ExtPoint::POLICY_MULTI);
        ExtPoint::definePoint(ExtPoints::MENU_ITEM, ExtPoint::POLICY_MULTI);
        ExtPoint::definePoint(ExtPoints::ADMIN_FRONTEND_MODULE, ExtPoint::POLICY_MULTI);
        ExtPoint::definePoint(ExtPoints::SEARCH_PROVIDER, ExtPoint::POLICY_MULTI);
        ExtPoint::definePoint(ExtPoints::EDITOR_DRIVER, ExtPoint::POLICY_MULTI);
        ExtPoint::definePoint(ExtPoints::EDITOR_PLUGIN, ExtPoint::POLICY_MULTI);
        ExtPoint::definePoint(ExtPoints::FORM_FIELD, ExtPoint::POLICY_MULTI);
        ExtPoint::definePoint(ExtPoints::TABLE_COLUMN, ExtPoint::POLICY_MULTI);

        // slot.definition / slot.content 虽命中 slot.* 动态前缀（不会触发未定义告警），
        // 但 getPointMeta() 已将其作为固定条目暴露给能力市场，而 toggle() 会校验
        // ExtPoint::defined()，故此处显式声明，使其可被启用/停用。
        ExtPoint::definePoint(ExtPoints::SLOT_DEFINITION, ExtPoint::POLICY_MULTI);
        ExtPoint::definePoint(ExtPoints::SLOT_CONTENT, ExtPoint::POLICY_MULTI);

        // ws.handler 本身是固定扩展点（其 'ws.handler.{action}' 子命名空间才是动态的）
        ExtPoint::definePoint(
            ExtPoints::WS_HANDLER,
            ExtPoint::POLICY_MULTI,
        );

        ExtPoint::definePoint(
            ExtPoints::WS_HEARTBEAT,
            ExtPoint::POLICY_MULTI,
        );

        // route.{app} 扩展点：承载 wise-* 包投递的应用路由。
        // concrete 是闭包（延迟到应用 route 文件加载时才真正调用 Route::group），
        // 故不声明 contract——ExtPoint 的契约校验只对字符串类名生效，
        // 声明接口反而会让所有合法实现被误判为不符合契约。
        foreach (ExtPoints::ROUTE_POINTS as $point) {
            ExtPoint::definePoint($point, ExtPoint::POLICY_MULTI);
        }
    }

    /**
     * 注册 wise-support 内置的实现（Phase 4: 包内自注册）
     */
    protected function registerDefaultImplementations(): void
    {
        // ── ws.heartbeat: 内置 PING/PONG 心跳处理器 ──
        ExtPoint::register(ExtPoints::WS_HEARTBEAT, 'ping_pong', PingPongHeartbeat::class);

        // ── exception.renderer: 默认 JSON 异常渲染器 ──
        ExtPoint::register(ExtPoints::EXCEPTION_RENDERER, 'default', DefaultExceptionRenderer::class);
    }
}
