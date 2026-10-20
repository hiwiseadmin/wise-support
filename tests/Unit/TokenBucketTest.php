<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use think\Container;
use wise\support\rate\RateLimiter;
use wise\support\rate\TokenBucket;

require_once __DIR__ . '/_rate_fixtures.php';

/**
 * 令牌桶限流算法 TokenBucket 单元测试
 */
class TokenBucketTest extends TestCase
{
    private string|false $originalErrorLog;

    protected function setUp(): void
    {
        Container::getInstance()->bind('cache', new RateUnavailableCacheStub());
        $this->originalErrorLog = ini_get('error_log');
        ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise_rate_error.log');
    }

    protected function tearDown(): void
    {
        Container::getInstance()->delete('cache');
        if ($this->originalErrorLog !== false) {
            ini_set('error_log', $this->originalErrorLog);
        }
    }

    private function fallbackLimiter(int $capacity, RateArrayCache $cache): RateLimiter
    {
        return RateLimiter::for($capacity, 60)->by('k')->setCache($cache);
    }

    // ============================================================
    // attempt() — Redis 不可用
    // ============================================================

    public function test_attempt_fails_closed_by_default_without_redis(): void
    {
        // 无降级限流器 + Redis 不可用：默认拒绝
        $result = (new TokenBucket())->attempt('api:search');

        $this->assertSame(['allowed' => false, 'remaining' => 0.0, 'retry_after_ms' => 60000], $result);
    }

    public function test_attempt_fail_opens_when_opted_in(): void
    {
        $result = (new TokenBucket())->failOpen()->attempt('api:search');

        $this->assertSame(['allowed' => true, 'remaining' => 60.0, 'retry_after_ms' => 0], $result);
    }

    public function test_attempt_fail_opens_with_custom_capacity_when_opted_in(): void
    {
        $result = (new TokenBucket())->failOpen()->attempt('api:search', [
            'capacity' => 10,
            'rate'     => 2.0,
            'consumed' => 3,
            'burst'    => 5,
        ]);

        $this->assertTrue($result['allowed']);
        $this->assertSame(10.0, $result['remaining']);
        $this->assertSame(0, $result['retry_after_ms']);
    }

    public function test_fail_open_is_chainable(): void
    {
        $bucket = new TokenBucket();

        $this->assertSame($bucket, $bucket->failOpen());
    }

    public function test_attempt_fail_closed_when_requested(): void
    {
        $result = (new TokenBucket())->attempt('api:search', [
            'capacity'    => 4,
            'fail_closed' => true,
        ]);

        $this->assertSame(['allowed' => false, 'remaining' => 0.0, 'retry_after_ms' => 60000], $result);
    }

    // ============================================================
    // attempt() — 降级到 RateLimiter
    // ============================================================

    public function test_attempt_delegates_to_fallback_limiter_with_weight(): void
    {
        $cache   = new RateArrayCache();
        $limiter = $this->fallbackLimiter(5, $cache);
        $bucket  = (new TokenBucket())->setFallbackLimiter($limiter);

        $result = $bucket->attempt('k', ['capacity' => 5, 'consumed' => 2]);

        $this->assertSame(['allowed' => true, 'remaining' => 3.0, 'retry_after_ms' => 0], $result);
        $this->assertSame(2, $cache->get('rate_limit:k'), 'consumed 应作为加权计数传给降级限流器');
    }

    public function test_fallback_denies_when_tokens_exhausted(): void
    {
        $cache   = new RateArrayCache();
        $limiter = $this->fallbackLimiter(3, $cache);
        $bucket  = (new TokenBucket())->setFallbackLimiter($limiter);

        $result = $bucket->attempt('k', ['capacity' => 3, 'consumed' => 3]);

        $this->assertFalse($result['allowed']);
        $this->assertSame(0.0, $result['remaining']);
        $this->assertSame(60000, $result['retry_after_ms']);
    }

