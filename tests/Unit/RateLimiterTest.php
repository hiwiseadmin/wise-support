<?php

declare(strict_types=1);

namespace wise\support\test;

use PHPUnit\Framework\TestCase;
use wise\support\rate\RateLimiter;
use RuntimeException;

/**
 * 内存缓存适配器（用于测试，不依赖 ThinkPHP Cache）
 */
class ArrayCache
{
    /** @var array<string, mixed> */
    private array $data = [];
    /** @var array<string, int> */
    private array $expiry = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (isset($this->expiry[$key]) && $this->expiry[$key] < time()) {
            unset($this->data[$key], $this->expiry[$key]);
            return $default;
        }
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->data[$key] = $value;
        if ($ttl === null) {
            // 与真实驱动一致：未指定过期时间 = 永久，会清除既有 TTL。
            // 这条语义是「窗口永不重置」类缺陷能被测试捕获的前提。
            unset($this->expiry[$key]);
        } else {
            $this->expiry[$key] = time() + $ttl;
        }
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->data[$key], $this->expiry[$key]);
        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key, new \stdClass()) !== new \stdClass();
    }

    /**
     * 该键是否带过期时间（用于断言窗口 TTL 未被抹掉）
     */
    public function hasExpiry(string $key): bool
    {
        return isset($this->expiry[$key]);
    }
}

/**
 * RateLimiter 单元测试
 */
