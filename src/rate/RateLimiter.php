<?php

declare(strict_types=1);

namespace wise\support\rate;

use think\facade\Cache;
use think\facade\Log;
use RuntimeException;
use wise\support\traits\Conditionable;
use wise\support\traits\Tappable;

/**
 * 通用频率限制器
 *
 * 提供统一的限流能力，支持按分钟/小时/天时间窗口，
 * 可按任意 key（IP、用户ID、接口路径等）独立限流。
 *
 * **生产环境强烈建议使用 Redis 驱动**（`.via('redis')`），
 * 以保证 inc/expire 的原子性操作，避免 TOCTOU 竞态条件。
 * `file` 驱动在低并发场景可用，但高并发下存在计数丢失风险。
 *
 * 使用示例：
 * ```php
 * use wise\support\rate\RateLimiter;
 *
 * // 生产环境：使用 Redis 驱动
 * $limiter = RateLimiter::perMinute(60)->via('redis')->by("api:user:{$userId}");
 *
 * // 每分钟最多 60 次
 * if ($limiter->tooManyAttempts()) {
 *     throw new RuntimeException('请求过于频繁');
 * }
 * $remaining = $limiter->hit();
 *
 * // 批量操作加权计数
 * $limiter->hit(10); // 一次消费 10 次额度
 *
 * // HTTP 标准响应头
 * $headers = $limiter->toHeaders(); // X-RateLimit-* + Retry-After
 *
 * // 超限回调（可观测性）
 * $limiter->onLimitReached(function($key, $max) {
 *     Log::warning("限流触发: {$key}, 上限: {$max}");
 * });
 * ```
 *
 * @warning 高并发场景下必须使用 Redis 驱动，file 驱动存在 TOCTOU 竞态条件
 */
class RateLimiter implements RateLimitInterface
{
    use Conditionable;
    use Tappable;

    /** @var int 最大允许次数 */
    protected int $maxAttempts;

    /** @var int 时间窗口（秒） */
    protected int $decaySeconds;

    /** @var string 限流标识 */
    protected string $key = '';

    /** @var string 缓存前缀 */
    protected string $prefix = 'rate_limit';

    /** @var string 缓存驱动 */
    protected string $cacheStore = 'file';

    /** @var mixed 可注入的缓存适配器（用于测试） */
    protected $customCache = null;

    /** @var \Closure|null 超限回调 */
    protected $onLimitReached = null;

    /** @var bool 非原子回退路径告警是否已发出（进程内仅告警一次） */
    private static bool $warnedNonAtomic = false;

    /**
     * @param int $maxAttempts 最大允许次数
     * @param int $decaySeconds 时间窗口（秒）
     */
    public function __construct(int $maxAttempts = 60, int $decaySeconds = 60)
    {
        $this->maxAttempts = max(1, $maxAttempts);
        $this->decaySeconds = max(1, $decaySeconds);
    }

    // ────────────────── 静态工厂方法 ──────────────────

    /**
     * 通用工厂：指定最大次数和时间窗口
     * 例：RateLimiter::for(100, 3600)->by("api:user:1")
     */
    public static function for(int $maxAttempts, int $decaySeconds = 60): self
    {
        return new self($maxAttempts, $decaySeconds);
    }

    /**
     * 每分钟限制
     */
    public static function perMinute(int $maxAttempts): self
    {
        return new self($maxAttempts, 60);
    }

    /**
     * 每分钟限制，指定窗口分钟数
     * 例：perMinutes(5, 15) → 15 分钟内最多 5 次（常用于密码失败锁定）
     */
    public static function perMinutes(int $maxAttempts, int $minutes): self
    {
        return new self($maxAttempts, max(1, $minutes) * 60);
    }

    /**
     * 每小时限制
     */
    public static function perHour(int $maxAttempts): self
    {
        return new self($maxAttempts, 3600);
    }

    /**
     * 每小时限制，指定窗口小时数
     */
    public static function perHours(int $maxAttempts, int $hours): self
    {
        return new self($maxAttempts, max(1, $hours) * 3600);
    }

    /**
     * 每天限制
     */
    public static function perDay(int $maxAttempts): self
    {
        return new self($maxAttempts, 86400);
    }

    /**
     * 从当前 Request 自动获取 key（默认用 IP），便于中间件一行接入
     *
     * @param int         $maxAttempts  最大允许次数
     * @param int         $decaySeconds 时间窗口（秒）
     * @param string|null $key          自定义 key，null 则用 request()->ip()
     */
    public static function forRequest(int $maxAttempts, int $decaySeconds = 60, ?string $key = null): self
    {
        $instance = new self($maxAttempts, $decaySeconds);
        if ($key === null) {
            $request = function_exists('request') ? request() : null;
            if ($request === null || !method_exists($request, 'ip')) {
                throw new RuntimeException('RateLimiter::forRequest 无法获取请求上下文，请显式传入 $key');
            }
            $key = $request->ip();
        }
        return $instance->by($key);
    }

