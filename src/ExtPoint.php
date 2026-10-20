<?php
declare(strict_types=1);

namespace wise\support;

use InvalidArgumentException;
use wise\support\event\CapabilityToggled;

/**
 * 统一扩展点注册中心（SPI Registry）
 *
 * 为 WiseAdmin 框架提供统一的「能力注册 — 发现 — 调度」机制。
 * 解决当前 7 种扩展机制各自为政的问题，将所有可替换实现的扩展点
 * 收敛到单一注册中心管理。
 *
 * 核心概念：
 *  - 扩展点（Point）：一个能力类别，如 'captcha.driver'
 *  - 实现（Implementation）：扩展点下的一个具体注册项
 *  - 调度策略（Policy）：多个实现时的冲突处理规则
 *
 * 调度策略：
 *  - POLICY_SINGLE  — 独占型：同一时刻只有一个 active 实现
 *  - POLICY_MULTI   — 共享型：所有实现并行存在
 *  - POLICY_ROUTED  — 路由型：按名称精确查找
 *
 * 使用示例：
 * <pre>
 * // 1. 框架启动时定义扩展点
 * ExtPoint::definePoint('example.single', ExtPoint::POLICY_SINGLE, SomeDriverInterface::class);
 * ExtPoint::definePoint('example.routed', ExtPoint::POLICY_ROUTED, SomeRoutedInterface::class);
 * ExtPoint::definePoint('example.multi',  ExtPoint::POLICY_MULTI);
 *
 * // 2. 插件注册实现
 * ExtPoint::register('example.single', 'alpha', AlphaDriver::class);
 * ExtPoint::register('example.single', 'beta', BetaDriver::class, 20);
 * ExtPoint::register('example.routed', 'github', GithubDriver::class);
 *
 * // 3. 消费方使用
 * $active = ExtPoint::active('example.single');              // 当前活跃实现
 * $all = ExtPoint::all('example.multi');                     // 所有实现
 * $github = ExtPoint::get('example.routed', 'github');       // 指定平台
 * </pre>
 *
 * @package wise\support
 */
class ExtPoint
{
    /**
     * 独占型：同一时刻只有一个活跃实现，其余为候补
     */
    public const POLICY_SINGLE = 'single';

    /**
     * 共享型：所有注册实现并行存在
     */
    public const POLICY_MULTI = 'multi';

    /**
     * 路由型：按名称 key 精确查找
     */
    public const POLICY_ROUTED = 'routed';

    /**
     * 动态扩展点前缀：允许在未调用 definePoint() 的情况下直接 register()
     * （如 bus.handler.* / ws.handler.* / slot.* 等按需注册的扩展点）。
     */
    private const DYNAMIC_PREFIXES = ['bus.handler.', 'ws.handler.', 'slot.'];

    /**
     * 安全相关扩展点：变更其「活跃实现」等同变更信任决策
     *
     * 这些点决定谁能认证（auth.guard）、谁来签发/校验令牌（jwt.provider）、
     * 外部系统走谁（connector）。因此其活跃状态的变更必须经宿主授权
     * （bindOverrideAuthorizer），未绑定授权器时默认拒绝（fail-closed）；
     * 其余扩展点不受影响。
     */
    public const SECURITY_RELEVANT_POINTS = [
        ExtPoints::AUTH_GUARD,
        ExtPoints::JWT_PROVIDER,
        ExtPoints::EXTERNAL_CONNECTOR,
    ];

    /**
     * 已注册的实现
     *
     * 结构: [ point => [ name => ['concrete' => string|callable, 'priority' => int, 'active' => bool, ...] ] ]
     *
     * @var array<string, array<string, array>>
     */
    protected static array $registry = [];

    /**
     * 扩展点定义
     *
     * 结构: [ point => ['policy' => string, 'contract' => string|null] ]
     *
     * @var array<string, array>
     */
    protected static array $definitions = [];

    /**
     * 扩展点元数据（展示名 / 描述 / 归属模块等，由点的归属方自声明）
     *
     * 结构: [ point => ['name' => string, 'description' => string, ...] ]
     *
     * 与 $definitions 分离：$definitions 是调度必需（policy / contract），
     * $pointMeta 仅用于展示（能力市场等消费方），缺失不影响注册与调度。
     *
     * @var array<string, array>
     */
    protected static array $pointMeta = [];

