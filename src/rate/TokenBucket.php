<?php

declare(strict_types=1);

namespace wise\support\rate;

use wise\support\rate\traits\RedisHelper;

/**
 * 令牌桶限流算法
 *
 * 通过 Redis Lua 脚本实现原子性令牌补充与消费。
 *
 * 特性：
 * - 平滑突发（burst > capacity 时允许短时间爆发）
 * - 多令牌消费（consume 模式，一次消耗 N 个令牌）
 * - Redis 不可用时：优先降级限流器；无降级器时**默认拒绝**（fail-closed），
 *   可用性优先的场景在构造期显式 ->failOpen()
 * - 支持降级到 RateLimiter（文件缓存）
 *
 * @package wise\support\rate
 */
class TokenBucket implements RateLimitInterface
{
    use RedisHelper;

    /** @var RateLimiter|null 降级限流器 */
    protected ?RateLimiter $fallbackLimiter = null;

    /**
     * Redis 不可用且无降级限流器时是否拒绝放行（fail-closed）
     *
     * 默认 true：「判定不了配额」不得被默认解释为「允许」。
     */
    protected bool $failClosed = true;

    // ── Lua 脚本 ──

    /**
     * 原子令牌桶（支持突发容量）
     *
     * ARGV[6] = burst：初始令牌数（≥ capacity 时启用突发，首次请求多出 burst-capacity 个令牌）。
     * 填充时上限仍为 capacity（稳态速率受限），仅首轮从 burst 起步。
     *
     * burst == capacity → 行为等同于标准令牌桶。
     */
    public const LUA_SCRIPT = <<<'LUA'
local capacity = tonumber(ARGV[1])
local rate     = tonumber(ARGV[2])
local now      = tonumber(ARGV[3])
local ttl      = tonumber(ARGV[4])
local consume  = tonumber(ARGV[5] or 1)
local burst    = tonumber(ARGV[6] or capacity)

local last_refill = tonumber(redis.call('GET', KEYS[1]) or now)
local tokens      = tonumber(redis.call('GET', KEYS[2]) or burst)

-- 补充令牌：上限为 capacity（稳态限制）
local delta = math.max(0, now - last_refill) * rate
tokens = math.min(capacity, tokens + delta)

local allowed = 0
local retry_after = 0
if tokens >= consume then
    tokens = tokens - consume
    allowed = 1
else
    local need = consume - tokens
    retry_after = math.ceil((need / rate) * 1000)
end

redis.call('SET', KEYS[1], now, 'EX', ttl)
redis.call('SET', KEYS[2], tokens, 'EX', ttl)

return {allowed, tostring(tokens), retry_after}
LUA;

    /**
     * 只读查询（status）
     */
    public const LUA_STATUS = <<<'LUA'
local capacity = tonumber(ARGV[1])
local rate     = tonumber(ARGV[2])
local now      = tonumber(ARGV[3])

local last_refill = tonumber(redis.call('GET', KEYS[1]) or now)
local tokens      = tonumber(redis.call('GET', KEYS[2]) or capacity)

local delta = math.max(0, now - last_refill) * rate
tokens = math.min(capacity, tokens + delta)

return tostring(tokens)
LUA;

    /**
     * 设置降级限流器（Redis 不可用时启用）
     */
    public function setFallbackLimiter(RateLimiter $limiter): self
    {
        $this->fallbackLimiter = $limiter;
        return $this;
    }

    /**
     * 选择「Redis 不可用时放行」（fail-open），默认是拒绝
     *
     * 仅用于可用性优先、放行代价可接受的入口；安全敏感入口保持 fail-closed。
     */
    public function failOpen(bool $failOpen = true): self
    {
        $this->failClosed = !$failOpen;
        return $this;
    }

    // ── attempt ──

    /**
     * 执行一次令牌桶限流检查（原子消费）
     *
     * @param string $key              限流主键（不含前缀）
     * @param array  $options          可选参数：
     *   - capacity: int   稳态桶容量，默认 60
     *   - rate:     float 每秒填充速率，默认 1.0
     *   - consumed: int   本次消费令牌数，默认 1（＞1 实现多令牌消费）
     *   - burst:    int   突发容量，默认=capacity（＞capacity 允许首轮爆发更多请求）
     * @return array{allowed: bool, remaining: float, retry_after_ms: int}
     */
    public function attempt(string $key, array $options = []): array
    {
        $capacity = $options['capacity'] ?? 60;
        $rate     = $options['rate']     ?? 1.0;
        $consumed = $options['consumed'] ?? 1;
        $burst    = $options['burst']    ?? $capacity;
        $burst    = max($capacity, $burst);
        return $this->doAttempt($key, $capacity, $rate, $consumed, $burst, $options);
    }

