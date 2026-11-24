<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use think\Container;
use wise\support\rate\RateLimiter;
use wise\support\rate\SlidingWindow;

require_once __DIR__ . '/_rate_fixtures.php';

/**
 * 滑动窗口限流算法 SlidingWindow 单元测试
 *
 * Redis 不可用时的降级/fail-open/fail-closed 行为是重点；
 * 降级限流器（RateLimiter + 内存缓存）用于验证算法语义不被破坏。
 */
class SlidingWindowTest extends TestCase
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

    private function fallbackLimiter(int $max, int $window, RateArrayCache $cache, string $key): RateLimiter
    {
        return RateLimiter::for($max, $window)->by($key)->setCache($cache);
    }


    public function test_attempt_fails_closed_by_default_without_redis(): void
    {
        $result = (new SlidingWindow())->attempt('api:login');

        $this->assertSame(['allowed' => false, 'current' => 60, 'remaining' => 0], $result);
    }

    public function test_attempt_fail_opens_when_opted_in(): void
    {
        $result = (new SlidingWindow())->failOpen()->attempt('api:login');

        $this->assertSame(['allowed' => true, 'current' => 0, 'remaining' => 60], $result);
    }

    public function test_attempt_fail_opens_with_custom_limit_and_window_when_opted_in(): void
    {
        $result = (new SlidingWindow())->failOpen()->attempt('api:login', ['limit' => 10, 'window' => 30]);

        $this->assertTrue($result['allowed']);
        $this->assertSame(0, $result['current']);
        $this->assertSame(10, $result['remaining']);
    }

    public function test_attempt_fails_closed_with_custom_limit_and_window(): void
    {
        $result = (new SlidingWindow())->attempt('api:login', ['limit' => 10, 'window' => 30]);

        $this->assertFalse($result['allowed']);
        $this->assertSame(10, $result['current']);
        $this->assertSame(0, $result['remaining']);
    }

    public function test_fail_open_is_chainable(): void
    {
        $window = new SlidingWindow();

        $this->assertSame($window, $window->failOpen());
    }

    public function test_attempt_fail_closed_when_requested(): void
    {
        $result = (new SlidingWindow())->attempt('api:login', [
            'limit'       => 7,
            'fail_closed' => true,
        ]);

        $this->assertSame(['allowed' => false, 'current' => 7, 'remaining' => 0], $result);
    }

    public function test_attempt_accepts_explicit_member(): void
    {
        $result = (new SlidingWindow())->failOpen()->attempt('api:login', ['limit' => 3, 'member' => 'req-1']);

        $this->assertTrue($result['allowed']);
    }


    public function test_attempt_delegates_to_fallback_limiter(): void
    {
        $cache = new RateArrayCache();
        $limiter = $this->fallbackLimiter(5, 60, $cache, 'k');
        $window = (new SlidingWindow())->setFallbackLimiter($limiter);

        $first = $window->attempt('k', ['limit' => 5, 'window' => 60]);

        $this->assertSame(['allowed' => true, 'current' => 1, 'remaining' => 4], $first);
        $this->assertSame(1, $cache->get('rate_limit:k'));
    }

    public function test_attempt_fallback_denies_after_limit_reached(): void
    {
        $cache   = new RateArrayCache();
        $limiter = $this->fallbackLimiter(2, 60, $cache, 'k');
        $window  = (new SlidingWindow())->setFallbackLimiter($limiter);

        $window->attempt('k', ['limit' => 2, 'window' => 60]);
        $window->attempt('k', ['limit' => 2, 'window' => 60]);
        $third = $window->attempt('k', ['limit' => 2, 'window' => 60]);

        $this->assertFalse($third['allowed']);
        $this->assertSame(2, $third['current']);
        $this->assertSame(0, $third['remaining']);
    }

    public function test_fail_closed_option_is_ignored_when_fallback_available(): void
    {
        $cache   = new RateArrayCache();
        $limiter = $this->fallbackLimiter(5, 60, $cache, 'k');
        $window  = (new SlidingWindow())->setFallbackLimiter($limiter);

        $result = $window->attempt('k', ['limit' => 5, 'fail_closed' => true]);

        $this->assertTrue($result['allowed']);
        $this->assertSame(4, $result['remaining']);
    }

    public function test_set_fallback_limiter_is_chainable(): void
    {
        $cache   = new RateArrayCache();
        $limiter = $this->fallbackLimiter(5, 60, $cache, 'k');
        $window  = new SlidingWindow();

        $this->assertSame($window, $window->setFallbackLimiter($limiter));
    }


    public function test_status_without_redis_returns_zero_count(): void
    {
        $result = (new SlidingWindow())->status('api:login', ['window' => 30]);

        $this->assertSame(['current' => 0, 'window' => 30], $result);
    }

    public function test_status_uses_default_window(): void
    {
        $this->assertSame(['current' => 0, 'window' => 60], (new SlidingWindow())->status('k'));
    }

    public function test_status_with_limit_computes_remaining(): void
    {
        $result = (new SlidingWindow())->statusWithLimit('k', 10, 30);

        $this->assertSame(['current' => 0, 'limit' => 10, 'remaining' => 10, 'window' => 30], $result);
    }


    public function test_reset_clears_fallback_limiter_state(): void
    {
        $cache   = new RateArrayCache();
        $limiter = $this->fallbackLimiter(5, 60, $cache, 'k');
        $window  = (new SlidingWindow())->setFallbackLimiter($limiter);

        $window->attempt('k', ['limit' => 5]);
        $this->assertSame(1, $cache->get('rate_limit:k'));

        $window->reset('k');

        $this->assertNull($cache->get('rate_limit:k'));
    }

    public function test_reset_without_redis_and_fallback_is_noop(): void
    {
        (new SlidingWindow())->reset('k');

        $this->assertTrue(true);
    }


    public function test_unsupported_handler_type_falls_back_and_logs(): void
    {
        Container::getInstance()->bind('cache', new RateNonRedisCacheStub());
        $logFile = ini_get('error_log');
        @unlink($logFile);

        $result = (new SlidingWindow())->attempt('k', ['limit' => 5]);

        $this->assertFalse($result['allowed'], 'handler 类型不支持时按实例策略默认 fail-closed');
        $this->assertSame(0, $result['remaining']);
        $this->assertStringContainsString(
            'Redis handler type not supported',
            (string) @file_get_contents($logFile)
        );

        $this->assertTrue((new SlidingWindow())->failOpen()->attempt('k', ['limit' => 5])['allowed']);
    }

    public function test_redis_handler_is_used_and_eval_failure_falls_back(): void
    {
        if (!class_exists('\Redis')) {
            $this->markTestSkipped('未安装 ext-redis');
        }

        $redis = new \Redis();
        try {
            $redis->connect('127.0.0.1', 1, 0.05);
        } catch (\Throwable $e) {
        }

        Container::getInstance()->bind('cache', new RateRedisCacheStub($redis));

        $window = new SlidingWindow();

        $result = $window->attempt('k', ['limit' => 5]);
        $this->assertFalse($result['allowed'], 'eval 失败时必须按实例策略默认拒绝');
        $this->assertSame(0, $result['remaining']);
        $this->assertSame(5, $result['current']);

        $this->assertTrue(
            (new SlidingWindow())->failOpen()->attempt('k', ['limit' => 5])['allowed']
        );

        $this->assertSame(['current' => 0, 'window' => 30], $window->status('k', ['window' => 30]));
        $window->reset('k');
        $this->assertTrue(true);
    }


    public function test_lua_scripts_are_defined(): void
    {
        $this->assertStringContainsString('ZREMRANGEBYSCORE', SlidingWindow::LUA_ATTEMPT);
        $this->assertStringContainsString('ZCARD', SlidingWindow::LUA_STATUS);
    }
}