    /**
     * 「未定义扩展点即注册」的待补校验集合（诊断拼写错误 / definePoint 顺序问题）
     *
     * 结构: [ point => [ name => ['concrete' => string|callable, 'priority' => int] ] ]
     *
     * 语义：插件 boot 早于包服务 boot 时（启动顺序问题），扩展点尚未 definePoint，
     * register() 拿不到定义、无法做契约校验。此时实现照常进入 registry，
     * 同时登记到此处，待 definePoint() 执行时补做契约校验，使
     * 「先注册后定义」与「先定义后注册」的校验结果一致。
     *
     * @var array<string, array<string, array>>
     */
    protected static array $undefinedRegistrations = [];

    /**
     * 活跃状态覆盖表（用户通过能力市场设置并持久化的状态）
     *
     * 结构: [ point => [name => bool] ]
     *
     * 优先级：覆盖表 > 注册默认值。每次请求重建 registry 时，
     * 若覆盖表中存在该实现的记录，则以其为准。
     *
     * @var array<string, array<string, bool>>
     */
    protected static array $overrides = [];

    /**
     * 覆盖表是否已从存储加载（每个请求只加载一次）
     *
     * @var bool
     */
    protected static bool $overridesLoaded = false;

    /**
     * 覆盖表加载回调（由应用层通过 bindOverrideStore() 注入，保持包层不依赖具体存储）
     *
     * @var callable|null
     */
    protected static $overrideLoader = null;

    /**
     * 覆盖表保存回调（由应用层通过 bindOverrideStore() 注入）
     *
     * @var callable|null
     */
    protected static $overrideSaver = null;

    /**
     * 「变更活跃实现」的授权回调（由应用层通过 bindOverrideAuthorizer() 注入）
     *
     * 未绑定时，安全相关扩展点（SECURITY_RELEVANT_POINTS）的活跃变更一律拒绝。
     *
     * @var callable|null
     */
    protected static $overrideAuthorizer = null;

    /**
     * 事件派发器（由应用层通过 bindEventDispatcher() 注入，保持包层不依赖具体事件实现）
     *
     * 未绑定时不派发（CLI / 测试 / 未接线的宿主均安全）。
     *
     * @var callable|null
     */
    protected static $eventDispatcher = null;

    /**
     * 「实现级展示元数据」提供者（由各包通过 registerImplementationDescriber() 注册）
     *
     * 供能力市场等消费方查询单个实现的展示标记（如会员功能的 pending），
     * 使消费方无需内联判断任何业务包的逻辑。
     *
     * @var array<int, callable>
     */
    protected static array $implementationDescribers = [];

    /**
     * 从存储加载到的覆盖表快照（用于判断本次是否真的在变更安全相关扩展点）
     *
     * @var array<string, array<string, bool>>
     */
    protected static array $loadedOverrides = [];

    /**
     * 定义一个扩展点的元信息
     *
     * @param string      $point    扩展点名称，如 'captcha.driver'
     * @param string      $policy   调度策略，取 POLICY_SINGLE / POLICY_MULTI / POLICY_ROUTED
     * @param string|null $contract 实现必须遵循的接口或基类（可选）
     * @param array       $meta     市场展示元数据（可选，键见 {@see self::describePoint()}）
     */
    public static function definePoint(
        string $point,
        string $policy = self::POLICY_SINGLE,
        ?string $contract = null,
        array $meta = []
    ): void {
        if (!in_array($policy, [self::POLICY_SINGLE, self::POLICY_MULTI, self::POLICY_ROUTED], true)) {
            throw new InvalidArgumentException(
                sprintf('ExtPoint: 策略 [%s] 无效，允许值: single, multi, routed', $policy)
            );
        }

        if (isset(static::$definitions[$point])) {
            $existing = static::$definitions[$point];
            if ($existing['policy'] !== $policy) {
                // 注意：不能用 trigger_error——ThinkPHP 的 Error handler 会把任意级别
                // 错误转为 ErrorException 抛出（error_reporting(E_ALL)），导致中断。
                error_log(sprintf(
                    'ExtPoint: 扩展点 [%s] 已定义为 [%s] 策略，将覆盖为 [%s]',
                    $point, $existing['policy'], $policy
                ));
            }
        }

        static::$definitions[$point] = [
            'policy'   => $policy,
            'contract' => $contract,
        ];

        // 点元数据：由「点的归属方」自声明，供能力市场等消费方自动发现，
        // 避免框架层硬编码各业务包的点。传空数组表示不改动已有元数据。
        if (!empty($meta)) {
            static::describePoint($point, $meta);
        }

        // 确保该扩展点的实现槽位已初始化
        if (!isset(static::$registry[$point])) {
            static::$registry[$point] = [];
        }

        // 补校验「定义之前就已注册」的实现（插件先于包服务启动的启动顺序场景）
        static::verifyDeferredRegistrations($point);
    }

