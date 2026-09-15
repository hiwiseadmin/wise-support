<?php

declare(strict_types=1);

namespace wise\support\rate;

use wise\support\rate\traits\RedisHelper;

/**
 * 滑动窗口限流算法
 *
 * 通过 Redis Lua 脚本 + ZSET 实现原子滑动窗口。
 *
 * 特性：
 * - 精确时间窗口（非固定窗口重置）
 * - 支持自定义 member 实现请求去重
 * - Redis 不可用时 fail-open 放行
 * - 支持降级到 RateLimiter（文件缓存）
 *
 * @package wise\support\rate
 */
class SlidingWindow implements RateLimitInterface
{
    use RedisHelper;

    /** @var RateLimiter|null 降级限流器 */
    protected ?RateLimiter $fallbackLimiter = null;

    // ── Lua 脚本 ──

    /**
     * 原子滑动窗口（attempt）
     */
    public const LUA_ATTEMPT = <<<'LUA'
local now    = tonumber(ARGV[1])
local window = tonumber(ARGV[2])
local limit  = tonumber(ARGV[3])
local member = ARGV[4]
local ttl    = tonumber(ARGV[5])

local cutoff = now - window
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', cutoff)

local count = tonumber(redis.call('ZCARD', KEYS[1]))
local allowed = 0
if count < limit then
    redis.call('ZADD', KEYS[1], now, member)
    count = count + 1
    allowed = 1
end

redis.call('EXPIRE', KEYS[1], ttl)
return {allowed, count, limit - count}
LUA;

    /**
     * 只读查询（status）
     */
    public const LUA_STATUS = <<<'LUA'
local cutoff = tonumber(ARGV[1])
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', cutoff)
return redis.call('ZCARD', KEYS[1])
LUA;

    // ── 配置 ──

    /**
     * 设置降级限流器（Redis 不可用时启用）
     */
    public function setFallbackLimiter(RateLimiter $limiter): self
    {
        $this->fallbackLimiter = $limiter;
        return $this;
    }

    // ── attempt ──

    /**
     * 执行一次滑动窗口限流检查（原子消费）
     *
     * @param string $key     限流主键（不含前缀）
     * @param array  $options 可选参数：
     *   - limit:  int    窗口内最大请求数，默认 60
     *   - window: int    窗口大小秒数，默认 60
     *   - member: string|null 幂等 key（去重），默认自动生成
     * @return array{allowed: bool, current: int, remaining: int}
     */
    public function attempt(string $key, array $options = []): array
    {
        $limit  = $options['limit']  ?? 60;
        $window = $options['window'] ?? 60;
        $member = $options['member'] ?? null;
        $now    = microtime(true);
        $ttl    = $window + 1;
        $member = $member ?? ($now . ':' . bin2hex(random_bytes(8)));
        $zKey   = "rate:sw:{$key}";

        $redis = $this->getRedis();
        if ($redis === null) {
            return $this->handleRedisUnavailable($key, $limit, $window, $options);
        }

        $result = $redis->eval(
            self::LUA_ATTEMPT,
            [$zKey, (string) $now, (string) $window, (string) $limit, $member, (string) $ttl],
            1
        );

        if (!is_array($result) || count($result) < 3) {
            return $this->handleRedisUnavailable($key, $limit, $window, $options);
        }

        return [
            'allowed'   => (int) $result[0] === 1,
            'current'   => (int) $result[1],
            'remaining' => (int) $result[2],
        ];
    }

    // ── status ──

    /**
     * 查询当前窗口状态（不消费）
     *
     * @param string $key     限流主键
     * @param array  $options 可选参数：window=int 窗口大小秒数，默认 60
     *  - window: int 窗口大小秒数，默认 60
     * 
     * @return array{current: int, window: int}
     */
    public function status(string $key, array $options = []): array
    {
        $window = $options['window'] ?? 60;
        $redis = $this->getRedis();
        if ($redis === null) {
            return ['current' => 0, 'window' => $window];
        }

        $zKey   = "rate:sw:{$key}";
        $cutoff = microtime(true) - $window;
        $count  = (int) $redis->eval(self::LUA_STATUS, [$zKey, $cutoff], 1);

        return ['current' => $count, 'window' => $window];
    }

    /**
     * 带完整选项的查询（兼容 attempt 的参数风格）
     */
    public function statusWithLimit(string $key, int $limit = 60, int $window = 60): array
    {
        $current = $this->status($key, ['window' => $window])['current'];
        return [
            'current'   => $current,
            'limit'     => $limit,
            'remaining' => max(0, $limit - $current),
            'window'    => $window,
        ];
    }

    // ── reset ──

    public function reset(string $key): void
    {
        $redis = $this->getRedis();
        if ($redis !== null) {
            $zKey = "rate:sw:{$key}";
            $redis->del($zKey);
        }

        if ($this->fallbackLimiter) {
            $this->fallbackLimiter->by($key)->clear();
        }
    }

    // ── 内部 ──

    /**
     * Redis 不可用时的处理：优先降级，否则 fail-open；安全敏感场景可传
     * fail_closed=true 以在无降级限流器时拒绝放行。
     */
    protected function handleRedisUnavailable(string $key, int $limit, int $window, array $options = []): array
    {
        if ($this->fallbackLimiter) {
            $result = $this->fallbackLimiter->attempt($key, [
                'limit'  => $limit,
                'window' => $window,
            ]);
            return [
                'allowed'   => $result['allowed'],
                'current'   => $limit - $result['remaining'],
                'remaining' => $result['remaining'],
            ];
        }

        if (!empty($options['fail_closed'])) {
            return ['allowed' => false, 'current' => $limit, 'remaining' => 0];
        }

        return ['allowed' => true, 'current' => 0, 'remaining' => $limit];
    }
}
