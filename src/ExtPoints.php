<?php
declare(strict_types=1);

namespace wise\support;

/**
 * WiseAdmin 框架标准扩展点常量
 *
 * 统一管理所有框架级扩展点的命名，避免字符串硬编码导致的拼写错误。
 * 扩展点按策略分类定义，各子系统在 ServiceProvider::boot() 中调用
 * ExtPoint::definePoint() 绑定策略和契约。
 *
 * 分类（与 ExtPoint 的调度策略一一对应）：
 *  - POLICY_SINGLE 独占型：同一时刻只有一个活跃实现
 *  - POLICY_ROUTED 路由型：按名称 key 精确查找
 *  - POLICY_MULTI  共享型：所有实现并行存在
 *
 * 命名规范：
 *  - {子系统}.{类别}  如 'menu.item', 'dashboard.widget'
 *  - bus.handler.*    如 'bus.handler.payment.charge'
 *  - slot.*           如 'slot.definition.dashboard.widgets.top'
 *
 * @package wise\support
 */
final class ExtPoints
{
    // ============================================================
    // 独占型扩展点 (POLICY_SINGLE)
    // 同一时刻只有一个活跃实现，其余为候补
    // ============================================================

    // ── 基础驱动 ──

    /** 图片处理驱动 */
    public const IMAGE_DRIVER = 'image.driver';

    /** 图片处理器（resize / watermark 等操作能力，支持插件扩展自定义操作） */
    public const IMAGE_PROCESSOR = 'image.processor';

    // ── 运行时引擎 ──

    /** JWT 引擎（当前活跃的 JWT 实现） */
    public const JWT_PROVIDER = 'jwt.provider';

    // ============================================================
    // 路由型扩展点 (POLICY_ROUTED)
    // 按名称 key 精确查找
    // ============================================================

    /** 认证守卫（admin / api / member / wechat 等） */
    public const AUTH_GUARD = 'auth.guard';

    /** 外部系统连接器 */
    public const EXTERNAL_CONNECTOR = 'connector';

    // ============================================================
    // 共享型扩展点 (POLICY_MULTI)
    // 所有实现并行存在
    // ============================================================

    // ── 界面与内容 ──

    /** 仪表盘挂件 */
    public const DASHBOARD_WIDGET = 'dashboard.widget';

    /**
     * 后台菜单项（包 / 插件声明式注册，由 MenuItemSync 幂等落库）
     *
     * 实现 {@see \wise\support\contracts\MenuItemProvider}：
     *   - containers() 声明一个或多个分组容器（= 侧边栏一级目录），支持多分组；
     *   - items() 用 `container` 键把节点归属到容器（缺省归第一个）。
     * 落库后容器与节点同属 plugin = ident，故 prune / uninstall / prune-orphans
     * 可连带清理（卸载无痕）。
     */
    public const MENU_ITEM = 'menu.item';

    /**
     * 后台前端功能模块（自包含 ESM 模块，如 AI 助手，由包/插件注册）
     *
     * 注册时的 priority 既决定清单次序，也决定**前端挂载次序**：
     * priority 越小越靠前；宿主按该顺序注入 window.__WS_FEATURE_MODULES__，
     * 前端并行 import 后按同一顺序串行调用模块 init()，因此模块自建的 DOM
     * （如顶栏入口）落位可预期。
     *
     * 已用档位：wise-health-check = 100（靠前）、wise-agent = 9999（靠后）；
     * 默认值 100，插件如需插到两者之间可自行取值（同值时按服务启动顺序兜底）。
     */
    public const ADMIN_FRONTEND_MODULE = 'admin.frontend.module';

    // ── 前端插槽 ──

    /** 前端插槽内容 */
    public const SLOT_CONTENT = 'slot.content';

    // ── 服务与通道 ──

    /** 搜索提供者 */
    public const SEARCH_PROVIDER = 'search.provider';

    /** 服务总线消息处理器（子点 'bus.handler.{action}' 为动态前缀） */
    public const BUS_HANDLER_PREFIX = 'bus.handler';

    // ── 应用路由 ──

    /**
     * 应用标识 → 路由扩展点映射
     *
     * 键为 think-multi-app 的应用名，值为该应用对应的路由扩展点。
     * wise-* 包通过 wise\support\router\RouteRegistrar 声明路由后，
     * 基类按评测结果把路由实现投递到对应扩展点；应用在自身的
     * route 文件中调用 RouteRegistrar::applyRoutes() 落地为真实路由规则。
     *
     * 未列入此表的应用（如 install）不提供包路由挂载能力。
     */
    public const ROUTE_POINTS = [
        'api'    => self::ROUTE_API,
        'admin'  => self::ROUTE_ADMIN,
        'member' => self::ROUTE_MEMBER,
        'plugin' => self::ROUTE_PLUGIN,
    ];

