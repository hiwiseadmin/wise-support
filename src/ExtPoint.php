<?php
declare(strict_types=1);

namespace wise\support;

use InvalidArgumentException;

/**
 * 统一扩展点注册中心（SPI Registry）
 *
 * 为 WiseAdmin 框架提供统一的「能力注册 — 发现 — 调度」机制。
 * 解决当前 7 种扩展机制各自为政的问题，将所有可替换实现的扩展点
 * 收敛到单一注册中心管理。
 *
 * 核心概念：
 *  - 扩展点（Point）：一个能力类别，如 'payment.driver'
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
 * ExtPoint::definePoint('payment.driver', ExtPoint::POLICY_SINGLE, PaymentDriverInterface::class);
 * ExtPoint::definePoint('oauth.driver', ExtPoint::POLICY_ROUTED, OAuthDriverInterface::class);
 * ExtPoint::definePoint('notification.channel', ExtPoint::POLICY_MULTI);
 *
 * // 2. 插件注册实现
 * ExtPoint::register('payment.driver', 'wechat_pay', WechatPayDriver::class);
 * ExtPoint::register('payment.driver', 'alipay', AlipayDriver::class, 20);
 * ExtPoint::register('oauth.driver', 'github', GithubDriver::class);
 *
 * // 3. 消费方使用
 * $active = ExtPoint::active('payment.driver');              // 当前活跃的支付驱动
 * $all = ExtPoint::all('notification.channel');              // 所有通知通道
 * $github = ExtPoint::get('oauth.driver', 'github');         // 指定平台
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
     * 未定义扩展点即注册的集合（用于诊断拼写错误/顺序问题）
     *
     * 结构: [ point => [name, ...] ]
     *
     * @var array<string, string[]>
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
     * 定义一个扩展点的元信息
     *
     * @param string      $point    扩展点名称，如 'payment.driver'
     * @param string      $policy   调度策略，取 POLICY_SINGLE / POLICY_MULTI / POLICY_ROUTED
     * @param string|null $contract 实现必须遵循的接口或基类（可选）
     */
    public static function definePoint(
        string $point,
        string $policy = self::POLICY_SINGLE,
        ?string $contract = null
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

        // 确保该扩展点的实现槽位已初始化
        if (!isset(static::$registry[$point])) {
            static::$registry[$point] = [];
        }
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
        // 这里记录到日志并收集到诊断集合，但不抛异常/中断——
        // ThinkPHP 的 Error handler 会把任意级别 trigger_error 转为 ErrorException 抛出，
        // 若在 boot 阶段因顺序问题触发会导致整个请求崩溃。
        if ($def === null && !self::isDynamicPoint($point)) {
            static::$undefinedRegistrations[$point][] = $name;
            error_log(sprintf(
                'ExtPoint: 扩展点 [%s] 尚未定义即注册实现 [%s]，请确认已调用 definePoint()。',
                $point, $name
            ));
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
     * @param string $point  扩展点名称
     * @param string $name   实现标识
     * @param bool   $active 是否活跃
     */
    public static function setActive(string $point, string $name, bool $active): void
    {
        if (!isset(static::$registry[$point], static::$registry[$point][$name])) {
            return;
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

        // 同步覆盖表：使持久化状态与内存状态保持一致
        static::syncOverrides($point);

        // 若已绑定持久化存储则自动落库：避免调用方（业务代码/插件）只调用
        // setActive() 而遗漏 persistOverrides()，导致 Swoole/Workerman 下其它
        // worker 读取持久层时看不到本次切换。保存失败时记录但不抛异常，
        // 内存状态本次仍已生效。
        static::persistOverridesQuietly();
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
     * 每次调用都会从存储重新加载（wise-config 内部带缓存，仅在缓存失效时读库），
     * 用于常驻模式（Swoole/Workerman）下跨请求、跨 worker 的一致性刷新：
     * 注册表静态数组只在 worker 启动时构建一次，若不重新应用，其他 worker
     * 上的「设为活跃/停用」操作将无法反映到后续请求。
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

        try {
            return (bool) call_user_func(static::$overrideSaver, static::$overrides);
        } catch (\Throwable $e) {
            error_log('ExtPoint: 持久化活跃覆盖表失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 静默持久化覆盖表：未绑定存储或保存失败时不抛异常。
     *
     * 供 setActive() 在每次变更后自动调用，保证活跃状态跨请求/跨 worker 生效，
     * 避免调用方遗漏显式 persistOverrides()。保存失败仅记录日志，不影响
     * 本次已生效的内存状态。
     */
    protected static function persistOverridesQuietly(): void
    {
        if (static::$overrideSaver === null) {
            return;
        }
        try {
            call_user_func(static::$overrideSaver, static::$overrides);
        } catch (\Throwable $e) {
            error_log('ExtPoint: setActive 后自动持久化活跃覆盖表失败: ' . $e->getMessage());
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
     * 获取「未定义扩展点即注册」的记录（用于诊断拼写错误或 definePoint 顺序问题）
     *
     * @return array<string, string[]>
     */
    public static function getUndefinedRegistrations(): array
    {
        return static::$undefinedRegistrations;
    }

    /**
     * 重置所有注册数据（主要用于测试）
     */
    public static function reset(): void
    {
        static::$registry               = [];
        static::$definitions            = [];
        static::$undefinedRegistrations = [];
        static::$overrides              = [];
        static::$overridesLoaded        = false;
        static::$overrideLoader         = null;
        static::$overrideSaver          = null;
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
