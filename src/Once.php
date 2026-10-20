<?php
declare(strict_types=1);

namespace wise\support;

/**
 * 请求级闭包缓存（Memoize by call-site）
 *
 * 基于 debug_backtrace 定位调用点，缓存闭包执行结果。
 * 同一 call-site 的同一闭包在同一请求内只执行一次。
 *
 * 缓存键有两种形态：
 *  - **自动键**：调用点(file:line) + 闭包实例 id（仅非 static 闭包追加）；
 *  - **显式键**：调用方通过第二个参数指定，与调用点、闭包实例无关。
 *
 * ⚠️ 使用要点：自动键下**闭包必须声明为 `static`**，否则每次调用都会产生新的闭包实例，
 * 其 `spl_object_id` 被计入缓存键，导致永远不命中。**推荐直接传显式 key**，
 * 既不受该限制，语义也更明确。
 *
 * 使用示例：
 * <pre>
 * // ✅ 推荐：显式 key —— 非 static 闭包同样能命中，且不受调用点影响
 * function getConfig(): array {
 *     return Once::memoize(fn() => include config_path('complex.php'), 'config.complex');
 * }
 *
 * // ✅ 可用：static 闭包 + 自动键（同一调用点只解析一次）
 * function getConfig2(): array {
 *     return Once::memoize(static fn() => include config_path('complex.php'));
 * }
 *
 * // ❌ 反例（自动键 + 非 static 闭包）：每次都是新实例，永不命中
 * function bad(): array {
 *     return Once::memoize(fn() => include config_path('complex.php'));
 * }
 *
 * // 显式失效
 * Once::forget($cb, 'config.complex');
 * </pre>
 *
 * @package wise\support
 */
class Once
{
    /** @var array<string, mixed> 缓存 */
    private static array $values = [];

    /**
     * 保持闭包引用的数组，防止 GC 回收导致 spl_object_id 重复
     * @var array<int, object>
     */
    private static array $closureRefs = [];

    /**
     * 缓存条目数量上限（防常驻进程下同 call-site 新闭包实例导致无限增长）
     *
     * Swoole/Workerman/queue worker 常驻时，若循环内每次产生新闭包实例
     * （同调用点不同 spl_object_id），$values 与 $closureRefs 会持续累积。
     * 达到上限时主动回收最早写入的一批条目，避免内存无界增长。
     * 0 表示不限制（默认），可按需通过 setMaxEntries() 配置。
     *
     * @var int
     */
    private static int $maxEntries = 0;

    /**
     * 设置缓存条目数量上限（0 = 不限制）
     *
     * 建议在 Swoole / Workerman / queue worker 常驻场景按预估规模配置，
     * 例如 setMaxEntries(1000)。仅在写入新条目时触发回收，命中缓存不回收。
     */
    public static function setMaxEntries(int $max): void
    {
        self::$maxEntries = max(0, $max);
    }

    /**
     * 获取当前缓存条目数量
     */
    public static function count(): int
    {
        return count(self::$values);
    }

    /**
     * 缓存闭包执行结果。
     *
     * @param callable    $callback 要缓存的闭包
     * @param string|null $key      显式缓存键；传入时跳过「调用点 + 闭包实例」推导，
     *                              使非 static 闭包也能跨调用点命中。
     *                              传 null 时按调用栈位置推导（兼容原有行为）。
     * @return mixed 闭包返回值（首次执行）或缓存值
     */
    public static function memoize(callable $callback, ?string $key = null)
    {
        $cacheKey = self::buildKey($callback, $key);

        // 保持非静态闭包引用，防止 GC 回收导致 spl_object_id 复用。
        // 显式键不依赖闭包实例身份，无需保持引用。
        if ($key === null && $callback instanceof \Closure) {
            $ref = new \ReflectionFunction($callback);
            if (!$ref->isStatic()) {
                self::$closureRefs[spl_object_id($callback)] = $callback;
            }
        }

        if (array_key_exists($cacheKey, self::$values)) {
            return self::$values[$cacheKey];
        }

        // 写入新条目前触发回收（若配置了上限且已达上限）
        self::reclaimIfNeeded();

        return self::$values[$cacheKey] = $callback();
    }

