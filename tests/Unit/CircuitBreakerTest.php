<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\circuit\CircuitBreaker;

/**
 * CircuitBreaker 断路器单元测试
 *
 * 使用内存数组模拟缓存存储，验证三态转换逻辑。
 */
class CircuitBreakerTest extends TestCase
{
    private array $store = [];

    protected function setUp(): void
    {
        $this->store = [];
    }

    // ============================================================
    // 初始状态
    // ============================================================

    public function test_initial_state_is_closed(): void
    {
        $cb = $this->makeBreaker();

        $this->assertEquals(CircuitBreaker::STATE_CLOSED, $cb->getState());
        $this->assertTrue($cb->isAvailable());
        $this->assertEquals(0, $cb->getFailureCount());
    }

    // ============================================================
    // CLOSED → 累积失败 → OPEN
    // ============================================================

    public function test_failures_below_threshold_keeps_closed(): void
    {
        $cb = $this->makeBreaker(5, 30);

        // 4 failures — still under threshold
        for ($i = 0; $i < 4; $i++) {
            $cb->reportFailure();
        }

        $this->assertEquals(CircuitBreaker::STATE_CLOSED, $cb->getState());
        $this->assertEquals(4, $cb->getFailureCount());
    }

    public function test_failures_reach_threshold_opens_circuit(): void
    {
        $cb = $this->makeBreaker(3, 30);

        for ($i = 0; $i < 3; $i++) {
            $cb->reportFailure();
        }

        $this->assertEquals(CircuitBreaker::STATE_OPEN, $cb->getState());
    }

    // ============================================================
    // CLOSED 下成功 → 重置失败计数
    // ============================================================

    public function test_success_in_closed_resets_failures(): void
    {
        $cb = $this->makeBreaker(5, 30);

        $cb->reportFailure();
        $cb->reportFailure();
        $cb->reportSuccess();

        $this->assertEquals(0, $cb->getFailureCount());
    }

    // ============================================================
    // OPEN → HALF_OPEN (冷却时间到)
    // ============================================================

    public function test_open_state_blocks_requests(): void
    {
        $cb = $this->makeBreaker(1, 30);
        $cb->reportFailure(); // opens immediately

        $this->assertEquals(CircuitBreaker::STATE_OPEN, $cb->getState());
        $this->assertFalse($cb->isAvailable());
    }

    public function test_open_transitions_to_half_open_after_timeout(): void
    {
        $cb = $this->makeBreaker(1, 1); // 1 second timeout

        $cb->reportFailure(); // opens
        $this->assertEquals(CircuitBreaker::STATE_OPEN, $cb->getState());

        // Fake: set opened_at to 2 seconds ago
        $this->store['cb:test_service:opened_at'] = time() - 2;

        $this->assertTrue($cb->isAvailable());
        $this->assertEquals(CircuitBreaker::STATE_HALF_OPEN, $cb->getState());
    }

    // ============================================================
    // HALF_OPEN → CLOSED (探测成功)
    // ============================================================

    public function test_half_open_success_threshold_closes_circuit(): void
    {
        $cb = $this->makeBreaker(1, 1, 5, 2); // successThreshold=2

        // Open circuit
        $cb->reportFailure();
        // Manually fake timeout
        $this->store['cb:test_service:opened_at'] = time() - 2;

        // First probe
        $cb->isAvailable();
        $cb->reportSuccess();
        $this->assertEquals(CircuitBreaker::STATE_HALF_OPEN, $cb->getState());

        // Second probe — reaches threshold
        $cb->isAvailable();
        $cb->reportSuccess();
        $this->assertEquals(CircuitBreaker::STATE_CLOSED, $cb->getState());
    }

    // ============================================================
    // HALF_OPEN → OPEN (探测失败)
    // ============================================================

