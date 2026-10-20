<?php
declare(strict_types=1);

/**
 * Wise-Support 辅助函数
 *
 */

use wise\support\Once;
use wise\support\Pipeline;
use wise\support\rate\RateLimiter;
use wise\support\context\RequestContext;
use think\helper\Arr;

if (!function_exists('str_pipeline')) {
    /**
     * 创建 Pipeline 实例
     *
     * @param mixed $passable 初始值
     * @return Pipeline
     */
    function str_pipeline($passable = null): Pipeline
    {
        $pipeline = new Pipeline();
        if ($passable !== null) {
            $pipeline->send($passable);
        }
        return $pipeline;
    }
}

if (!function_exists('rate_limiter')) {
    /**
     * 创建限流器实例
     *
     * @param int $maxAttempts 最大允许次数
     * @param int $decaySeconds 时间窗口（秒），默认 60
     */
    function rate_limiter(int $maxAttempts, int $decaySeconds = 60): RateLimiter
    {
        return RateLimiter::for($maxAttempts, $decaySeconds);
    }
}

if (!function_exists('array_only_dot')) {
    /**
     * 从数组中提取指定键（支持 "." 分隔的多级路径）
     *
     * think\helper\Arr::only() 只支持顶层 key，本函数支持嵌套提取。
     *
     * @param array $array 源数组
     * @param array $keys  键列表，如 ['user.name', 'user.profile.age']
     * @return array       提取结果，输出键使用路径最后一段
     */
    function array_only_dot(array $array, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (strpos($key, '.') === false) {
                $result[$key] = $array[$key] ?? null;
                continue;
            }
            $value = Arr::get($array, $key);
            if ($value !== null || Arr::has($array, [$key])) {
                $result[substr($key, strrpos($key, '.') + 1)] = $value;
            }
        }
        return $result;
    }
}

if (!function_exists('retry')) {
    /**
     * 失败重试原语，支持指数退避和条件过滤。
     *
     * @param int             $times     最大执行次数（含首次）
     * @param callable        $callback  要重试的业务逻辑，接收 int $attempt（从 1 开始）
     * @param int|callable    $sleepMs   失败后等待毫秒数；为 callable 时签名为 (int $attempt): int，支持动态退避
     * @param callable|null   $when      异常过滤器，签名 (\Throwable $e): bool，返回 false 的异常不重试直接抛出
     * @param callable|null   $onFailure 失败回调，签名 (\Throwable $e, int $attempt): void，不中断重试流程
     * @return mixed callback 的返回值
     * @throws \Throwable    用完次数后无法补偿时的最后一次异常
     */
    function retry(
        int $times,
        callable $callback,
        int|callable $sleepMs = 0,
        ?callable $when = null,
        ?callable $onFailure = null
    ): mixed {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $times) {
            $attempt++;

            try {
                return $callback($attempt);
            } catch (\Throwable $e) {
                $lastException = $e;

                if ($onFailure) {
                    $onFailure($e, $attempt);
                }

                if ($when && !$when($e)) {
                    throw $e;
                }

                if ($attempt >= $times) {
                    throw $e;
                }

                $delay = is_callable($sleepMs) ? $sleepMs($attempt) : $sleepMs;
                if ($delay > 0) {
                    usleep($delay * 1000);
                }
            }
        }

        throw $lastException ?? new \LogicException('Unreachable: retry loop exhausted');
    }
}

if (!function_exists('once')) {
    /**
     * 缓存闭包执行结果（同一请求内只执行一次）。
     *
     * - 传 $key 时使用显式键，与调用位置、闭包实例无关（推荐）；
     * - 不传 $key 时按调用点(file:line)推导，此时闭包需声明为 static 才能真正命中。
     *
     * @param callable    $callback 要缓存的闭包
     * @param string|null $key      显式缓存键
     * @return mixed 闭包返回值（首次执行）或缓存值
     */
    function once(callable $callback, ?string $key = null)
    {
        return Once::memoize($callback, $key);
    }
}

// ============================================================
// Phase 1 新增辅助函数 — 2026-08-02
// ============================================================

if (!function_exists('request_id')) {
    /**
     * 获取当前请求唯一 ID
     *
     * 用于日志追踪、链路分析等场景。
     * 需在全局中间件中先调用 RequestContext::setRequestId()。
     *
     * @return string 未设置时返回 "unknown"
     */
    function request_id(): string
    {
        return RequestContext::getRequestId();
    }
}

if (!function_exists('context')) {
    /**
     * 获取或设置请求上下文
     *
     * @param string|null $key     上下文键名；为 null 时返回全部上下文
     * @param mixed       $default 默认值（仅在 key 不存在时使用）
     * @return mixed
     */
    function context(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return RequestContext::all();
        }
        return RequestContext::get($key, $default);
    }
}

if (!function_exists('wex')) {
    /**
     * 创建 WiseException 的快捷方式
     *
     * @param \wise\support\exception\ErrorCodeInterface $errorCode 错误码枚举（实现 ErrorCodeInterface）
     * @param string $message 错误描述（为空则使用枚举 defaultMessage()）
     * @param int $httpCode HTTP 状态码
     * @param array<string, mixed> $context 附加上下文
     * @return \wise\support\exception\WiseException
     */
    function wex(
        \wise\support\exception\ErrorCodeInterface $errorCode,
        string $message = '',
        int $httpCode = 500,
        array $context = [],
    ): \wise\support\exception\WiseException {
        return new \wise\support\exception\WiseException(
            $errorCode->value,
            $message !== '' ? $message : $errorCode->defaultMessage(),
            $httpCode,
            null,
            $context,
        );
    }
}

// ============================================================
// Phase 2 新增辅助函数 — 2026-08-02
// ============================================================

if (!function_exists('auth_user')) {
    /**
     * 获取当前登录用户（便捷函数）
     *
     * 通过 AuthManager 获取当前 guard 的登录用户。
     *
     * @param string|null $guard 守卫名称，null 使用默认（admin）
     * @return object|null
     */
    function auth_user(?string $guard = null): ?object
    {
        return \wise\support\facade\Auth::user($guard);
    }
}

if (!function_exists('auth_id')) {
    /**
     * 获取当前登录用户 ID（便捷函数）
     *
     * @param string|null $guard 守卫名称，null 使用默认（admin）
     * @return string|int|null
     */
    function auth_id(?string $guard = null): string|int|null
    {
        return \wise\support\facade\Auth::id($guard);
    }
}