    // ────────────────── 流式配置方法 ──────────────────

    /**
     * 设置限流 key
     */
    public function by(string $key): self
    {
        $this->key = $key;
        return $this;
    }

    /**
     * 设置缓存前缀（默认 "rate_limit"）
     */
    public function withPrefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * 指定缓存驱动（默认 "file"）
     */
    public function via(string $store): self
    {
        $this->cacheStore = $store;
        return $this;
    }

    /**
     * 设置缓存适配器（主要用于测试注入 mock）
     */
    public function setCache(object $cache): self
    {
        $this->customCache = $cache;
        return $this;
    }

    /**
     * 设置超限回调（可观测性）
     *
     * @param \Closure $callback function(string $key, int $max, int $decaySeconds): void
     */
    public function onLimitReached(\Closure $callback): self
    {
        $this->onLimitReached = $callback;
        return $this;
    }

    // ────────────────── 核心操作 ──────────────────

    /**
     * 是否超出限制
     */
    public function tooManyAttempts(): bool
    {
        $this->ensureKey();
        return $this->attempts() >= $this->maxAttempts;
    }

    /**
     * 记录访问并返回剩余次数
     *
     * @param int      $weight 加权计数，默认 1（批量操作可传 >1）
     * @param int|null $limit  本次计数的最大次数，默认取实例 $maxAttempts
     * @param int|null $window 本次计数的时间窗口（秒），默认取实例 $decaySeconds
     *                         注意：原子 inc 分支仅在首次创建计数键时设置窗口 TTL（固定窗口语义，
     *                         避免每次刷新退化为滑动窗口）；无 inc() 的非原子回退分支必须每次
     *                         写入都带 TTL（否则计数键永不过期、窗口永不重置，退化为滑动窗口）
     * @return int 剩余可用次数，0=已超限
     */
    public function hit(int $weight = 1, ?int $limit = null, ?int $window = null): int
    {
        $this->ensureKey();
        $weight = max(1, $weight);
        $limit  = $limit  ?? $this->maxAttempts;
        $window = $window ?? $this->decaySeconds;

        $key = $this->buildCacheKey();
        $cache = $this->cache();

        if (method_exists($cache, 'inc')) {
            // 用 has() 明确判断是否首次创建，避免依赖 inc() 对不存在的 key 从 0 起计数的假设
            $isNew = method_exists($cache, 'has') ? !$cache->has($key) : false;
            $hits = (int) $cache->inc($key, $weight);
            // 仅在首次创建计数键时设置窗口 TTL，避免每次命中刷新 TTL 退化为滑动窗口。
            // 驱动不提供 has() 时 $isNew 无从判断，此时用「本次增量 == 当前计数」识别首次写入，
            // 否则无 TTL 的计数键会永久保留、窗口永不重置。
            $isFirst = $isNew || $hits === $weight;
            if ($isFirst && method_exists($cache, 'expire')) {
                $cache->expire($key, $window);
            }
        } else {
            // @warning 回退路径：首次写入使用 set（仅 key 不存在时写入），存在 TOCTOU 竞态条件。
            // 高并发场景下，多个请求可能同时检测到 key 不存在并分别写入，导致计数丢失。
            // 生产环境请使用 Redis 驱动（走 inc 分支，原子操作）。
            if (!self::$warnedNonAtomic) {
                self::$warnedNonAtomic = true;
                // 禁用 trigger_error：ThinkPHP 错误处理器会把 E_USER_WARNING 抛成
                // ErrorException，导致非原子回退路径首次调用即中断（500）。
                error_log('[RateLimiter] 当前缓存驱动不提供原子 inc()，存在 TOCTOU 竞态，建议使用 via("redis")。');
                // 附加一条框架日志；Log 门面在容器未就绪（如极早期 CLI）时不可用，
                // 捕获异常避免降级路径本身成为新的失败点（error_log 已足够兜底）。
                try {
                    Log::warning('RateLimiter 当前缓存驱动不提供原子 inc()，存在 TOCTOU 竞态，建议使用 via("redis")。');
                } catch (\Throwable) {
                    // Log 不可用时已由 error_log 记录
                }
            }
            if (method_exists($cache, 'has') ? !$cache->has($key) : ((int) $cache->get($key, 0) === 0)) {
                $cache->set($key, $weight, $window);
                $hits = $weight;
            } else {
                $hits = (int) $cache->get($key, 0) + $weight;
                // 必须带窗口 TTL：不带 TTL 的 set 会把首次写入的窗口抹掉，
                // 使该计数键永不过期（窗口永不重置）。代价是回退分支退化为滑动窗口，
                // 这是该后端无原子自增时所能提供的唯一可靠语义。
                $cache->set($key, $hits, $window);
            }
        }

        $remaining = max(0, $limit - $hits);

        // 超限回调
        if ($remaining <= 0 && $this->onLimitReached !== null) {
            ($this->onLimitReached)($this->key, $limit, $window);
        }

        return $remaining;
    }