    /**
     * 声明 / 更新扩展点的市场展示元数据
     *
     * 由扩展点的「归属方」调用（谁拥有该点谁声明），使能力市场等消费方无需在
     * 框架层硬编码各业务包的点即可发现与展示。「框架外」的扩展点（业务包 / 插件
     * 自定义点）尤其需要通过本方法或 definePoint($meta) 投递描述。
     *
     * 支持的键（均可选）：
     *  - name        string  展示名（缺省回退为扩展点名称）
     *  - description string  作用描述
     *  - module      string  归属模块标识（如 'wise-member'，用于按来源分组）
     *  - category    string  分组 / 分类（如 '会员'）
     *  - visible     bool    是否在能力市场展示（默认 true）
     *
     * 合并语义：同键后者覆盖、未涉及键保留，便于宿主覆盖业务包默认展示名。
     *
     * @param string $point 扩展点名称
     * @param array  $meta  元数据
     */
    public static function describePoint(string $point, array $meta): void
    {
        static::$pointMeta[$point] = array_merge(static::$pointMeta[$point] ?? [], $meta);
    }

    /**
     * 获取指定扩展点的元数据
     *
     * @return array|null 未声明时返回 null
     */
    public static function getPointMeta(string $point): ?array
    {
        return static::$pointMeta[$point] ?? null;
    }

    /**
     * 获取所有「已自声明元数据」的扩展点
     *
     * 能力市场的数据源之一：凡经 definePoint(..., $meta) 或 describePoint() 声明过的
     * 点都会出现在此（含各业务包 / 插件的自定义点），无需框架层枚举。
     *
     * @return array<string, array> [point => meta]
     */
    public static function describedPoints(): array
    {
        return static::$pointMeta;
    }

    /**
     * 判断扩展点是否已定义
     */
    public static function defined(string $point): bool
    {
        return isset(static::$definitions[$point]);
    }

    /**
     * 获取扩展点定义信息
     *
     * @return array{policy: string, contract: string|null}|null
     */
    public static function getDefinition(string $point): ?array
    {
        return static::$definitions[$point] ?? null;
    }

    /**
     * 获取扩展点的调度策略
     */
    public static function getPolicy(string $point): string
    {
        return static::$definitions[$point]['policy'] ?? self::POLICY_MULTI;
    }