    public function test_half_open_failure_reopens_circuit(): void
    {
        $cb = $this->makeBreaker(1, 1);

        // Open → HalfOpen
        $cb->reportFailure();
        $this->store['cb:test_service:opened_at'] = time() - 2;
        $cb->isAvailable();
        $this->assertEquals(CircuitBreaker::STATE_HALF_OPEN, $cb->getState());

        // Fail in half-open
        $cb->reportFailure();
        $this->assertEquals(CircuitBreaker::STATE_OPEN, $cb->getState());
    }

    // ============================================================
    // Half open max requests
    // ============================================================

    public function test_half_open_max_probe_requests(): void
    {
        $cb = $this->makeBreaker(1, 1, 1); // halfOpenMaxRequests=1

        // Open → HalfOpen
        $cb->reportFailure();
        $this->store['cb:test_service:opened_at'] = time() - 2;

        // First probe allowed
        $this->assertTrue($cb->isAvailable());
        // Second probe denied
        $this->assertFalse($cb->isAvailable());
    }

    // ============================================================
    // reset
    // ============================================================

    public function test_reset_returns_to_closed(): void
    {
        $cb = $this->makeBreaker(1, 30);

        $cb->reportFailure(); // opens
        $this->assertEquals(CircuitBreaker::STATE_OPEN, $cb->getState());

        $cb->reset();
        $this->assertEquals(CircuitBreaker::STATE_CLOSED, $cb->getState());
    }

    public function test_reset_clears_failures(): void
    {
        $cb = $this->makeBreaker(5, 30);

        for ($i = 0; $i < 3; $i++) {
            $cb->reportFailure();
        }
        $cb->reset();

        $this->assertEquals(0, $cb->getFailureCount());
    }

    // ============================================================
    // key prefixes
    // ============================================================

    public function test_default_key_prefix(): void
    {
        $cb = $this->makeBreaker();
        $this->assertEquals('cb', $this->getKeyPrefix($cb));
    }

    public function test_custom_key_prefix_via_options(): void
    {
        $cb = new CircuitBreaker('svc', $this->makeCache(), [
            'key_prefix' => 'wise_api:myapp:cb',
        ]);

        // Should not throw — key format is valid
        $this->assertEquals(CircuitBreaker::STATE_CLOSED, $cb->getState());
    }

    public function test_set_key_prefix(): void
    {
        $cb = $this->makeBreaker();
        $cb->setKeyPrefix('custom:prefix');

        // Should work with custom prefix
        $this->store['custom:prefix:test_service:state'] = CircuitBreaker::STATE_CLOSED;
        $this->assertEquals(CircuitBreaker::STATE_CLOSED, $cb->getState());
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function makeBreaker(int $failureThreshold = 5, int $timeout = 30, int $halfOpenMax = 5, int $successThreshold = 2): CircuitBreaker
    {
        return new CircuitBreaker('test_service', $this->makeCache(), [
            'failure_threshold'     => $failureThreshold,
            'timeout'               => $timeout,
            'half_open_max_requests' => $halfOpenMax,
            'success_threshold'      => $successThreshold,
        ]);
    }

    private function makeCache(): object
    {
        $store = &$this->store;
        return new class($store) {
            private array $store;
            public function __construct(array &$store) { $this->store = &$store; }
            public function get(string $key, $default = null): mixed { return $this->store[$key] ?? $default; }
            public function set(string $key, $value, $ttl = null): void { $this->store[$key] = $value; }
            public function inc(string $key, int $by = 1): int {
                if (!isset($this->store[$key])) { $this->store[$key] = 0; }
                $this->store[$key] += $by;
                return $this->store[$key];
            }
            public function has(string $key): bool { return isset($this->store[$key]); }
            public function delete(string $key): void { unset($this->store[$key]); }
        };
    }

    private function getKeyPrefix(CircuitBreaker $cb): string
    {
        $ref = new \ReflectionClass($cb);
        $prop = $ref->getProperty('keyPrefix');
        $prop->setAccessible(true);
        return $prop->getValue($cb);
    }
}
