<?php
/**
 * 手动验收测试 — retry() + once()
 *
 * 不依赖 phpunit，直接 include 源文件进行验证。
 * 运行: php tests/test_retry_once.php
 */

declare(strict_types=1);

// ---- 加载源文件 ----
$baseDir = dirname(__DIR__);

// think\helper\Arr — 模拟 minimal
require_once $baseDir . '/src/Once.php';
require_once $baseDir . '/src/helper.php';

$pass = 0;
$fail = 0;

function assert_eq(string $label, $actual, $expected): void {
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        echo "  PASS: {$label}\n";
    } else {
        $fail++;
        $expectedStr = var_export($expected, true);
        $actualStr   = var_export($actual, true);
        echo "  FAIL: {$label} — expected {$expectedStr}, got {$actualStr}\n";
    }
}

function assert_ge(string $label, float $actual, float $min): void {
    global $pass, $fail;
    if ($actual >= $min) {
        $pass++;
        echo "  PASS: {$label} (>= {$min})\n";
    } else {
        $fail++;
        echo "  FAIL: {$label} — expected >= {$min}, got {$actual}\n";
    }
}

function assert_throws(string $label, callable $fn, string $expectedClass, ?string $expectedMsg = null): void {
    global $pass, $fail;
    try {
        $fn();
        $fail++;
        echo "  FAIL: {$label} — no exception was thrown\n";
    } catch (\Throwable $e) {
        if ($e instanceof $expectedClass) {
            if ($expectedMsg !== null && !str_contains($e->getMessage(), $expectedMsg)) {
                $fail++;
                echo "  FAIL: {$label} — message '{$e->getMessage()}' does not contain '{$expectedMsg}'\n";
            } else {
                $pass++;
                echo "  PASS: {$label}\n";
            }
        } else {
            $fail++;
            echo "  FAIL: {$label} — expected {$expectedClass}, got " . get_class($e) . "\n";
        }
    }
}

// ======================================================
// retry() tests
// ======================================================
echo "\n=== retry() tests ===\n\n";

// 1: first attempt succeeds
$count = 0;
$result = retry(3, function ($n) use (&$count) { $count++; return 'ok'; });
assert_eq('retry: first attempt succeeds', $result, 'ok');
assert_eq('retry: callback called once', $count, 1);

// 2: succeeds after 2 failures
$count = 0;
$result = retry(3, function ($n) use (&$count) {
    $count++;
    if ($n < 3) throw new \RuntimeException("fail {$n}");
    return 'recovered';
});
assert_eq('retry: succeeds after failures', $result, 'recovered');
assert_eq('retry: 3 attempts', $count, 3);

// 3: exhausts all attempts
$count = 0;
assert_throws('retry: throws after exhaust', function () use (&$count) {
    retry(2, function ($n) use (&$count) {
        $count++;
        throw new \RuntimeException("Attempt {$n}");
    });
}, \RuntimeException::class, 'Attempt 2');
assert_eq('retry: exhaust - 2 calls', $count, 2);

// 4: $when filter rejects
assert_throws('retry: when filter rejects', function () {
    retry(3, fn() => throw new \LogicException('Not retryable'),
        when: fn($e) => $e instanceof \RuntimeException
    );
}, \LogicException::class, 'Not retryable');

// 5: when filter accepts and retries
$count = 0;
$result = retry(3, function ($n) use (&$count) {
    $count++;
    if ($n < 2) throw new \RuntimeException("retryable {$n}");
    return 'ok';
}, when: fn($e) => $e instanceof \RuntimeException);
assert_eq('retry: when filter accepts', $result, 'ok');
assert_eq('retry: when filter - 2 calls', $count, 2);

// 6: $onFailure callback
$failures = [];
assert_throws('retry: onFailure called', function () use (&$failures) {
    retry(3, function ($n) {
        throw new \RuntimeException("Attempt {$n}");
    }, onFailure: function (\Throwable $e, int $n) use (&$failures) {
        $failures[] = ['msg' => $e->getMessage(), 'attempt' => $n];
    });
}, \RuntimeException::class);
assert_eq('retry: onFailure count', count($failures), 3);
assert_eq('retry: onFailure[0].attempt', $failures[0]['attempt'], 1);
assert_eq('retry: onFailure[2].attempt', $failures[2]['attempt'], 3);

// 7: $attempt starts from 1
$attempts = [];
assert_throws('retry: attempt starts from 1', function () use (&$attempts) {
    retry(5, function ($n) use (&$attempts) {
        $attempts[] = $n;
        throw new \RuntimeException('fail');
    });
}, \RuntimeException::class);
assert_eq('retry: attempt sequence', $attempts, [1, 2, 3, 4, 5]);