    public function test_consumed_below_one_is_clamped_to_one(): void
    {
        $cache   = new RateArrayCache();
        $limiter = $this->fallbackLimiter(5, $cache);
        $bucket  = (new TokenBucket())->setFallbackLimiter($limiter);

        $result = $bucket->attempt('k', ['capacity' => 5, 'consumed' => 0]);

        $this->assertSame(4.0, $result['remaining']);
        $this->assertSame(1, $cache->get('rate_limit:k'));
    }

    public function test_burst_smaller_than_capacity_is_clamped(): void
    {
        $cache   = new RateArrayCache();
        $limiter = $this->fallbackLimiter(5, $cache);
        $bucket  = (new TokenBucket())->setFallbackLimiter($limiter);

        $result = $bucket->attempt('k', ['capacity' => 5, 'burst' => 1]);

        $this->assertTrue($result['allowed']);
        $this->assertSame(4.0, $result['remaining']);
    }

    public function test_broken_redis_connection_degrades_gracefully(): void
    {
        if (!class_exists('\Redis')) {
            $this->markTestSkipped('未安装 ext-redis');
        }

        // 连接到必然不可达的端口：instanceof 判定通过，eval 抛 RedisException
        $redis = new \Redis();
        try {
            $redis->connect('127.0.0.1', 1, 0.05);
        } catch (\Throwable $e) {
            // 预期失败
        }

        Container::getInstance()->bind('cache', new RateRedisCacheStub($redis));
        $bucket = new TokenBucket();

        // eval 抛异常（连接失效）→ 默认 fail-closed
        $result = $bucket->attempt('k', ['capacity' => 5]);
        $this->assertFalse($result['allowed'], 'eval 失败时必须按实例策略默认拒绝');
        $this->assertSame(0.0, $result['remaining']);

        // 显式 failOpen 的实例才放行
        $this->assertTrue(
            (new TokenBucket())->failOpen()->attempt('k', ['capacity' => 5])['allowed']
        );

        $this->assertSame(5.0, $bucket->status('k', ['capacity' => 5])['current']);
        $bucket->reset('k');
        $this->assertTrue(true);
    }

    public function test_set_fallback_limiter_is_chainable(): void
    {
        $bucket = new TokenBucket();
        $limiter = $this->fallbackLimiter(5, new RateArrayCache());

        $this->assertSame($bucket, $bucket->setFallbackLimiter($limiter));
    }

    // ============================================================
    // status()
    // ============================================================

    public function test_status_without_redis_returns_full_capacity(): void
    {
        $result = (new TokenBucket())->status('k', ['capacity' => 20, 'rate' => 0.5]);

        $this->assertSame(['current' => 20.0, 'capacity' => 20, 'rate' => 0.5], $result);
    }

    public function test_status_defaults(): void
    {
        $result = (new TokenBucket())->status('k');

        $this->assertSame(60.0, $result['current']);
        $this->assertSame(60, $result['capacity']);
        $this->assertSame(1.0, $result['rate']);
    }

    // ============================================================
    // reset()
    // ============================================================

    public function test_reset_clears_fallback_state(): void
    {
        $cache   = new RateArrayCache();
        $limiter = $this->fallbackLimiter(5, $cache);
        $bucket  = (new TokenBucket())->setFallbackLimiter($limiter);

        $bucket->attempt('k', ['capacity' => 5, 'consumed' => 1]);
        $this->assertSame(1, $cache->get('rate_limit:k'));

        $bucket->reset('k');

        $this->assertNull($cache->get('rate_limit:k'));
    }

    public function test_reset_without_redis_is_noop(): void
    {
        (new TokenBucket())->reset('k');

        $this->assertTrue(true);
    }

    public function test_lua_scripts_are_defined(): void
    {
        $this->assertStringContainsString('tokens', TokenBucket::LUA_SCRIPT);
        $this->assertStringContainsString('tokens', TokenBucket::LUA_STATUS);
    }
}
