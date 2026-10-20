<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\backoff\BackoffStrategy;

/**
 * BackoffStrategy 退避策略单元测试
 */
class BackoffStrategyTest extends TestCase
{
    // ============================================================
    // Linear 线性退避
    // ============================================================

    public function test_linear_factory_default(): void
    {
        $strategy = BackoffStrategy::linear();

        $this->assertEquals(1000, $strategy->delayForAttempt(1));
        $this->assertEquals(2000, $strategy->delayForAttempt(2));
        $this->assertEquals(3000, $strategy->delayForAttempt(3));
    }

    public function test_linear_custom_step(): void
    {
        $strategy = BackoffStrategy::linear(500);

        $this->assertEquals(500, $strategy->delayForAttempt(1));
        $this->assertEquals(1000, $strategy->delayForAttempt(2));
        $this->assertEquals(1500, $strategy->delayForAttempt(3));
    }

    public function test_linear_attempt_zero_returns_zero(): void
    {
        $strategy = BackoffStrategy::linear(1000);
        $this->assertEquals(0, $strategy->delayForAttempt(0));
    }

    // ============================================================
    // Exponential 指数退避
    // ============================================================

    public function test_exponential_factory_default(): void
    {
        $strategy = BackoffStrategy::exponential();

        $this->assertEquals(1000, $strategy->delayForAttempt(1));  // 1 * 2^0
        $this->assertEquals(2000, $strategy->delayForAttempt(2));  // 1 * 2^1
        $this->assertEquals(4000, $strategy->delayForAttempt(3));  // 1 * 2^2
        $this->assertEquals(8000, $strategy->delayForAttempt(4));  // 1 * 2^3
    }

    public function test_exponential_custom_base(): void
    {
        $strategy = BackoffStrategy::exponential(500);

        $this->assertEquals(500, $strategy->delayForAttempt(1));
        $this->assertEquals(1000, $strategy->delayForAttempt(2));
        $this->assertEquals(2000, $strategy->delayForAttempt(3));
    }

    public function test_exponential_max_cap(): void
    {
        $strategy = BackoffStrategy::exponential(1000, 5000);

        $this->assertEquals(1000, $strategy->delayForAttempt(1));
        $this->assertEquals(2000, $strategy->delayForAttempt(2));
        $this->assertEquals(4000, $strategy->delayForAttempt(3));
        $this->assertEquals(5000, $strategy->delayForAttempt(4)); // capped
        $this->assertEquals(5000, $strategy->delayForAttempt(5)); // capped
    }

    // ============================================================
    // Fixed 固定延迟
    // ============================================================

    public function test_fixed_factory_default(): void
    {
        $strategy = BackoffStrategy::fixed();

        $this->assertEquals(5000, $strategy->delayForAttempt(1));
        $this->assertEquals(5000, $strategy->delayForAttempt(2));
        $this->assertEquals(5000, $strategy->delayForAttempt(10));
    }

    public function test_fixed_custom_delay(): void
    {
        $strategy = BackoffStrategy::fixed(3000);

        $this->assertEquals(3000, $strategy->delayForAttempt(1));
        $this->assertEquals(3000, $strategy->delayForAttempt(5));
    }

    // ============================================================
    // Custom 自定义数组
    // ============================================================

    public function test_custom_sequential(): void
    {
        $strategy = BackoffStrategy::custom([100, 200, 500, 1000]);

        $this->assertEquals(100, $strategy->delayForAttempt(1));
        $this->assertEquals(200, $strategy->delayForAttempt(2));
        $this->assertEquals(500, $strategy->delayForAttempt(3));
        $this->assertEquals(1000, $strategy->delayForAttempt(4));
    }

    public function test_custom_falls_back_to_last(): void
    {
        $strategy = BackoffStrategy::custom([100, 500]);

        $this->assertEquals(100, $strategy->delayForAttempt(1));
        $this->assertEquals(500, $strategy->delayForAttempt(2));
        $this->assertEquals(500, $strategy->delayForAttempt(3)); // fallback to last
        $this->assertEquals(500, $strategy->delayForAttempt(10)); // fallback to last
    }

    public function test_custom_empty_array_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BackoffStrategy::custom([]);
    }

    // ============================================================
    // Invoke / retry() 兼容
    // ============================================================

    public function test_invoke_acts_as_callable(): void
    {
        $strategy = BackoffStrategy::linear(200);

        $this->assertEquals(200, $strategy(1));
        $this->assertEquals(400, $strategy(2));
    }

    public function test_invoke_passed_to_retry(): void
    {
        $strategy = BackoffStrategy::exponential(100);
        $calls = [];

        // Simulate retry behavior: callable receives $attempt
        $attempt = 1;
        try {
            $calls[] = ['attempt' => $attempt, 'delay' => -1];
            throw new \RuntimeException('fail');
        } catch (\Throwable $e) {
            $delay = $strategy($attempt);
            $calls[0]['delay'] = $delay;
        }

        // 1st attempt: 100 * 2^0 = 100
        $this->assertEquals(100, $calls[0]['delay']);
    }

    // ============================================================
    // Serialize / fromArray
    // ============================================================

    public function test_serialize_and_from_array(): void
    {
        $original = BackoffStrategy::exponential(500, 10000);
        $data = $original->serialize();
        $restored = BackoffStrategy::fromArray($data);

        $this->assertEquals($original->delayForAttempt(1), $restored->delayForAttempt(1));
        $this->assertEquals($original->delayForAttempt(2), $restored->delayForAttempt(2));
        $this->assertEquals($original->delayForAttempt(5), $restored->delayForAttempt(5));
    }

    public function test_serialize_and_from_array_custom(): void
    {
        $original = BackoffStrategy::custom([100, 300, 600]);
        $data = $original->serialize();
        $restored = BackoffStrategy::fromArray($data);

        $this->assertEquals(100, $restored->delayForAttempt(1));
        $this->assertEquals(300, $restored->delayForAttempt(2));
        $this->assertEquals(600, $restored->delayForAttempt(3));
        $this->assertEquals(600, $restored->delayForAttempt(5));
    }

    public function test_from_array_unknown_method_falls_back_to_linear(): void
    {
        $restored = BackoffStrategy::fromArray(['method' => 'unknown', 'base_delay_ms' => 500]);

        $this->assertEquals(500, $restored->delayForAttempt(1));
        $this->assertEquals(1000, $restored->delayForAttempt(2));
    }

    // ============================================================
    // toArray
    // ============================================================

    public function test_to_array_with_max_attempts(): void
    {
        $strategy = BackoffStrategy::linear(100);
        $delays = $strategy->toArray(3);

        $this->assertEquals([100, 200, 300], $delays);
    }

    public function test_to_array_no_args_serializes(): void
    {
        $strategy = BackoffStrategy::exponential(500);
        $data = $strategy->toArray();

        $this->assertArrayHasKey('method', $data);
        $this->assertArrayHasKey('base_delay_ms', $data);
        $this->assertEquals('exponential', $data['method']);
    }

    public function test_get_method(): void
    {
        $linear = BackoffStrategy::linear();
        $exponential = BackoffStrategy::exponential();
        $fixed = BackoffStrategy::fixed();
        $custom = BackoffStrategy::custom([100]);

        $this->assertEquals('linear', $linear->getMethod());
        $this->assertEquals('exponential', $exponential->getMethod());
        $this->assertEquals('fixed', $fixed->getMethod());
        $this->assertEquals('custom', $custom->getMethod());
    }
}