class RateLimiterTest extends TestCase
{
    private ArrayCache $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
    }

    /** @return RateLimiter */
    private function make(int $max = 3, int $seconds = 60, string $key = 'test'): RateLimiter
    {
        return RateLimiter::for($max, $seconds)->by($key)->setCache($this->cache);
    }

    // ==================== 工厂方法 ====================

    public function test_factory_attempts(): void
    {
        $rl = RateLimiter::for(5, 120);
        $this->assertInstanceOf(RateLimiter::class, $rl);
    }

    public function test_factory_per_minute(): void
    {
        $rl = RateLimiter::perMinute(30);
        $info = $rl->by('test')->setCache($this->cache)->getLimit();
        $this->assertEquals(30, $info['max']);
    }

    public function test_factory_per_minutes(): void
    {
        $rl = RateLimiter::perMinutes(5, 15);
        $info = $rl->by('test')->setCache($this->cache)->getLimit();
        $this->assertEquals(5, $info['max']);
    }

    public function test_factory_per_hour(): void
    {
        $rl = RateLimiter::perHour(100);
        $info = $rl->by('test')->setCache($this->cache)->getLimit();
        $this->assertEquals(100, $info['max']);
    }

    public function test_factory_per_hours(): void
    {
        $rl = RateLimiter::perHours(50, 2);
        $info = $rl->by('test')->setCache($this->cache)->getLimit();
        $this->assertEquals(50, $info['max']);
    }

    public function test_factory_per_day(): void
    {
        $rl = RateLimiter::perDay(1000);
        $info = $rl->by('test')->setCache($this->cache)->getLimit();
        $this->assertEquals(1000, $info['max']);
    }

    // ==================== 基本限流逻辑 ====================

    public function test_not_too_many_initially(): void
    {
        $rl = $this->make(3, 60, 'foo');
        $this->assertFalse($rl->tooManyAttempts());
    }

    public function test_hit_increments_count(): void
    {
        $rl = $this->make(3, 60, 'bar');
        $this->assertEquals(0, $rl->attempts());

        $rl->hit();
        $this->assertEquals(1, $rl->attempts());

        $rl->hit();
        $this->assertEquals(2, $rl->attempts());
    }

    public function test_hit_returns_remaining(): void
    {
        $rl = $this->make(3, 60, 'baz');
        $this->assertEquals(2, $rl->hit());
        $this->assertEquals(1, $rl->hit());
        $this->assertEquals(0, $rl->hit());
    }

    public function test_non_atomic_fallback_keeps_window_ttl(): void
    {
        // ArrayCache 不提供 inc() → 走非原子回退分支
        $rl = $this->make(5, 60, 'ttl');
        $key = 'rate_limit:ttl';

        $rl->hit();
        $this->assertTrue($this->cache->hasExpiry($key), '首次写入应带窗口 TTL');

        $rl->hit();
        // 修复前更新分支用 set($key, $hits)（不带 TTL），会把窗口抹掉 → 计数键永不过期
        $this->assertTrue($this->cache->hasExpiry($key), '更新写入也必须携带窗口 TTL');

        $this->assertEquals(2, $rl->attempts());
    }

    public function test_too_many_after_limit_reached(): void
    {
        $rl = $this->make(2, 60, 'qux');

        $rl->hit(); // 1
        $this->assertFalse($rl->tooManyAttempts());

        $rl->hit(); // 2
        $this->assertTrue($rl->tooManyAttempts());
    }

    public function test_remaining_counts_down(): void
    {
        $rl = $this->make(3, 60, 'rem');
        $this->assertEquals(3, $rl->remaining());

        $rl->hit();
        $this->assertEquals(2, $rl->remaining());

        $rl->hit();
        $this->assertEquals(1, $rl->remaining());

        $rl->hit();
        $this->assertEquals(0, $rl->remaining());

        // 超限后不会变成负数
        $rl->hit();
        $this->assertEquals(0, $rl->remaining());
    }

    // ==================== 清除与重置 ====================

    public function test_clear_resets_count(): void
    {
        $rl = $this->make(5, 60, 'clear-test');
        $rl->hit();
        $rl->hit();
        $rl->hit();
        $this->assertEquals(3, $rl->attempts());

        $rl->clear();
        $this->assertEquals(0, $rl->attempts());
        $this->assertFalse($rl->tooManyAttempts());
    }

    public function test_clear_is_chainable(): void
    {
        $rl = $this->make(5, 60, 'chain-clear');
        // hit() 返回本次命中的计数（int），clear() 才是链式返回 $this
        $rl->hit();

        $result = $rl->clear();

        $this->assertInstanceOf(RateLimiter::class, $result);
        $this->assertEquals(5, $result->remaining());
    }

    // ==================== Key 隔离 ====================

    public function test_different_keys_are_independent(): void
    {
        $rlA = $this->make(2, 60, 'key-a');
        $rlB = $this->make(2, 60, 'key-b');

        $rlA->hit();
        $rlA->hit();
        $this->assertTrue($rlA->tooManyAttempts());
        $this->assertFalse($rlB->tooManyAttempts());
    }

    public function test_same_key_shares_state(): void
    {
        $rl1 = $this->make(5, 60, 'shared');
        $rl2 = $this->make(10, 60, 'shared');

        $rl1->hit();
        $rl1->hit();

        // 不同配置，相同 key，共享底层缓存
        $this->assertEquals(2, $rl2->attempts());
    }

    // ==================== 无 key 时抛异常 ====================

    public function test_throws_without_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('必须通过 by() 方法设置限流 key');

        RateLimiter::perMinute(10)->setCache($this->cache)->tooManyAttempts();
    }

    public function test_hit_throws_without_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('必须通过 by() 方法设置限流 key');

        RateLimiter::perMinute(10)->setCache($this->cache)->hit();
    }

    // ==================== getLimit() ====================

    public function test_get_limit(): void
    {
        $rl = $this->make(5, 120, 'limit-info');
        $rl->hit();
        $rl->hit();

        $info = $rl->getLimit();
        $this->assertEquals(5, $info['max']);
        $this->assertEquals(3, $info['remaining']);
    }

    // ==================== 边界值 ====================

    public function test_max_attempts_of_one(): void
    {
        $rl = $this->make(1, 60, 'single');
        $this->assertFalse($rl->tooManyAttempts());

        $rl->hit();
        $this->assertTrue($rl->tooManyAttempts());
    }

    public function test_zero_initial_state(): void
    {
        $rl = $this->make(100, 60, 'zero-state');
        $this->assertEquals(0, $rl->attempts());
        $this->assertEquals(100, $rl->remaining());
    }

    // ==================== Conditionable 集成 ====================

    public function test_when_with_conditionable(): void
    {
        $rl = $this->make(1, 60, 'cond');
        $rl->hit();

        $blocked = false;
        $rl->when(
            fn (RateLimiter $r) => $r->tooManyAttempts(),
            function () use (&$blocked) { $blocked = true; }
        );

        $this->assertTrue($blocked);
    }

    // ==================== Tappable 集成 ====================

    public function test_tap_returns_self(): void
    {
        $rl = $this->make(5, 60, 'tap-test');
        $tapped = false;

        $result = $rl->tap(function (RateLimiter $r) use (&$tapped) {
            $tapped = true;
            $this->assertInstanceOf(RateLimiter::class, $r);
        });

        $this->assertTrue($tapped);
        $this->assertSame($rl, $result);
    }

    // ==================== withPrefix 自定义前缀 ====================

    public function test_custom_prefix_isolation(): void
    {
        $rl1 = $this->make(5, 60, 'pref-test');
        $rl1->hit();
        $this->assertEquals(1, $rl1->attempts());

        // 不同前缀，相同 key → 隔离
        $rl2 = RateLimiter::for(5, 60)
            ->by('pref-test')
            ->withPrefix('custom_prefix')
            ->setCache($this->cache);
        $this->assertEquals(0, $rl2->attempts());
    }

    // ==================== 全局辅助函数 ====================

    public function test_helper_function(): void
    {
        $this->assertTrue(function_exists('rate_limiter'));

        $rl = rate_limiter(10, 300);
        $this->assertInstanceOf(RateLimiter::class, $rl);
    }
}