    /**
     * 缓存超限时回收最早写入的一批条目，防止常驻进程下无界增长。
     *
     * 每次仅回收超限数量的条目（至少 1 条），通过按键插入顺序
     * 截取最旧的部分淘汰。仅在写入新 key 时调用，命中缓存不触发。
     */
    protected static function reclaimIfNeeded(): void
    {
        if (self::$maxEntries <= 0) {
            return;
        }

        $overflow = count(self::$values) - self::$maxEntries;
        if ($overflow < 0) {
            return;
        }

        // 超限时淘汰最旧的 overflow+1 条，避免仅淘汰 1 条导致频繁回收
        $remove = $overflow + 1;
        $oldKeys = array_slice(array_keys(self::$values), 0, $remove, true);
        foreach ($oldKeys as $oldKey) {
            unset(self::$values[$oldKey]);
        }

        // 同步修剪 $closureRefs：仅保留仍被缓存引用的非静态闭包，
        // 避免被淘汰条目对应的旧引用一直残留导致 $closureRefs 无界增长。
        // 闭包对象 id 位于缓存的 key 末尾（':' 分隔的最后一段），据此反查存活引用。
        $alive = [];
        foreach (self::$values as $k => $v) {
            $lastColon = strrpos($k, ':');
            if ($lastColon !== false) {
                $objId = (int) substr($k, $lastColon + 1);
                if ($objId > 0 && isset(self::$closureRefs[$objId])) {
                    $alive[$objId] = self::$closureRefs[$objId];
                }
            }
        }
        self::$closureRefs = $alive;
    }

    /**
     * 清除指定闭包的缓存。
     *
     * 用于需要强制重新计算的场景（如测试、配置热更新）。
     *
     * @param callable    $callback 对应的闭包
     * @param string|null $key      显式缓存键（需与 memoize 时传入的一致）；
     *                              传 null 时按调用位置定位
     */
    public static function forget(callable $callback, ?string $key = null): void
    {
        $cacheKey = self::buildKey($callback, $key);
        unset(self::$values[$cacheKey]);

        // 同步释放闭包引用，避免 $closureRefs 无限增长导致内存泄漏
        if ($key === null && $callback instanceof \Closure) {
            unset(self::$closureRefs[spl_object_id($callback)]);
        }
    }

    /**
     * 清除所有缓存。
     *
     * 主要用于清理测试状态。
     */
    public static function flush(): void
    {
        self::$values = [];
        self::$closureRefs = [];
    }

    /**
     * 根据调用栈和闭包信息构建缓存 key。
     *
     * 策略：
     *  - 显式 key：直接使用，并加 `once.key:` 前缀避免与自动键形态（file:line）冲突
     *  - 自动 key = 调用 once() 的 file:line
     *  - 非静态闭包追加 spl_object_hash 以区分不同实例
     *  - callable 数组（[object, 'method']）追加对象 hash
     *
     * 这确保：
     *  - 同一静态闭包在同一位置共享缓存
     *  - 循环中创建的多个非静态闭包（use 绑定了不同变量）各自独立
     */
    private static function buildKey(callable $callback, ?string $key = null): string
    {
        if ($key !== null && $key !== '') {
            return 'once.key:' . $key;
        }

        // 安全提取调用者：跳过 buildKey 和 memoize 两层，
        // 若 memoize 的调用者又是 once() 辅助函数，则再跳一层
        $caller = self::resolveUserCaller();

        $key = ($caller['file'] ?? '__unknown__') . ':' . ($caller['line'] ?? 0);

        if ($callback instanceof \Closure) {
            $ref = new \ReflectionFunction($callback);
            if (!$ref->isStatic()) {
                // spl_object_id 比 spl_object_hash 更可靠（PHP 7.2+）
                // 配合 $closureRefs 防止 GC 导致 id 复用
                $key .= ':' . spl_object_id($callback);
            }
        } elseif (is_array($callback)) {
            if (is_object($callback[0])) {
                $key .= ':' . spl_object_id($callback[0]);
            } elseif (is_string($callback[0])) {
                $key .= ':' . $callback[0];
            }
            $key .= '::' . $callback[1];
        }

        return $key;
    }

    /**
     * 从调用栈中安全解析用户真正调用点
     *
     * trace[0]=buildKey, trace[1]=memoize, trace[2]=user 或 once() 辅助函数
     * 若 trace[2] 是全局 once()，则取 trace[3] 作为真实调用点
     *
     * @return array{file?: string, line?: int}
     */
    private static function resolveUserCaller(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 6);

        // 取 memoize 的直接调用者（trace[2]）；若栈帧不足则回退到最后一个有效帧
        $memoizeCaller = $trace[2] ?? end($trace) ?: [];
        if (($memoizeCaller['function'] ?? '') === 'once'
            && ($memoizeCaller['class'] ?? '') === '') {
            return $trace[3] ?? $memoizeCaller;
        }
        return $memoizeCaller;
    }
}
