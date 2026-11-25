<?php
declare(strict_types=1);

/**
 * 限流相关单元测试的公共测试替身
 *
 * 该文件不以 Test.php 结尾，不会被 PHPUnit 当作测试文件扫描，
 * 仅供 SlidingWindowTest / TokenBucketTest 等显式 require_once 使用。
 */

namespace wise\support\test\Unit;

/**
 * Cache 门面替身：使 Cache::store('redis') 抛错，模拟 Redis 不可用
 */
class RateUnavailableCacheStub
{
    public function store(string $name): object
    {
        throw new \RuntimeException("cache store [{$name}] unavailable");
    }
}

/**
 * Cache 门面替身：store('redis') 返回自身，handler() 返回非 Redis 对象
 *
 * 用于覆盖 RedisHelper 中「handler 类型不支持」的降级分支。
 */
class RateNonRedisCacheStub
{
    public function store(string $name): object
    {
        return $this;
    }

    public function handler(): object
    {
        return new \stdClass();
    }
}

/**
 * Cache 门面替身：handler() 返回真实 \Redis 实例（未连接）
 *
 * 用于覆盖 RedisHelper 中「命中 Redis handler」的分支，
 * 以及未连接时 eval 抛异常后的 fail-open 兜底。
 */
class RateRedisCacheStub
{
    public function __construct(private object $handler)
    {
    }

    public function store(string $name): object
    {
        return $this;
    }

    public function handler(): object
    {
        return $this->handler;
    }
}

/**
 * 内存缓存替身：实现 RateLimiter 认为「具备原子能力」的 inc()/expire()
 */
class RateArrayCache
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->data[$key] = $value;
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function inc(string $key, int $step = 1): int
    {
        $this->data[$key] = (int) ($this->data[$key] ?? 0) + $step;
        return (int) $this->data[$key];
    }

    public function expire(string $key, int $ttl): bool
    {
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->data[$key]);
        return true;
    }

    public function getTtl(string $key): int
    {
        return 60;
    }
}