    /** API 应用路由（挂载到 /api 下） */
    public const ROUTE_API = 'route.api';

    /** 后台应用路由（挂载到 /admin 下） */
    public const ROUTE_ADMIN = 'route.admin';

    /** 会员应用路由（挂载到 /member 下） */
    public const ROUTE_MEMBER = 'route.member';

    /** 插件应用路由（挂载到 /plugin 下） */
    public const ROUTE_PLUGIN = 'route.plugin';

    // ── WebSocket ──

    /** WebSocket 消息处理器（子点 'ws.handler.{action}' 为动态前缀） */
    public const WS_HANDLER = 'ws.handler';

    /** WebSocket 心跳处理器 */
    public const WS_HEARTBEAT = 'ws.heartbeat';

    // ── MCP（Model Context Protocol）──
    // 由 wise-mcp 包声明契约与策略，其它包/插件注册实现。

    // ── AI 能力 ──

    /**
     * AI 工具权限提供者
     *
     * 由 wise-agent 消费：包 / 插件注册 provider（类名或闭包）向 wise-auth 权限表
     * 补充 AI 工具权限声明，停用后其声明的权限不再注册（= 该插件 AI 能力开关）。
     *
     * 在「能力市场」中可查看并启停，实现按插件粒度的 AI 能力开关。
     */
    public const AI_TOOL_PERMISSION_PROVIDER = 'ai.tool.permission_provider';

    // ── 权限 ──

    /**
     * 权限同步提供者
     *
     * 包 / 插件注册一个提供 `sync(bool $dryRun = false, bool $prune = false): array`
     * 的实现（类名字符串或对象），宿主在以下两个入口统一调用并汇总结果：
     *   - 安装期：{@see \app\install\service\PermissionInitService::build()}
     *   - 后台「更新权限列表」：{@see \app\admin\controller\Permission::updateFromMenuActionApi()}
     *
     * 框架因此无需认识任何具体包的权限注册器（如 wise-agent 的
     * ToolPermissionRegistrar）：包在自身 boot() 阶段注册实现，卸载即失效，
     * 实现「包级权限即插即用 / 解耦」。
     *
     * 约定：实现名建议用包名（如 'wise-agent'）；返回数组应至少包含
     * `created` / `updated` 两个计数键，供宿主汇总展示。
     */
    public const PERMISSION_SYNC_PROVIDER = 'permission.sync_provider';

    // ============================================================
    // 扩展点元数据（供 Capability 等管理界面使用）
    // ============================================================

    /**
     * 获取所有框架扩展点的元数据（名称、策略）
     *
     * 顺序与上方常量分组一致：single → routed → multi。
     *
     * @return array<int, array{point: string, name: string, policy: string}>
     */
    public static function getPointMeta(): array
    {
        return [
            // ── 独占型 ──
            ['point' => self::IMAGE_DRIVER,            'name' => '图片处理驱动',       'policy' => ExtPoint::POLICY_SINGLE],
            ['point' => self::JWT_PROVIDER,            'name' => 'JWT 引擎',          'policy' => ExtPoint::POLICY_SINGLE],

            // ── 路由型 ──
            ['point' => self::AUTH_GUARD,              'name' => '认证守卫',          'policy' => ExtPoint::POLICY_ROUTED],
            ['point' => self::EXTERNAL_CONNECTOR,      'name' => '外部连接器',        'policy' => ExtPoint::POLICY_ROUTED],

            // ── 共享型 ──
            ['point' => self::DASHBOARD_WIDGET,        'name' => '仪表盘挂件',        'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::MENU_ITEM,               'name' => '菜单项',            'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::ADMIN_FRONTEND_MODULE,   'name' => '后台前端功能模块',  'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::SLOT_CONTENT,            'name' => '前端插槽内容',       'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::SEARCH_PROVIDER,         'name' => '搜索提供者',        'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::WS_HANDLER,              'name' => 'WS 消息处理器',     'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::WS_HEARTBEAT,            'name' => 'WS 心跳处理器',     'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::IMAGE_PROCESSOR,         'name' => '图片处理器',        'policy' => ExtPoint::POLICY_MULTI],

            // ── AI 能力 ──
            ['point' => self::AI_TOOL_PERMISSION_PROVIDER, 'name' => 'AI 工具权限提供者', 'policy' => ExtPoint::POLICY_MULTI],

            // ── 权限 ──
            ['point' => self::PERMISSION_SYNC_PROVIDER, 'name' => '权限同步提供者', 'policy' => ExtPoint::POLICY_MULTI],

        ];
    }
}