    /**
     * 核心实现
     */
    protected function doAttempt(
        string $key,
        int $capacity,
        float $rate,
        int $consumed,
        int $burst,
        array $options = []
    ): array {
        $consumed = max(1, $consumed);

        $now      = microtime(true);
        $ttl      = max(60, (int) ceil($capacity / max(0.001, $rate)) * 2);
        $lastKey  = "rate:tb:{$key}:last";
        $tokenKey = "rate:tb:{$key}:tokens";

        $redis = $this->getRedis();
        if ($redis === null) {
            return $this->handleRedisUnavailable($key, $capacity, $consumed, $options);
        }

        // 统一脚本，burst == capacity 时退化为标准令牌桶
        try {
            $result = $redis->eval(
                self::LUA_SCRIPT,
                [$lastKey, $tokenKey,
                 (string) $capacity, (string) $rate, (string) $now, (string) $ttl,
                 (string) $consumed, (string) $burst],
                2
            );
        } catch (\Throwable $e) {
            // 连接在取到 handler 后失效时 eval 会抛异常，须兜底以维持 fail-open 契约
            error_log('[RateLimiter] TokenBucket eval failed, fallback: ' . $e->getMessage());
            return $this->handleRedisUnavailable($key, $capacity, $consumed, $options);
        }

        if (!is_array($result) || count($result) < 3) {
            return $this->handleRedisUnavailable($key, $capacity, $consumed, $options);
        }

        return [
            'allowed'         => (int) $result[0] === 1,
            'remaining'       => (float) $result[1],
            'retry_after_ms'  => (int) $result[2],
        ];
    }

    // ── status ──

    /**
     * 查询当前令牌数（不消费）
     *
     * @param string $key     限流主键
     * @param array  $options 可选参数：capacity=int, rate=float
     * - capacity: int 稳态桶容量，默认 60
     * - rate:     float 每秒填充速率，默认 1.0
     * 
     * @return array{current: float, capacity: int, rate: float}
     */
    public function status(string $key, array $options = []): array
    {
        $capacity = $options['capacity'] ?? 60;
        $rate     = $options['rate']     ?? 1.0;
        $redis = $this->getRedis();
        if ($redis === null) {
            return ['current' => (float) $capacity, 'capacity' => $capacity, 'rate' => $rate];
        }

        $lastKey  = "rate:tb:{$key}:last";
        $tokenKey = "rate:tb:{$key}:tokens";
        $now      = microtime(true);

        try {
            $tokens = (float) $redis->eval(
                self::LUA_STATUS,
                [$lastKey, $tokenKey, (string) $capacity, (string) $rate, $now],
                2
            );
        } catch (\Throwable $e) {
            error_log('[RateLimiter] TokenBucket status eval failed, fallback: ' . $e->getMessage());
            $tokens = (float) $capacity;
        }

        return [
            'current'  => $tokens,
            'capacity' => $capacity,
            'rate'     => $rate,
        ];
    }

    // ── reset ──

    public function reset(string $key): void
    {
        $redis = $this->getRedis();
        if ($redis !== null) {
            try {
                $redis->del("rate:tb:{$key}:last", "rate:tb:{$key}:tokens");
            } catch (\Throwable $e) {
                // 清理失败不应影响后续 fallback 状态清理
                error_log('[RateLimiter] TokenBucket reset failed: ' . $e->getMessage());
            }
        }

        if ($this->fallbackLimiter) {
            $this->fallbackLimiter->by($key)->clear();
        }
    }

    // ── 内部 ──

    protected function handleRedisUnavailable(string $key, int $capacity, int $consumed, array $options = []): array
    {
        if ($this->fallbackLimiter) {
            $result = $this->fallbackLimiter->attempt($key, [
                'limit'  => $capacity,
                'window' => 60,
                'weight' => $consumed,
            ]);
            return [
                'allowed'       => $result['allowed'],
                'remaining'     => (float) $result['remaining'],
                'retry_after_ms'=> $result['allowed'] ? 0 : 60_000,
            ];
        }

        // 无降级限流器时：按实例策略决定，默认 fail-closed（拒绝）；
        // 可用性优先的入口在构造期显式 ->failOpen()。
        // 兼容旧调用：单次调用仍可传 fail_closed=true 强制拒绝。
        if ($this->failClosed || !empty($options['fail_closed'])) {
            return [
                'allowed'        => false,
                'remaining'      => 0.0,
                'retry_after_ms' => 60_000,
            ];
        }

        return [
            'allowed'        => true,
            'remaining'      => (float) $capacity,
            'retry_after_ms' => 0,
        ];
    }
}