    /**
     * 注册一个扩展点实现
     *
     * - 如果扩展点定义了 contract，会校验 $concrete 是否实现了该接口
     * - single 策略下，同一实现名重复注册会覆盖（后注册者优先）
     * - single 策略下，不同实现名注册时首注册者自动成为 active，后续为候补
     *
     * @param string          $point    扩展点名称
     * @param string          $name     实现标识，如 'wechat_pay'
     * @param string|callable $concrete 类名字符串或工厂闭包
     * @param int             $priority 优先级（越小越优先，默认 100）
     */
    public static function register(
        string $point,
        string $name,
        string|callable $concrete,
        int $priority = 100
    ): void {
        $def = static::$definitions[$point] ?? null;

        // 未定义扩展点即注册：可能是拼写错误、动态扩展点，或 definePoint 尚未执行（启动顺序）。
        //
        // 此处不再直接 error_log：插件 boot 早于包服务 boot 属常态（PluginServiceProvider
        // 在 App::load() 阶段就启动插件，而 wise-* 包服务在 vendor/services.php 阶段注册），
        // 每个插件每批实现都刷一条日志会造成大量噪音。
        // 改为登记到待补校验集合，注册行为不变；definePoint() 执行时统一补做契约校验，
        // 遗漏的契约不符会以明确文案报出，既不刷屏也不丢校验。
        if ($def === null && !self::isDynamicPoint($point)) {
            static::$undefinedRegistrations[$point][$name] = [
                'concrete' => $concrete,
                'priority' => $priority,
            ];
        }

        // 契约校验
        if ($def && $def['contract'] && is_string($concrete)) {
            if (!is_subclass_of($concrete, $def['contract'])) {
                throw new InvalidArgumentException(
                    sprintf(
                        'ExtPoint[%s] 要求实现 %s，[%s] 不符合契约',
                        $point,
                        $def['contract'],
                        $concrete
                    )
                );
            }
        }

        // 确保槽位存在
        if (!isset(static::$registry[$point])) {
            static::$registry[$point] = [];
        }

        $isDuplicate = isset(static::$registry[$point][$name]);

        // 注册前确保活跃覆盖表已加载（首个注册即触发，每个请求只加载一次）
        static::ensureOverridesLoaded();

        // single 策略：若扩展点下尚无任何实现，首注册自动激活
        // multi/routed 策略：所有实现默认激活
        $shouldBeActive = true;
        if ($def && $def['policy'] === self::POLICY_SINGLE) {
            $shouldBeActive = empty(static::$registry[$point]);
        }

        $active = $shouldBeActive || ($isDuplicate && (static::$registry[$point][$name]['active'] ?? false));

        // 覆盖表优先：用户在能力市场设置过的活跃状态以覆盖为准
        if (isset(static::$overrides[$point][$name])) {
            $active = (bool) static::$overrides[$point][$name];
        }

        static::$registry[$point][$name] = [
            'concrete'  => $concrete,
            'priority'  => $priority,
            'active'    => $active,
            'source'    => static::resolveSource(),
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        // 重复注册是正常行为（插件重装），后注册者覆盖前者
        // 此处不记录日志以避免日志噪音，如需审计可通过外部事件订阅
    }

    /**
     * 获取扩展点下所有已注册的实现（按优先级排序）
     *
     * @return array<string, array>
     */
    public static function all(string $point): array
    {
        $items = static::$registry[$point] ?? [];
        // 强制值拷贝，避免 uasort 就地排序污染静态存储
        $sorted = $items;
        uasort($sorted, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
        return $sorted;
    }

    /**
     * 获取指定名称的实现类名或工厂
     *
     * @return string|callable|null
     */
    public static function get(string $point, string $name): string|callable|null
    {
        return static::$registry[$point][$name]['concrete'] ?? null;
    }

    /**
     * 获取 single 策略下的活跃实现
     *
     * @return array{name: string, concrete: string|callable, priority: int}|null
     */
    public static function active(string $point): ?array
    {
        $all = static::all($point);

        foreach ($all as $name => $item) {
            if ($item['active'] ?? false) {
                return array_merge(['name' => $name], $item);
            }
        }

        // 没有标记为 active 的，返回 priority 最高的
        $first = reset($all);
        if ($first === false) {
            return null;
        }

        $firstName = array_key_first($all);
        return array_merge(['name' => $firstName], $first);
    }

    /**
     * 设置实现项的活跃状态
     *
     * single 策略：激活一个时自动停用同扩展点下其他所有实现
     * multi/routed 策略：独立控制，不影响其他
     *
     * 安全相关扩展点（SECURITY_RELEVANT_POINTS）需要宿主授权
     * （bindOverrideAuthorizer），未获授权时拒绝变更并返回 false。
     *
     * @param string $point  扩展点名称
     * @param string $name   实现标识
     * @param bool   $active 是否活跃
     * @param string $source 变更来源（'admin' 运营 / 'system' 程序），随事件派发
     * @return bool 是否已变更（实现不存在或未获授权时为 false）
     */
    public static function setActive(string $point, string $name, bool $active, string $source = 'system'): bool
    {
        if (!isset(static::$registry[$point], static::$registry[$point][$name])) {
            return false;
        }

        // 变更前状态（供事件 payload 与审计使用）
        $before = (bool) (static::$registry[$point][$name]['active'] ?? false);

        if (!static::isOverrideAuthorized($point, $name, $active)) {
            error_log(sprintf(
                'ExtPoint: 拒绝变更安全相关扩展点 [%s] 实现 [%s] 的活跃状态（%s）：未获授权',
                $point,
                $name,
                $active ? '启用' : '停用',
            ));
            return false;
        }

        $def = static::$definitions[$point] ?? null;

        if ($def && $def['policy'] === self::POLICY_SINGLE) {
            if ($active) {
                // single 策略：激活一个时，停用同扩展点下所有其他
                foreach (static::$registry[$point] as $n => &$item) {
                    $item['active'] = ($n === $name);
                }
                unset($item);
            } else {
                // single 策略：停用时，如果目标当前活跃，自动激活最优先级的备用实现
                if (static::$registry[$point][$name]['active']) {
                    static::$registry[$point][$name]['active'] = false;

                    // 找最优先级且已注册的其他实现作为备用
                    $alternatives = static::all($point);
                    $fallback = null;
                    foreach ($alternatives as $altName => $altItem) {
                        if ($altName !== $name) {
                            $fallback = $altName;
                            break;
                        }
                    }

                    if ($fallback !== null) {
                        static::$registry[$point][$fallback]['active'] = true;
                    }
                }
            }
        } else {
            static::$registry[$point][$name]['active'] = $active;
        }

        // 同步覆盖表：使内存活跃状态与覆盖表保持一致
        static::syncOverrides($point);

        // 派发「活跃实现被切换」事件：由关注方（业务包）订阅并自行响应
        // （如会员能力显隐审计），变更方（能力市场控制器）无需感知任何业务包。
        static::dispatch(new CapabilityToggled($point, $name, $before, $active, $source));

        return true;
    }

    /**
     * 绑定活跃覆盖表的持久化存储回调
     *
     * 由应用层在启动阶段注入，保证 wise-support 包层不依赖具体存储实现；
     * 后续更换持久化方案（独立配置表、Redis 等）时仅需替换回调。
     *
     * @param callable|null $loader 加载回调，返回 [point => [name => bool]]，null 表示无存储
     * @param callable|null $saver  保存回调，入参同上，返回 bool，null 表示不支持保存
     */
    public static function bindOverrideStore(?callable $loader, ?callable $saver = null): void
    {
        static::$overrideLoader  = $loader;
        static::$overrideSaver   = $saver;
        // 重新绑定后允许重新加载，兼容启动顺序异常的场景
        static::$overridesLoaded = false;
    }

    /**
     * 绑定「变更活跃实现」的授权回调
     *
     * 由应用层在启动阶段注入，用于回答「本次请求是否有权变更该扩展点的活跃实现」。
     * 未绑定时安全相关扩展点（SECURITY_RELEVANT_POINTS）默认拒绝（fail-closed）。
     *
     * @param callable|null $authorizer fn(string $point, string $name, bool $active): bool
     */
    public static function bindOverrideAuthorizer(?callable $authorizer): void
    {
        static::$overrideAuthorizer = $authorizer;
    }

    /**
     * 绑定事件派发器（由应用层在启动阶段注入）
     *
     * 典型实现：桥接到框架事件系统，使各包可用标准
     * `Event::listen(CapabilityToggled::class, ...)` 订阅扩展点事件，
     * 而 ExtPoint 保持零依赖。未绑定时静默不派发（CLI / 测试安全）。
     *
     * @param callable|null $dispatcher fn(object $event): void
     */
    public static function bindEventDispatcher(?callable $dispatcher): void
    {
        static::$eventDispatcher = $dispatcher;
    }

    /**
     * 派发扩展点事件对象
     *
     * fail-safe：未绑定派发器时不派发；派发异常被吞掉并记录，
     * 绝不让「事件消费方的问题」影响扩展点本身的注册与调度。
     *
     * @param object $event 事件对象（如 {@see CapabilityToggled}）
     */
    protected static function dispatch(object $event): void
    {
        if (static::$eventDispatcher === null) {
            return;
        }

        try {
            call_user_func(static::$eventDispatcher, $event);
        } catch (\Throwable $e) {
            error_log(sprintf('ExtPoint: 派发事件 [%s] 失败: %s', $event::class, $e->getMessage()));
        }
    }

    /**
     * 注册「实现级展示元数据」提供者
     *
     * 由各包自注册：消费方（能力市场等）在构建单个实现的展示数据时，
     * 经 {@see self::describeImplementation()} 汇总各提供者的返回，
     * 从而无需在框架层内联判断任何业务包的实现状态。
     *
     * @param callable $describer fn(string $point, string $name, mixed $concrete): array
     */
    public static function registerImplementationDescriber(callable $describer): void
    {
        static::$implementationDescribers[] = $describer;
    }

    /**
     * 汇总指定实现的展示元数据
     *
     * 依次调用各提供者并合并返回数组（后者覆盖同键）；提供者异常被吞掉，
     * 保证展示链路不因某个业务包报错而中断（fail-safe）。
     *
     * @param string $point    扩展点名称
     * @param string $name     实现标识
     * @param mixed  $concrete 实现类名或工厂闭包
     * @return array 合并后的展示元数据（如 ['pending' => true]）
     */
    public static function describeImplementation(string $point, string $name, mixed $concrete = null): array
    {
        $meta = [];

        foreach (static::$implementationDescribers as $describer) {
            try {
                $meta = array_merge($meta, (array) call_user_func($describer, $point, $name, $concrete));
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'ExtPoint: 实现元数据提供者异常（point=%s name=%s）: %s',
                    $point,
                    $name,
                    $e->getMessage()
                ));
            }
        }

        return $meta;
    }

