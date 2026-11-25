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


    public function testMemoizeExecutesCallableOnce(): void
    {
        $a = $this->getMemoizedValue();
        $b = $this->getMemoizedValue();

        $this->assertSame('cached', $a);
        $this->assertSame('cached', $b);
    }

    private int $memoizeCallCount = 0;

    private function getMemoizedValue(): string
    {
        return Once::memoize(fn() => 'cached');
    }

    public function testOnceGlobalFunction(): void
    {
        $callCount = 0;

        $callback = static function () use (&$callCount) {
            $callCount++;
            return 42;
        };

        $results = [];
        for ($i = 0; $i < 2; $i++) {
            $results[] = once($callback);
        }

        $this->assertSame([42, 42], $results);
        $this->assertSame(1, $callCount, '同一调用点 + 同一闭包实例应只执行一次');
    }


    public function testMemoizeDifferentCallSitesIndependent(): void
    {
        $resultA = $this->helperA();
        $resultB = $this->helperB();

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


    public function testMemoizeMultipleCallsFromSameSite(): void
    {
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


    public function testNonStaticClosureIsolation(): void
    {
        $results = [];

        for ($i = 1; $i <= 3; $i++) {
            $results[] = Once::memoize(function () use ($i) {
                return $i * 10;
            });
        }

        $this->assertSame([10, 20, 30], $results);

        $results2 = [];
        for ($i = 1; $i <= 3; $i++) {
            $results2[] = Once::memoize(function () use ($i) {
                return $i * 10;
            });
        }
        $this->assertSame([10, 20, 30], $results2);
    }

    public function testSameCodeDifferentUsages(): void
    {
        $result1 = Once::memoize(function () {
            return 100;
        });

        $result2 = Once::memoize(function () {
            return 200;
        });

        $this->assertSame(100, $result1);
        $this->assertSame(200, $result2);
    }


    public function testForgetClearsSpecificCache(): void
    {
        $callback = fn() => 'unique_value';

        $first = Once::memoize($callback);
        $this->assertSame('unique_value', $first);

        Once::forget($callback);

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


    public function testFlushClearsAllCache(): void
    {
        $result1 = $this->getFixedValue();
        $this->assertSame(42, $result1);

        Once::flush();

        $result2 = $this->getFixedValue();
        $this->assertSame(42, $result2);
    }

    private function getFixedValue(): int
    {
        return Once::memoize(function () {
            return 42;
        });
    }


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

        $results = [];
        for ($i = 0; $i < 2; $i++) {
            $results[] = Once::memoize([$obj, 'compute']);
        }

        $this->assertSame(['computed', 'computed'], $results);
        $this->assertSame(1, $obj->callCount, '同一对象方法在同一调用点应只执行一次');
    }


    public function testExplicitKeyHitsRegardlessOfCallSiteAndClosureInstance(): void
    {
        $callCount = 0;

        $a = Once::memoize(function () use (&$callCount) {
            $callCount++;
            return 'value';
        }, 'once-test.explicit');

        $b = Once::memoize(function () use (&$callCount) {
            $callCount++;
            return 'value';
        }, 'once-test.explicit');

        $this->assertSame('value', $a);
        $this->assertSame('value', $b);
        $this->assertSame(1, $callCount, '显式 key 应跨调用点与闭包实例命中');
    }

    public function testExplicitKeysAreIsolated(): void
    {
        $countA = 0;
        $countB = 0;

        Once::memoize(function () use (&$countA) { $countA++; return 'a'; }, 'once-test.iso-a');
        Once::memoize(function () use (&$countA) { $countA++; return 'a'; }, 'once-test.iso-a');
        Once::memoize(function () use (&$countB) { $countB++; return 'b'; }, 'once-test.iso-b');

        $this->assertSame(1, $countA);
        $this->assertSame(1, $countB);
    }

    public function testOnceHelperAcceptsExplicitKey(): void
    {
        $callCount = 0;

        $callback = function () use (&$callCount) {
            $callCount++;
            return 7;
        };

        $this->assertSame(7, once($callback, 'once-test.helper'));
        $this->assertSame(7, once($callback, 'once-test.helper'));
        $this->assertSame(1, $callCount);
    }

    public function testForgetWithExplicitKey(): void
    {
        $callCount = 0;

        $callback = function () use (&$callCount) {
            $callCount++;
            return 'v';
        };

        Once::memoize($callback, 'once-test.forget');
        Once::forget($callback, 'once-test.forget');
        Once::memoize($callback, 'once-test.forget');

        $this->assertSame(2, $callCount, 'forget 后应重新执行');
    }

    public function testExplicitKeyDoesNotCollideWithAutoKey(): void
    {
        Once::memoize(static fn () => 'explicit', 'src/Some.php:42');
        $afterExplicit = Once::count();

        $this->autoKeySample();
        $this->autoKeySample();

        $this->assertSame(
            $afterExplicit + 1,
            Once::count(),
            '显式键与自动键应落在不同缓存条目上'
        );
    }

    private function autoKeySample(): string
    {
        return Once::memoize(static fn () => 'auto');
    }


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
