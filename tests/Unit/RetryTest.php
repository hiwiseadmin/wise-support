<?php

declare(strict_types=1);

namespace wise\support\test;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use LogicException;
use ErrorException;

/**
 * retry() 全局函数单元测试
 */
class RetryTest extends TestCase
{

    public function testRetrySucceedsOnFirstAttempt(): void
    {
        $callCount = 0;

        $result = retry(3, function ($attempt) use (&$callCount) {
            $callCount++;
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(1, $callCount, 'callback 应只执行 1 次');
    }

    public function testRetrySucceedsAfterFailures(): void
    {
        $callCount = 0;

        $result = retry(3, function ($attempt) use (&$callCount) {
            $callCount++;
            if ($attempt < 3) {
                throw new RuntimeException("Attempt {$attempt} failed");
            }
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(3, $callCount, 'callback 应执行 3 次（2 次失败 + 1 次成功）');
    }


    public function testRetryThrowsAfterExhaustingAttempts(): void
    {
        $callCount = 0;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Always fails');

        retry(2, function ($attempt) use (&$callCount) {
            $callCount++;
            throw new RuntimeException('Always fails');
        });

        $this->assertSame(2, $callCount);
    }

    public function testRetryThrowsLastExceptionOnExhaustion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Attempt 2');

        retry(2, function ($attempt) {
            throw new RuntimeException("Attempt {$attempt}");
        });
    }

    /**
     * @depends testRetryThrowsLastExceptionOnExhaustion
     */
    public function testRetryExceptionMessageIsFromLastAttempt(): void
    {
        $callCount = 0;

        try {
            retry(3, function ($attempt) use (&$callCount) {
                $callCount++;
                throw new RuntimeException("Attempt {$attempt}");
            });
            $this->fail('Should have thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('Attempt 3', $e->getMessage());
            $this->assertSame(3, $callCount);
        }
    }


    public function testWhenFilterAllowsRetryOnlyForMatchingException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Not retryable');

        retry(3, function () {
            throw new LogicException('Not retryable');
        }, when: function (\Throwable $e) {
            return $e instanceof RuntimeException;
        });
    }

    public function testWhenFilterRetriesMatchingException(): void
    {
        $callCount = 0;

        $result = retry(3, function ($attempt) use (&$callCount) {
            $callCount++;
            if ($attempt < 2) {
                throw new RuntimeException("Retryable");
            }
            return 'recovered';
        }, when: function (\Throwable $e) {
            return $e instanceof RuntimeException;
        });

        $this->assertSame('recovered', $result);
        $this->assertSame(2, $callCount);
    }


    public function testSleepDelayAppliedBetweenRetries(): void
    {
        $start = hrtime(true);
        $callCount = 0;

        retry(3, function ($attempt) use (&$callCount) {
            $callCount++;
            if ($attempt < 3) {
                throw new RuntimeException("Fail {$attempt}");
            }
            return true;
        }, sleepMs: 50);

        $elapsedMs = (hrtime(true) - $start) / 1e6;

        $this->assertSame(3, $callCount);
        $this->assertGreaterThanOrEqual(100, $elapsedMs, '应至少有 100ms 的总 sleep');
    }

    public function testExponentialBackoffViaCallable(): void
    {
        $delays = [];

        try {
            retry(3, function ($attempt) use (&$delays) {
                $delays[] = $attempt;
                throw new RuntimeException("Fail");
            }, sleepMs: function ($attempt) {
                return $attempt * 100;
            });
        } catch (RuntimeException $e) {
        }

        $this->assertSame([1, 2, 3], $delays);
    }


    public function testOnFailureCallbackInvoked(): void
    {
        $failures = [];

        try {
            retry(3, function ($attempt) {
                throw new RuntimeException("Attempt {$attempt}");
            }, sleepMs: 0, onFailure: function (\Throwable $e, int $attempt) use (&$failures) {
                $failures[] = ['msg' => $e->getMessage(), 'attempt' => $attempt];
            });
        } catch (RuntimeException $e) {
        }

        $this->assertCount(3, $failures);
        $this->assertSame('Attempt 1', $failures[0]['msg']);
        $this->assertSame(1, $failures[0]['attempt']);
        $this->assertSame('Attempt 3', $failures[2]['msg']);
        $this->assertSame(3, $failures[2]['attempt']);
    }


    public function testAttemptParameterStartsFromOne(): void
    {
        $attempts = [];

        try {
            retry(5, function ($attempt) use (&$attempts) {
                $attempts[] = $attempt;
                throw new RuntimeException('fail');
            });
        } catch (\Throwable $e) {
        }

        $this->assertSame([1, 2, 3, 4, 5], $attempts);
    }


    public function testZeroSleepRetriesImmediately(): void
    {
        $start = hrtime(true);
        $callCount = 0;

        try {
            retry(10, function ($attempt) use (&$callCount) {
                $callCount++;
                throw new RuntimeException('fail');
            }, sleepMs: 0);
        } catch (\RuntimeException $e) {
        }

        $elapsedMs = (hrtime(true) - $start) / 1e6;

        $this->assertSame(10, $callCount);
        $this->assertLessThan(50, $elapsedMs, '无 delay 时不应有显著等待');
    }
}