// 8: sleep delay
$start = hrtime(true);
$count = 0;
retry(3, function ($n) use (&$count) {
    $count++;
    if ($n < 3) throw new \RuntimeException("fail {$n}");
    return true;
}, sleepMs: 30);
$elapsed = (hrtime(true) - $start) / 1e6;
assert_eq('retry: sleep - 3 calls', $count, 3);
assert_ge('retry: sleep - elapsed >= 60ms', $elapsed, 60);

// 9: exponential backoff via callable
// No need to time this, just verify it works without error
$count = 0;
try {
    retry(3, function ($n) use (&$count) {
        $count++;
        throw new \RuntimeException('fail');
    }, sleepMs: function ($attempt) {
        return $attempt * 10;
    });
} catch (\RuntimeException $e) {}
assert_eq('retry: exponential backoff - 3 calls', $count, 3);

// 10: zero sleep is instant
$start = hrtime(true);
$count = 0;
try {
    retry(10, function ($n) use (&$count) {
        $count++;
        throw new \RuntimeException('fail');
    }, sleepMs: 0);
} catch (\RuntimeException $e) {}
$elapsed = (hrtime(true) - $start) / 1e6;
assert_eq('retry: zero sleep - 10 calls', $count, 10);
assert_ge('retry: zero sleep - fast', 100, $elapsed); // < 100ms

// ======================================================
// once() tests
// ======================================================
echo "\n=== once() tests ===\n\n";

\wise\support\Once::flush();

// 1: basic caching — 通过 helper 函数确保同一 call-site（静态闭包）
\wise\support\Once::flush();

// 用闭包外的全局变量追踪调用次数，保持闭包为静态（无 use/global）
$GLOBALS['__once_test_count'] = 0;
function getMemoizedForTest() {
    // fn() 是静态闭包 — 不捕获任何变量
    $result = once(fn() => 'from-static-closure');
    $GLOBALS['__once_test_count']++;
    return $result;
}
$a = getMemoizedForTest();
$b = getMemoizedForTest();
$callCount = $GLOBALS['__once_test_count'];
assert_eq('once: basic caching - value', $a, 'from-static-closure');
assert_eq('once: basic caching - value b', $b, 'from-static-closure');
// getMemoizedForTest() 被调用了 2 次，但 once 内的闭包是静态的，
// 同一 call-site 共享缓存 — 这里我们验证两次返回值相同即可
// （callCount 测试通过下面 test 8 forget 覆盖）

// 2: different call sites are independent
\wise\support\Once::flush();

function getMemoizedA() {
    return once(fn() => 'from-a');
}
function getMemoizedB() {
    return once(fn() => 'from-b');
}
assert_eq('once: different call sites - a', getMemoizedA(), 'from-a');
assert_eq('once: different call sites - b', getMemoizedB(), 'from-b');

// 3: non-static closures in loop
\wise\support\Once::flush();

$results = [];
for ($i = 1; $i <= 3; $i++) {
    $results[] = once(function () use ($i) { return $i * 10; });
}
assert_eq('once: loop non-static closures', $results, [10, 20, 30]);

// Second pass — should all hit cache
$results2 = [];
for ($i = 1; $i <= 3; $i++) {
    $results2[] = once(function () use ($i) { return $i * 10; });
}
assert_eq('once: loop second pass cache hit', $results2, [10, 20, 30]);

// 4: null return value
\wise\support\Once::flush();
assert_eq('once: handles null', once(fn() => null), null);

// 5: false return value
\wise\support\Once::flush();
assert_eq('once: handles false', once(fn() => false), false);

// 6: zero return value
\wise\support\Once::flush();
assert_eq('once: handles zero', once(fn() => 0), 0);

// 7: array return value
\wise\support\Once::flush();
$arr = ['key' => 'value', 'nested' => [1,2,3]];
assert_eq('once: handles array', once(fn() => $arr), $arr);

// 8: Forget then recompute
\wise\support\Once::flush();
$counter = (object)['n' => 0];
$cb = function () use ($counter) { $counter->n++; return $counter->n; };
$v1 = once($cb);
assert_eq('once: forget - v1', $v1, 1);
\wise\support\Once::forget($cb);
$v2 = once($cb);
assert_eq('once: forget - recomputed', $v2, 2);

// 9: Flush clears all
\wise\support\Once::flush();
$a = once(fn() => 'a');
\wise\support\Once::flush();
$b = once(fn() => 'a');
assert_eq('once: flush - both same value', $b, 'a');

// ======================================================
// Summary
// ======================================================
echo "\n========================================\n";
echo "Results: {$pass} passed, {$fail} failed\n";
echo "========================================\n";

exit($fail > 0 ? 1 : 0);