    /**
     * 某扩展点的活跃变更是否已获授权
     *
     * @param string $point  扩展点名称
     * @param string $name   实现标识
     * @param bool   $active 目标活跃状态
     * @return bool 非安全相关扩展点恒为 true；安全相关扩展点需授权器明确放行
     */
    public static function isOverrideAuthorized(string $point, string $name, bool $active): bool
    {
        if (!in_array($point, self::SECURITY_RELEVANT_POINTS, true)) {
            return true;
        }

        if (static::$overrideAuthorizer === null) {
            return false;
        }

        try {
            return (bool) call_user_func(static::$overrideAuthorizer, $point, $name, $active);
        } catch (\Throwable $e) {
            // 授权器异常一律按拒绝处理，避免「检查失败 = 放行」
            error_log('ExtPoint: 活跃变更授权检查异常，按拒绝处理: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取当前活跃覆盖表
     *
     * @return array<string, array<string, bool>>
     */
    public static function getOverrides(): array
    {
        static::ensureOverridesLoaded();
        return static::$overrides;
    }

    /**
     * 将持久化的覆盖表重新应用到当前内存注册表
     *
     */
    public static function applyPersistedOverrides(): void
    {
        static::$overridesLoaded = false;
        static::ensureOverridesLoaded();

        if (empty(static::$overrides)) {
            return;
        }

        foreach (static::$overrides as $point => $states) {
            if (!isset(static::$registry[$point]) || !is_array($states)) {
                continue;
            }
            foreach ($states as $name => $active) {
                if (isset(static::$registry[$point][$name])) {
                    static::$registry[$point][$name]['active'] = (bool) $active;
                }
            }
        }
    }

    /**
     * 将当前覆盖表持久化到存储
     *
     * @return bool 是否保存成功（未绑定存储时返回 false）
     */
    public static function persistOverrides(): bool
    {
        if (static::$overrideSaver === null) {
            return false;
        }

        // 落盘前校验安全相关扩展点的授权：覆盖表整体保存，任何「与已落盘状态不同」的
        // 安全点都必须获授权，否则 setActive 的授权门可被「先改内存再持久化」绕过。
        foreach (static::$overrides as $point => $states) {
            if (!is_array($states) || !in_array((string) $point, self::SECURITY_RELEVANT_POINTS, true)) {
                continue;
            }

            foreach ($states as $name => $active) {
                if ((static::$loadedOverrides[$point][$name] ?? null) === (bool) $active) {
                    continue; // 与已落盘状态一致 → 本次并未变更该安全点
                }

                if (!static::isOverrideAuthorized((string) $point, (string) $name, (bool) $active)) {
                    error_log(sprintf(
                        'ExtPoint: 拒绝持久化覆盖表：安全相关扩展点 [%s] 实现 [%s] 未获授权',
                        $point,
                        $name,
                    ));
                    return false;
                }
            }
        }

        try {
            $saved = (bool) call_user_func(static::$overrideSaver, static::$overrides);
            if ($saved) {
                static::$loadedOverrides = static::$overrides;
            }
            return $saved;
        } catch (\Throwable $e) {
            error_log('ExtPoint: 持久化活跃覆盖表失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 静默持久化覆盖表：未绑定存储或保存失败时不抛异常。
     *
     */
    protected static function persistOverridesQuietly(): void
    {
        if (static::$overrideSaver === null) {
            return;
        }
        try {
            call_user_func(static::$overrideSaver, static::$overrides);
        } catch (\Throwable $e) {
            error_log('ExtPoint: 静默持久化活跃覆盖表失败: ' . $e->getMessage());
        }
    }

    /**
     * 确保覆盖表已从存储加载（每个请求只执行一次）
     */
    protected static function ensureOverridesLoaded(): void
    {
        if (static::$overridesLoaded) {
            return;
        }
        static::$overridesLoaded = true;

        if (static::$overrideLoader === null) {
            return;
        }

        try {
            $data = call_user_func(static::$overrideLoader);
            if (is_array($data)) {
                static::$overrides = $data;
                // 记录「已落盘状态」，用于判断本次持久化是否真的在变更安全相关扩展点
                static::$loadedOverrides = $data;
            }
        } catch (\Throwable $e) {
            // 存储不可用时降级为空覆盖，保证注册流程不受影响
            error_log('ExtPoint: 加载活跃覆盖表失败: ' . $e->getMessage());
        }
    }

    /**
     * 将指定扩展点的内存活跃状态同步到覆盖表
     * 
     * @param string $point 扩展点名称
     */
    protected static function syncOverrides(string $point): void
    {
        static::$overrides[$point] = [];
        foreach (static::$registry[$point] as $n => $item) {
            static::$overrides[$point][$n] = (bool) ($item['active'] ?? false);
        }
    }

    /**
     * 根据策略解析扩展点
     *
     * @param string      $point    扩展点名称
     * @param string|null $routeKey ROUTED 策略下的路由键（null 则返回所有实现列表，与 MULTI 一致）
     * @param string|null $strategy 覆盖默认策略（null 则使用定义时的策略）
     * @return array
     */
    public static function resolve(string $point, ?string $routeKey = null, ?string $strategy = null): array
    {
        $policy = $strategy ?? static::getPolicy($point);

        return match ($policy) {
            self::POLICY_SINGLE => static::activeAsList($point),
            self::POLICY_ROUTED => $routeKey !== null
                ? static::routeSingle($point, $routeKey)
                : static::all($point),
            self::POLICY_MULTI => static::all($point),
        };
    }

    /**
     * ROUTED 策略：按路由键查找单个实现
     *
     * @return array 包含 name/concrete/priority 等字段的实现数组，未找到返回空数组
     */
    protected static function routeSingle(string $point, string $routeKey): array
    {
        $item = static::$registry[$point][$routeKey] ?? null;
        if ($item === null) {
            return [];
        }
        return [array_merge(['name' => $routeKey], $item)];
    }

    /**
     * 注销一个扩展点实现
     */
    public static function unregister(string $point, string $name): void
    {
        unset(static::$registry[$point][$name]);
    }

    /**
     * 检查扩展点下是否存在指定实现
     */
    public static function has(string $point, string $name): bool
    {
        return isset(static::$registry[$point][$name]);
    }

    /**
     * 检查扩展点下是否有任何实现
     */
    public static function isEmpty(string $point): bool
    {
        return empty(static::$registry[$point]);
    }

    /**
     * 获取扩展点下实现的数量
     */
    public static function count(string $point): int
    {
        return count(static::$registry[$point] ?? []);
    }

    /**
     * 列出所有已注册的扩展点名称
     *
     * @return string[]
     */
    public static function points(): array
    {
        return array_keys(static::$registry);
    }

    /**
     * 对「在 definePoint() 之前就已注册」的实现补做契约校验
     *
     * 启动顺序场景（插件 boot 早于 wise-* 包服务 boot）下，register() 取不到定义，
     * 只能跳过契约校验。definePoint() 执行时在此补校验，避免契约不符被静默漏掉。
     *
     * 与正常顺序的差异：补校验失败**不抛异常**（此时实现早已注册进 registry，
     * 抛异常只会让定扩展点的包 boot 崩溃），改为以 error_log 报出明确原因，
     * 保持「启动顺序问题不导致请求崩溃」的 fail-safe 语义。
     *
     * @param string $point 扩展点名称
     */
    protected static function verifyDeferredRegistrations(string $point): void
    {
        $deferred = static::$undefinedRegistrations[$point] ?? [];
        if (empty($deferred)) {
            return;
        }

        // 一次性消费：同一定义重复执行时不重复校验
        unset(static::$undefinedRegistrations[$point]);

        $def = static::$definitions[$point] ?? null;
        if (empty($def['contract'])) {
            return;
        }

        foreach ($deferred as $name => $item) {
            $concrete = $item['concrete'] ?? null;

            // 闭包工厂无法做类型校验，与 register() 的处理保持一致
            if (!is_string($concrete)) {
                continue;
            }

            if (!is_subclass_of($concrete, $def['contract'])) {
                error_log(sprintf(
                    'ExtPoint: 扩展点 [%s] 要求实现 %s，但先于定义注册的实现 [%s]（%s）不符合契约。',
                    $point,
                    $def['contract'],
                    $name,
                    $concrete
                ));
            }
        }
    }

    /**
     * 获取「未定义扩展点即注册」的记录（用于诊断拼写错误或 definePoint 顺序问题）
     *
     * 注意：definePoint() 补校验消费后会从集合中移除；
     * 若此处仍有记录，说明对应扩展点在整个生命周期内始终未被定义（多半是拼写错误）。
     *
     * @return array<string, string[]>
     */
    public static function getUndefinedRegistrations(): array
    {
        $map = [];

        foreach (static::$undefinedRegistrations as $point => $items) {
            $map[$point] = array_keys($items);
        }

        return $map;
    }

    /**
     * 重置所有注册数据（主要用于测试）
     */
    public static function reset(): void
    {
        static::$registry               = [];
        static::$definitions            = [];
        static::$pointMeta              = [];
        static::$undefinedRegistrations = [];
        static::$overrides              = [];
        static::$overridesLoaded        = false;
        static::$overrideLoader         = null;
        static::$overrideSaver          = null;
        static::$overrideAuthorizer     = null;
        static::$loadedOverrides        = [];
        static::$eventDispatcher        = null;
        static::$implementationDescribers = [];
    }

    /**
     * 将活跃实现包装为与 all() 一致的列表格式
     *
     * @return array<string, array>
     */
    protected static function activeAsList(string $point): array
    {
        $active = static::active($point);
        if ($active === null) {
            return [];
        }

        $name = $active['name'];
        unset($active['name']);
        return [$name => $active];
    }

    /**
     * 判断扩展点是否为允许按需注册的动态扩展点
     *
     * 动态扩展点的名称由运行时输入拼接产生（bus.handler.{action} 等），
     * 编码期无法穷举，故不会出现在 $definitions 中。
     * 消费方（如能力市场）在校验「扩展点是否存在」时，须同时考虑
     * defined() 与本方法，否则会误判动态扩展点为未定义。
     */
    public static function isDynamicPoint(string $point): bool
    {
        foreach (self::DYNAMIC_PREFIXES as $prefix) {
            if (str_starts_with($point, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 通过调用栈解析注册来源
     */
    protected static function resolveSource(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        // 跳过 ExtPoint::register → ExtPoint::resolveSource
        // 取调用 register 的类名
        foreach ($trace as $frame) {
            if (isset($frame['class']) && $frame['class'] !== self::class) {
                return $frame['class'];
            }
        }
        return 'unknown';
    }
}