    /**
     * 获取当前已记录次数
     */
    public function attempts(): int
    {
        $this->ensureKey();
        return (int) $this->cache()->get($this->buildCacheKey(), 0);
    }

    /**
     * 剩余可用次数
     */
    public function remaining(): int
    {
        return max(0, $this->maxAttempts - $this->attempts());
    }

    /**
     * 获取限制配置
     *
     * @return array{max: int, remaining: int, decay_seconds: int}
     */
    public function getLimit(): array
    {
        return [
            'max'           => $this->maxAttempts,
            'remaining'     => $this->remaining(),
            'decay_seconds' => $this->decaySeconds,
        ];
    }

    /**
     * 冷却剩余秒数（通过缓存 TTL 获取实际剩余时间）
     */
    public function cooldown(): int
    {
        $this->ensureKey();
        if (!$this->tooManyAttempts()) {
            return 0;
        }

        $cache = $this->cache();
        if (method_exists($cache, 'getTtl')) {
            $ttl = $cache->getTtl($this->buildCacheKey());
            return is_numeric($ttl) ? max(0, (int) $ttl) : 0;
        }

        // 无法获取精确 TTL 时，返回一个保守但合理的估计值
        // 若当前计数已达到上限，至少等待 1 秒让调用方可以重试
        return max(1, min($this->decaySeconds, 60));
    }

    /**
     * 返回可用的时间戳（超限时返回解禁时间点，未超限返回 null）
     */
    public function availableAt(): ?int
    {
        $cooldown = $this->cooldown();
        return $cooldown > 0 ? time() + $cooldown : null;
    }

    /**
     * 生成标准 HTTP 限流响应头
     *
     * @return array{X-RateLimit-Limit: int, X-RateLimit-Remaining: int, X-RateLimit-Reset: int, Retry-After: int}
     */
    public function toHeaders(): array
    {
        $remaining = $this->remaining();
        $available = $this->availableAt();

        return [
            'X-RateLimit-Limit'     => $this->maxAttempts,
            'X-RateLimit-Remaining' => $remaining,
            'X-RateLimit-Reset'     => $available ?? time(),
            'Retry-After'           => $this->cooldown(),
        ];
    }

    /**
     * 清除当前 key 的计数
     */
    public function clear(): self
    {
        $this->ensureKey();
        $this->cache()->delete($this->buildCacheKey());
        return $this;
    }

    // ────────── RateLimitInterface ──────────

    /**
     * 尝试通过限流检查并消费（原子操作）
     *
     * @param string $key     限流主键
     * @param array  $options limit=int 最大次数, window=int 窗口秒数, weight=int 加权计数
     *  - limit: int 最大次数，默认 60
     *  - window: int 窗口秒数，默认 60
     *  - weight: int 加权计数，默认 1
     *
     * @return array{allowed: bool, remaining: int, limit: int}
     */
    public function attempt(string $key, array $options = []): array
    {
        // 保存原始 key，执行后恢复，避免污染实例状态
        $originalKey = $this->key;
        try {
            $this->by($key);

            $limit  = $options['limit']  ?? $this->maxAttempts;
            $window = $options['window'] ?? $this->decaySeconds;
            $weight = $options['weight'] ?? 1;

            // 不修改实例状态，避免污染后续 hit()/status()/getLimit() 调用
            $remaining = $this->hit($weight, $limit, $window);

            return [
                'allowed'   => $remaining > 0,
                'remaining' => $remaining,
                'limit'     => $limit,
            ];
        } finally {
            $this->key = $originalKey;
        }
    }

    /**
     * 查询当前状态（不消费）
     *
     * @param string $key 限流主键
     * @param array  $options 可选参数
     * @return array{current: int, limit: int, remaining: int, cooldown: int}
     */
    public function status(string $key, array $options = []): array
    {
        $this->by($key);
        return [
            'current'   => $this->attempts(),
            'limit'     => $this->maxAttempts,
            'remaining' => $this->remaining(),
            'cooldown'  => $this->cooldown(),
        ];
    }

    /**
     * 重置指定 key 的限流状态
     *
     * @param string $key 限流主键
     */
    public function reset(string $key): void
    {
        $this->by($key);
        $this->clear();
    }

    // ────────────────── 内部方法 ──────────────────

    /**
     * 构造缓存 key
     */
    protected function buildCacheKey(): string
    {
        return "{$this->prefix}:{$this->key}";
    }

    /**
     * 确保已设置 key
     *
     * @throws RuntimeException
     */
    protected function ensureKey(): void
    {
        if (empty($this->key)) {
            throw new RuntimeException('RateLimiter: 必须通过 by() 方法设置限流 key');
        }
    }

    /**
     * 获取缓存实例
     *
     * @return \think\Cache|\Psr\SimpleCache\CacheInterface
     */
    protected function cache()
    {
        if ($this->customCache !== null) {
            return $this->customCache;
        }
        return Cache::store($this->cacheStore);
    }
}
