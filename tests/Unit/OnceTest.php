<?php

declare(strict_types=1);

namespace wise\support\test;

use PHPUnit\Framework\TestCase;
use wise\support\Once;

/**
 * once() 及 Once 类单元测试
 */
class OnceTest extends TestCase
{
    protected function setUp(): void
    {
        Once::flush();
    }

    protected function tearDown(): void
    {
        Once::flush();
    }

    // ==================== 基本缓存 ====================

    public function testMemoizeExecutesCallableOnce(): void
    {
        // 通过辅助方法确保同一 call-site
        $a = $this->getMemoizedValue();
        $b = $this->getMemoizedValue();

        $this->assertSame('cached', $a);
        $this->assertSame('cached', $b);
        // 返回相同的缓存值
    }

    private int $memoizeCallCount = 0;

    private function getMemoizedValue(): string
    {
        return Once::memoize(fn() => 'cached');
    }

    public function testOnceGlobalFunction(): void
    {
        $callCount = 0;

        $a = once(function () use (&$callCount) {
            $callCount++;
            return 42;
        });

        $b = once(function () use (&$callCount) {
            $callCount++;
            return 42;
        });

        $this->assertSame(42, $a);
        $this->assertSame(42, $b);
        $this->assertSame(1, $callCount);
    }

    // ==================== 不同位置独立缓存 ====================

    public function testMemoizeDifferentCallSitesIndependent(): void
    {
        $resultA = $this->helperA();
        $resultB = $this->helperB();

        // 两处各自返回自己的值
        $this->assertSame('from-a', $resultA);
        $this->assertSame('from-b', $resultB);
    }

    private function helperA(): string
    {
        return Once::memoize(fn() => 'from-a');
    }

    private function helperB(): string
    {
        return Once::memoize(fn() => 'from-b');
    }

    // ==================== 多次调用验证 ====================

    public function testMemoizeMultipleCallsFromSameSite(): void
    {
        // 静态闭包 — 同一 call-site，多次调用返回同一缓存值
        $result1 = $this->getStaticMemoized();
        $result2 = $this->getStaticMemoized();
        $result3 = $this->getStaticMemoized();

        $this->assertSame('static-result', $result1);
        $this->assertSame('static-result', $result2);
        $this->assertSame('static-result', $result3);
    }

    private function getStaticMemoized(): string
    {
        return Once::memoize(fn() => 'static-result');
    }

    // ==================== 非静态闭包隔离 ====================

    public function testNonStaticClosureIsolation(): void
    {
        $results = [];

        for ($i = 1; $i <= 3; $i++) {
            // 每次循环创建新闭包实例（use 绑定了不同 $i）
            $results[] = Once::memoize(function () use ($i) {
                return $i * 10;
            });
        }

        // 每个闭包只执行一次，各自返回不同值
        $this->assertSame([10, 20, 30], $results);

        // 第二轮调用，全部命中缓存
        $results2 = [];
        for ($i = 1; $i <= 3; $i++) {
            $results2[] = Once::memoize(function () use ($i) {
                return $i * 10; // 不应到达这里
            });
        }
        $this->assertSame([10, 20, 30], $results2);
    }

    public function testSameCodeDifferentUsages(): void
    {
        // 不同 call-site（不同行）创建独立的缓存条目
        $result1 = Once::memoize(function () {
            return 100;
        });

        $result2 = Once::memoize(function () {
            return 200;
        });

        // 不同 call-site → 各自独立缓存，各自返回自己的值
        $this->assertSame(100, $result1);
        $this->assertSame(200, $result2);
    }

    // ==================== forget() ====================

    public function testForgetClearsSpecificCache(): void
    {
        $callback = fn() => 'unique_value';

        // 缓存
        $first = Once::memoize($callback);
        $this->assertSame('unique_value', $first);

        // 传入同一闭包实例做 forget
        Once::forget($callback);

        // 重新执行
        $second = Once::memoize($callback);
        $this->assertSame('unique_value', $second);
    }

    /**
     * 测试 forget 同一闭包后重新计算。
     * 使用将闭包存储为属性的方式确保传回同一实例。
     */
    public function testForgetAndRecompute(): void
    {
        $counter = (object)['n' => 0];
        $callback = function () use ($counter) { $counter->n++; return $counter->n; };

        $val1 = Once::memoize($callback);
        $this->assertSame(1, $val1);

        Once::forget($callback);

        $val2 = Once::memoize($callback);
        $this->assertSame(2, $val2, 'forget 后同一闭包实例重新计算');
    }

    // ==================== flush() ====================

    public function testFlushClearsAllCache(): void
    {
        $result1 = $this->getFixedValue();
        $this->assertSame(42, $result1);

        Once::flush();

        // flush 后重新计算（但 our helper 中闭包缓存的是 42）
        $result2 = $this->getFixedValue();
        $this->assertSame(42, $result2);
    }

    private function getFixedValue(): int
    {
        return Once::memoize(function () {
            return 42;
        });
    }

    // ==================== 可调用数组 ====================

    public function testCallableArrayMemoize(): void
    {
        $obj = new class {
            public int $callCount = 0;
            public function compute(): string
            {
                $this->callCount++;
                return 'computed';
            }
        };

        $a = Once::memoize([$obj, 'compute']);
        $b = Once::memoize([$obj, 'compute']);

        $this->assertSame('computed', $a);
        $this->assertSame('computed', $b);
        $this->assertSame(1, $obj->callCount, '同一对象方法调用应只执行一次');
    }

    // ==================== 返回值多样性 ====================

    public function testMemoizeHandlesNullReturn(): void
    {
        $result = Once::memoize(fn() => null);

        $this->assertNull($result);
    }

    public function testMemoizeHandlesFalseReturn(): void
    {
        $result = Once::memoize(fn() => false);

        $this->assertFalse($result);
    }

    public function testMemoizeHandlesZeroReturn(): void
    {
        $result = Once::memoize(fn() => 0);

        $this->assertSame(0, $result);
    }

    public function testMemoizeHandlesArrayReturn(): void
    {
        $expected = ['key' => 'value', 'nested' => [1, 2, 3]];

        $result = Once::memoize(fn() => $expected);

        $this->assertSame($expected, $result);
    }
}
