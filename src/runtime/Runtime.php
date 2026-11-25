<?php
declare(strict_types=1);

namespace wise\support\runtime;

/**
 * 运行时薄助手（C-3）
 *
 * 为什么不是 `RuntimeInterface` + FPM/Swoole/Swow 三实现
 * ------------------------------------------------------
 * 三实现会把"我当前跑在哪个运行时"变成一个**需要业务代码感知**的概念，
 * 等于人为造出第二套请求访问路径，侵入性远大于收益。而且 Swow 没有落地路径
 * （`think-swoole` 不支持，Workerman 5 混入与 think-swoole 互斥）。
 *
 * 因此这里只提供**一个静态薄助手**，把散落在各包的 `class_exists(\Swoole\...)`
 * 判断与 `usleep()` 收敛到一处：
 *   - 未装 Swoole 时行为与改造前完全一致；
 *   - 装了 Swoole 时由本类统一给出协程安全的实现；
 *   - 业务代码**永远不需要判断自己运行在哪个 runtime**。
 *
 * 收敛清单（原散点，见 C-3 记录）：
 *   - `class_exists(\Swoole\Coroutine::class) && getCid() > 0` → {@see self::inCoroutine()}
 *   - `\Swoole\Coroutine::getContext()`                   → {@see self::context()}
 *   - `\Swoole\Coroutine::defer()`                        → {@see self::defer()}
 *   - `usleep()` / `sleep()`                              → {@see self::sleep()}
 *
 * @package wise\support\runtime
 */
final class Runtime
{
    /**
     * 是否加载了 Swoole 扩展
     * @return bool
     */
    public static function isSwoole(): bool
    {
        return class_exists(\Swoole\Coroutine::class, false) || extension_loaded('swoole');
    }

    /**
     * 当前协程 ID（0 表示不在协程内，或未安装 Swoole）
     * @return int
     */
    public static function coroutineId(): int
    {
        if (!self::isSwoole()) {
            return 0;
        }

        try {
            return (int) \Swoole\Coroutine::getCid();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * 是否在协程内
     * @return bool
     */
    public static function inCoroutine(): bool
    {
        return self::coroutineId() > 0;
    }

    /**
     * 协程安全的睡眠
     *
     * 协程内必须用 `Coroutine::sleep()`：直接 `usleep()` 会**阻塞整个 worker**，
     * 一个请求的重试等待会把同进程的其他请求一起卡住 —— 这是常驻运行时最典型的
     * 性能事故来源。
     *
     * @param float $seconds 秒（支持小数）
     * @return void
     */
    public static function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        if (self::inCoroutine()) {
            try {
                \Swoole\Coroutine::sleep($seconds);
                return;
            } catch (\Throwable) {
            }
        }

        usleep((int) round($seconds * 1_000_000));
    }

    /**
     * 注册协程结束回调（非协程环境下静默忽略）
     *
     * 用途：协程级上下文/连接的自动回收，避免常驻进程内存泄漏。
     * @param callable $callback 回调函数
     * @return void
     */
    public static function defer(callable $callback): void
    {
        if (!self::inCoroutine()) {
            return;
        }

        try {
            \Swoole\Coroutine::defer($callback);
        } catch (\Throwable) {
        }
    }

    /**
     * 当前协程上下文（非协程环境返回 null）
     *
     * 协程上下文用于存放「请求级、需按协程隔离」的临时数据（缓冲区、追踪 ID 等）。
     * 返回 null 而非抛异常或返回空对象，是为了让调用方用一个 `if` 同时完成
     * 「是否在协程内」的判断与「取上下文」的动作，避免 inCoroutine() + context()
     * 两次判断之间出现不一致状态。
     *
     * 注意：返回的 `ArrayObject` 由 Swoole 在协程结束时自动销毁，不要在协程外持有。
     *
     * @return \ArrayObject|null 协程上下文；非协程环境或取得失败时为 null
     */
    public static function context(): ?\ArrayObject
    {
        if (!self::inCoroutine()) {
            return null;
        }

        try {
            return \Swoole\Coroutine::getContext();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 运行时名称（仅用于日志与诊断，不应据此分支业务逻辑）
     * @return string
     */
    public static function name(): string
    {
        if (self::inCoroutine()) {
            return 'swoole-coroutine';
        }

        return PHP_SAPI === 'cli' ? 'cli' : 'fpm';
    }
}
