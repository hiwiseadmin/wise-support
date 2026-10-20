<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use think\Container;
use wise\support\rate\AtomicCounter;

/**
 * 原子递增计数器 AtomicCounter 单元测试
 *
 * 覆盖三层降级：Redis Lua → Cache::inc() → 非原子兜底，
 * 以及日志告警（Log 门面需绑定替身，否则真实日志驱动不可用会抛异常）。
 */
class AtomicCounterTest extends TestCase
{
    private CounterCacheStub $cache;
    private CounterLogStub $log;

    protected function setUp(): void
    {
        $this->cache = new CounterCacheStub();
        $this->log   = new CounterLogStub();

        Container::getInstance()->bind('cache', $this->cache);
        Container::getInstance()->bind('log', $this->log);
    }

    protected function tearDown(): void
    {
        Container::getInstance()->delete('cache');
        Container::getInstance()->delete('log');
    }

    // ============================================================
    // L2：Cache::inc() 驱动级原子递增
    // ============================================================

    public function test_first_increment_sets_expire_sentinel(): void
    {
        $this->cache->incReturn = 1;

        $value = AtomicCounter::increment('order:count', 60);

        $this->assertSame(1, $value);
        $this->assertSame(1, $this->cache->data['order:count']);
        $this->assertSame(1, $this->cache->data['order:count:expire_set']);
        $this->assertContains(['set', 'order:count', 1, 60], $this->cache->calls);
    }

    public function test_non_first_increment_does_not_touch_sentinel(): void
    {
        $this->cache->incReturn = 2;

        $value = AtomicCounter::increment('order:count', 60);

        $this->assertSame(2, $value);
        $this->assertArrayNotHasKey('order:count:expire_set', $this->cache->data);
        $this->assertSame([], $this->log->records);
    }

    public function test_existing_sentinel_is_not_overwritten(): void
    {
        $this->cache->incReturn = 1;
        $this->cache->data['order:count:expire_set'] = 1;

        AtomicCounter::increment('order:count', 60);

        $this->assertSame(1, $this->cache->data['order:count:expire_set']);
    }

    // ============================================================
    // L3：非原子兜底
    // ============================================================

    public function test_inc_exception_falls_back_to_non_atomic(): void
    {
        $this->cache->throwOnInc = true;
        $this->cache->data['order:count'] = 7;

        $value = AtomicCounter::increment('order:count', 60, 'MyContext');

        $this->assertSame(8, $value);
        $this->assertSame(8, $this->cache->data['order:count']);
        $this->assertStringContainsString('Cache atomicIncrement failed', $this->log->records[0]);
        $this->assertStringContainsString('[MyContext]', $this->log->records[0]);
    }

    public function test_inc_returning_false_falls_back_to_non_atomic(): void
    {
        $this->cache->incReturn = false;
        $this->cache->data['order:count'] = 3;

        $value = AtomicCounter::increment('order:count', 60);

        $this->assertSame(4, $value);
        $this->assertStringContainsString('Cache::inc() returned false', $this->log->records[0]);
    }

    public function test_non_atomic_fallback_defaults_missing_key_to_zero(): void
    {
        $this->cache->throwOnInc = true;

        $value = AtomicCounter::increment('fresh:key', 30);

        $this->assertSame(1, $value);
        $this->assertContains(['set', 'fresh:key', 1, 30], $this->cache->calls);
    }

    public function test_default_context_is_used_when_omitted(): void
    {
        $this->cache->throwOnInc = true;

        AtomicCounter::increment('k', 60);

        $this->assertStringContainsString('[AtomicCounter]', $this->log->records[0]);
    }

    // ============================================================
    // L1：Redis Lua 分支
    // ============================================================

    public function test_redis_handler_path_falls_back_when_lua_fails(): void
    {
        if (!class_exists('\Redis')) {
            $this->markTestSkipped('未安装 ext-redis，跳过 L1 分支测试');
        }

        // 连接一个必然不可达的端口，使 eval 抛 RedisException
        $redis = new \Redis();
        try {
            $redis->connect('127.0.0.1', 1, 0.05);
        } catch (\Throwable $e) {
            // 预期失败
        }

        $this->cache->handler   = $redis;
        $this->cache->incReturn = 5;

        $value = AtomicCounter::increment('order:count', 60, 'RedisCtx');

        $this->assertSame(5, $value);
        $this->assertStringContainsString('Redis atomicIncrement failed', $this->log->records[0]);
        $this->assertStringContainsString('[RedisCtx]', $this->log->records[0]);
    }

    public function test_l1_lua_increment_when_redis_available(): void
    {
        if (!class_exists('\Redis')) {
            $this->markTestSkipped('未安装 ext-redis');
        }

        $redis = new \Redis();
        try {
            if (!$redis->connect('127.0.0.1', 6379, 0.2)) {
                $this->markTestSkipped('本机 Redis 不可用');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('本机 Redis 不可用: ' . $e->getMessage());
        }

        $key = 'wise:test:atomic_counter';
        $redis->del($key);
        $this->cache->handler = $redis;

        $first  = AtomicCounter::increment($key, 30);
        $second = AtomicCounter::increment($key, 30);

        $this->assertSame(1, $first);
        $this->assertSame(2, $second);
        $this->assertGreaterThan(0, $redis->ttl($key), '首次递增应设置 TTL');
        $this->assertSame([], $this->log->records, 'L1 成功时不应记录降级告警');
        $this->assertNotContains('inc', array_column($this->cache->calls, 0), 'L1 成功时不应回退到 Cache::inc');

        $redis->del($key);
    }

    public function test_l1_is_skipped_for_non_redis_handler(): void
    {
        $this->cache->handler = new \stdClass();
        $this->cache->incReturn = 1;

        AtomicCounter::increment('order:count', 60);

        // 未走 Redis：不应出现 eval 相关日志
        $this->assertSame([], $this->log->records);
    }
}

// ============================================================
// 测试替身
// ============================================================

class CounterCacheStub
{
    /** @var array<string, mixed> */
    public array $data = [];
    /** @var array<int, array<int, mixed>> */
    public array $calls = [];
    public mixed $incReturn = 1;
    public bool $throwOnInc = false;
    public object $handler;

    public function __construct()
    {
        $this->handler = new \stdClass();
    }

    public function store(string $name): object
    {
        $this->calls[] = ['store', $name];
        return $this;
    }

    public function handler(): object
    {
        return $this->handler;
    }

    public function inc(string $key, int $step = 1): mixed
    {
        $this->calls[] = ['inc', $key, $step];
        if ($this->throwOnInc) {
            throw new \RuntimeException('inc not supported');
        }
        return $this->incReturn;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->calls[] = ['get', $key];
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->calls[] = ['set', $key, $value, $ttl];
        $this->data[$key] = $value;
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }
}

class CounterLogStub
{
    /** @var string[] */
    public array $records = [];

    public function warning(string $message): void
    {
        $this->records[] = $message;
    }

    public function error(string $message): void
    {
        $this->records[] = $message;
    }

    public function record(string $message, string $level = 'info'): void
    {
        $this->records[] = $message;
    }

    public function info(string $message): void
    {
        $this->records[] = $message;
    }

    public function log(string $level, string $message): void
    {
        $this->records[] = $message;
    }
}
