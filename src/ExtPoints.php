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
 *  - {子系统}.{类别}  如 'oauth.driver', 'payment.driver'
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

    /** 支付驱动 */
    public const PAYMENT_DRIVER = 'payment.driver';

    /** 短信服务商 */
    public const SMS_PROVIDER = 'sms.provider';

    /** 默认存储驱动 */
    public const STORAGE_DRIVER = 'storage.driver';

    /** 验证码驱动 */
    public const CAPTCHA_DRIVER = 'captcha.driver';

    /** 图片处理驱动 */
    public const IMAGE_DRIVER = 'image.driver';

    /** 缓存驱动（框架级） */
    public const CACHE_DRIVER = 'cache.driver';

    /** 队列驱动（框架级） */
    public const QUEUE_DRIVER = 'queue.driver';

    /** 日志驱动（框架级，区别于 TP 内置 log channel） */
    public const LOG_DRIVER = 'log.driver';

    // ── 运行时引擎 ──

    /** JWT 引擎（当前活跃的 JWT 实现） */
    public const JWT_PROVIDER = 'jwt.provider';

    /** WebSocket 服务端实现（Swoole / Workerman 等） */
    public const WS_SERVER = 'ws.server';

    /** 异常渲染器（API JSON / HTML / CLI 等） */
    public const EXCEPTION_RENDERER = 'exception.renderer';

    // ============================================================
    // 路由型扩展点 (POLICY_ROUTED)
    // 按名称 key 精确查找
    // ============================================================

    /** OAuth 平台驱动 */
    public const OAUTH_DRIVER = 'oauth.driver';

    /** 认证守卫（admin / api / member / wechat 等） */
    public const AUTH_GUARD = 'auth.guard';

    /** 外部系统连接器 */
    public const EXTERNAL_CONNECTOR = 'connector';

    /** 登录安全用户解析器 */
    public const LOGIN_USER_RESOLVER = 'login.user_resolver';

    /** 防沉迷身份提供方（wechat / gov 等，规则引擎按 key 选取） */
    public const ANTI_ADDICTION_IDENTITY = 'anti_addiction.identity';

    // ============================================================
    // 共享型扩展点 (POLICY_MULTI)
    // 所有实现并行存在
    // ============================================================

    // ── 界面与内容 ──

    /** 仪表盘挂件 */
    public const DASHBOARD_WIDGET = 'dashboard.widget';

    /** 菜单项 */
    public const MENU_ITEM = 'menu.item';

    /** 后台前端功能模块（自包含 ESM 模块，如 AI 助手，由包/插件注册） */
    public const ADMIN_FRONTEND_MODULE = 'admin.frontend.module';

    /** 自定义表单字段 */
    public const FORM_FIELD = 'form.field';

    /** 表格列类型 */
    public const TABLE_COLUMN = 'table.column';

    /** 编辑器驱动 */
    public const EDITOR_DRIVER = 'editor.driver';

    /** 编辑器插件 */
    public const EDITOR_PLUGIN = 'editor.plugin';

    // ── 前端插槽 ──

    /** 前端插槽定义 */
    public const SLOT_DEFINITION = 'slot.definition';

    /** 前端插槽内容 */
    public const SLOT_CONTENT = 'slot.content';

    // ── 服务与通道 ──

    /** 通知发送通道 */
    public const NOTIFICATION_CHANNEL = 'notification.channel';

    /** 搜索提供者 */
    public const SEARCH_PROVIDER = 'search.provider';

    /** 工作流管道 */
    public const WORKFLOW_PIPE = 'workflow.pipe';

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

    // ── 会员能力 ──

    /**
     * 会员功能模块注册
     *
     * 各包（含三方）向会员中心声明自定义会员功能：功能标识、名称、图标、
     * 入口路由、所需登录态、对应 config features 开关等。
     * 由 wise-member 主包消费并装配会员中心导航，使「/member/** 即会员功能」
     * 不仅有路由，还有入口。
     */
    public const MEMBER_FEATURE = 'member.feature';

    /** 会员中心入口卡片/导航内容（细粒度插槽，key 为位置标识） */
    public const MEMBER_CENTER_ENTRY = 'member.center.entry';

    /** 会员身份解析器（宿主注入，替代 config('wise-member.auth.member_resolver')） */
    public const MEMBER_IDENTITY_RESOLVER = 'member.identity.resolver';

    // ── WebSocket ──

    /** WebSocket 消息处理器（子点 'ws.handler.{action}' 为动态前缀） */
    public const WS_HANDLER = 'ws.handler';

    /** WebSocket 心跳处理器 */
    public const WS_HEARTBEAT = 'ws.heartbeat';

    // ── MCP（Model Context Protocol）──
    // 由 wise-mcp 包声明契约与策略，其它包/插件注册实现。

    /** MCP 工具提供者（把自身能力以 MCP 工具暴露，多实现并行） */
    public const MCP_TOOL_PROVIDER = 'mcp.tool_provider';

    /** MCP 资源提供者（多实现并行） */
    public const MCP_RESOURCE_PROVIDER = 'mcp.resource_provider';

    /** MCP 提示模板提供者（多实现并行） */
    public const MCP_PROMPT_PROVIDER = 'mcp.prompt_provider';

    /** MCP 传输（stdio / ws 等长驻传输，按名称查找） */
    public const MCP_TRANSPORT = 'mcp.transport';

    /** MCP 命名服务端（按名称查找） */
    public const MCP_SERVER = 'mcp.server';

    /** MCP 客户端外部连接（外部 MCP Server，按名称查找） */
    public const MCP_CLIENT = 'mcp.client';

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
            ['point' => self::PAYMENT_DRIVER,          'name' => '支付驱动',          'policy' => ExtPoint::POLICY_SINGLE],
            ['point' => self::SMS_PROVIDER,            'name' => '短信服务商',        'policy' => ExtPoint::POLICY_SINGLE],
            ['point' => self::STORAGE_DRIVER,          'name' => '存储驱动',          'policy' => ExtPoint::POLICY_SINGLE],
            ['point' => self::CAPTCHA_DRIVER,          'name' => '验证码驱动',        'policy' => ExtPoint::POLICY_SINGLE],
            ['point' => self::IMAGE_DRIVER,            'name' => '图片处理驱动',       'policy' => ExtPoint::POLICY_SINGLE],
            ['point' => self::CACHE_DRIVER,            'name' => '缓存驱动',          'policy' => ExtPoint::POLICY_SINGLE],
            ['point' => self::QUEUE_DRIVER,            'name' => '队列驱动',          'policy' => ExtPoint::POLICY_SINGLE],
            ['point' => self::LOG_DRIVER,              'name' => '日志驱动',          'policy' => ExtPoint::POLICY_SINGLE],
            ['point' => self::JWT_PROVIDER,            'name' => 'JWT 引擎',          'policy' => ExtPoint::POLICY_SINGLE],
            ['point' => self::WS_SERVER,               'name' => 'WebSocket 服务',    'policy' => ExtPoint::POLICY_SINGLE],
            ['point' => self::EXCEPTION_RENDERER,      'name' => '异常渲染器',        'policy' => ExtPoint::POLICY_SINGLE],
            ['point' => self::MEMBER_IDENTITY_RESOLVER,'name' => '会员身份解析器',     'policy' => ExtPoint::POLICY_SINGLE],

            // ── 路由型 ──
            ['point' => self::OAUTH_DRIVER,            'name' => 'OAuth 平台',        'policy' => ExtPoint::POLICY_ROUTED],
            ['point' => self::AUTH_GUARD,              'name' => '认证守卫',          'policy' => ExtPoint::POLICY_ROUTED],
            ['point' => self::EXTERNAL_CONNECTOR,      'name' => '外部连接器',        'policy' => ExtPoint::POLICY_ROUTED],
            ['point' => self::LOGIN_USER_RESOLVER,     'name' => '登录用户解析器',     'policy' => ExtPoint::POLICY_ROUTED],
            ['point' => self::ANTI_ADDICTION_IDENTITY, 'name' => '防沉迷身份提供方',   'policy' => ExtPoint::POLICY_ROUTED],

            // ── 共享型 ──
            ['point' => self::DASHBOARD_WIDGET,        'name' => '仪表盘挂件',        'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::MENU_ITEM,               'name' => '菜单项',            'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::ADMIN_FRONTEND_MODULE,   'name' => '后台前端功能模块',  'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::FORM_FIELD,              'name' => '表单字段',          'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::TABLE_COLUMN,            'name' => '表格列',            'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::EDITOR_DRIVER,           'name' => '编辑器驱动',        'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::EDITOR_PLUGIN,           'name' => '编辑器插件',        'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::SLOT_DEFINITION,         'name' => '前端插槽定义',       'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::SLOT_CONTENT,            'name' => '前端插槽内容',       'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::NOTIFICATION_CHANNEL,    'name' => '通知通道',          'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::SEARCH_PROVIDER,         'name' => '搜索提供者',        'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::WORKFLOW_PIPE,           'name' => '工作流管道',        'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::WS_HANDLER,              'name' => 'WS 消息处理器',     'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::WS_HEARTBEAT,            'name' => 'WS 心跳处理器',     'policy' => ExtPoint::POLICY_MULTI],

            // ── MCP ──
            ['point' => self::MCP_TOOL_PROVIDER,        'name' => 'MCP 工具提供者',    'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::MCP_RESOURCE_PROVIDER,    'name' => 'MCP 资源提供者',    'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::MCP_PROMPT_PROVIDER,      'name' => 'MCP 提示模板提供者', 'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::MCP_TRANSPORT,            'name' => 'MCP 传输',          'policy' => ExtPoint::POLICY_ROUTED],
            ['point' => self::MCP_SERVER,               'name' => 'MCP 服务端',        'policy' => ExtPoint::POLICY_ROUTED],
            ['point' => self::MCP_CLIENT,               'name' => 'MCP 客户端连接',    'policy' => ExtPoint::POLICY_ROUTED],

            // ── 会员能力 ──
            ['point' => self::MEMBER_FEATURE,          'name' => '会员功能模块',      'policy' => ExtPoint::POLICY_MULTI],
            ['point' => self::MEMBER_CENTER_ENTRY,     'name' => '会员中心入口',      'policy' => ExtPoint::POLICY_MULTI],
        ];
    }
}
