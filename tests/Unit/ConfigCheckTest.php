<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\config\CheckLevel;
use wise\support\config\CheckResult;
use wise\support\config\ConfigCheckCollector;
use wise\support\config\ConfigCheckInterface;

/**
 * CheckResult / CheckLevel / ConfigCheckCollector 单元测试
 */
class ConfigCheckTest extends TestCase
{
    /** 绑定到容器的日志替身，供断言"失败项确实写入了日志" */
    private object $logStub;

    protected function setUp(): void
    {
        // 绑定 mock Log 到 ThinkPHP 容器，避免 report() 触发真实日志系统
        //
        // 注意：ConfigCheckCollector::logResult() 通过 Log::record($message, $level)
        // 写入日志（第二个参数为 CheckLevel 的字符串值），替身必须实现 record()
        $this->logStub = new class {
            /** @var array<int, array{message: string, level: string}> */
            public array $records = [];

            public function record(string $message, string $level): void
            {
                $this->records[] = ['message' => $message, 'level' => $level];
            }

            public function error(string $message): void { /** 兼容旧调用方式 */ }

            public function warning(string $message): void { /** 兼容旧调用方式 */ }
        };

        \think\Container::getInstance()->bind('log', $this->logStub);
    }

    // ============================================================
    // CheckResult
    // ============================================================

    public function test_check_result_ok(): void
    {
        $result = CheckResult::ok('配置正常');

        $this->assertTrue($result->passed);
        $this->assertSame('配置正常', $result->message);
        $this->assertNull($result->fixHint);
    }

    public function test_check_result_ok_with_empty_message(): void
    {
        $result = CheckResult::ok();

        $this->assertTrue($result->passed);
        $this->assertSame('', $result->message);
    }

    public function test_check_result_fail(): void
    {
        $result = CheckResult::fail('JWT_SECRET 缺失', '请在 .env 中设置 JWT_SECRET');

        $this->assertFalse($result->passed);
        $this->assertSame('JWT_SECRET 缺失', $result->message);
        $this->assertSame('请在 .env 中设置 JWT_SECRET', $result->fixHint);
    }

    public function test_check_result_fail_without_hint(): void
    {
        $result = CheckResult::fail('错误');

        $this->assertFalse($result->passed);
        $this->assertNull($result->fixHint);
    }

    public function test_check_result_constructor(): void
    {
        $result = new CheckResult(true, 'msg', 'hint');

        $this->assertTrue($result->passed);
        $this->assertSame('msg', $result->message);
        $this->assertSame('hint', $result->fixHint);
    }

    // ============================================================
    // CheckLevel
    // ============================================================

    public function test_check_level_values(): void
    {
        $this->assertSame('warning', CheckLevel::WARNING->value);
        $this->assertSame('error', CheckLevel::ERROR->value);
        $this->assertSame('fatal', CheckLevel::FATAL->value);
    }

    public function test_check_level_is_backed_enum(): void
    {
        $this->assertInstanceOf(\BackedEnum::class, CheckLevel::ERROR);
    }

    // ============================================================
    // ConfigCheckCollector
    // ============================================================

    public function test_register_is_chainable(): void
    {
        $collector = new ConfigCheckCollector();
        $check = new StubConfigCheck('wise-jwt:secret', 'wise-jwt', CheckLevel::ERROR, CheckResult::ok());

        $this->assertSame($collector, $collector->register($check));
    }

    public function test_collect_returns_results_keyed_by_id(): void
    {
        $collector = new ConfigCheckCollector();
        $collector->register(new StubConfigCheck('a', 'pkg-a', CheckLevel::ERROR, CheckResult::ok('ok-a')));
        $collector->register(new StubConfigCheck('b', 'pkg-b', CheckLevel::WARNING, CheckResult::fail('fail-b')));

        $results = $collector->collect();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('a', $results);
        $this->assertArrayHasKey('b', $results);
        $this->assertTrue($results['a']->passed);
        $this->assertFalse($results['b']->passed);
    }

    public function test_collect_empty_returns_empty_array(): void
    {
        $collector = new ConfigCheckCollector();

        $this->assertSame([], $collector->collect());
    }

    public function test_collect_catches_exception_as_failure(): void
    {
        $collector = new ConfigCheckCollector();
        $collector->register(new ThrowingConfigCheck('boom', 'pkg'));

        $results = $collector->collect();

        $this->assertCount(1, $results);
        $this->assertFalse($results['boom']->passed);
        $this->assertStringContainsString('执行异常', $results['boom']->message);
    }

    public function test_collect_does_not_let_exception_propagate(): void
    {
        $collector = new ConfigCheckCollector();
        $collector->register(new ThrowingConfigCheck('boom', 'pkg'));
        $collector->register(new StubConfigCheck('ok', 'pkg', CheckLevel::ERROR, CheckResult::ok()));

        // 一个抛异常不应影响其他检查项
        $results = $collector->collect();

        $this->assertCount(2, $results);
        $this->assertTrue($results['ok']->passed);
        $this->assertFalse($results['boom']->passed);
    }

    public function test_report_returns_only_failed(): void
    {
        $collector = new ConfigCheckCollector();
        $collector->register(new StubConfigCheck('pass', 'pkg', CheckLevel::ERROR, CheckResult::ok()));
        $collector->register(new StubConfigCheck('fail', 'pkg', CheckLevel::WARNING, CheckResult::fail('missing')));

        $failed = $collector->report();

        $this->assertCount(1, $failed);
        $this->assertArrayHasKey('fail', $failed);
        $this->assertArrayNotHasKey('pass', $failed);

        // 失败项应写入日志；检查项通过时不应写日志
        $this->assertNotEmpty($this->logStub->records, '失败检查项应写入日志');
        $this->assertStringContainsString('missing', $this->logStub->records[0]['message']);
        $this->assertSame('warning', $this->logStub->records[0]['level']);
    }

    public function test_report_returns_empty_when_all_pass(): void
    {
        $collector = new ConfigCheckCollector();
        $collector->register(new StubConfigCheck('pass', 'pkg', CheckLevel::ERROR, CheckResult::ok()));

        $this->assertSame([], $collector->report());
    }

    public function test_register_overwrites_same_id(): void
    {
        $collector = new ConfigCheckCollector();
        $collector->register(new StubConfigCheck('same', 'pkg', CheckLevel::ERROR, CheckResult::ok('first')));
        $collector->register(new StubConfigCheck('same', 'pkg', CheckLevel::ERROR, CheckResult::ok('second')));

        $results = $collector->collect();

        $this->assertCount(1, $results);
        $this->assertSame('second', $results['same']->message);
    }
}

// ============================================================
// 测试辅助类
// ============================================================

class StubConfigCheck implements ConfigCheckInterface
{
    public function __construct(
        private string $id,
        private string $package,
        private CheckLevel $level,
        private CheckResult $result,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function package(): string
    {
        return $this->package;
    }

    public function level(): CheckLevel
    {
        return $this->level;
    }

    public function check(): CheckResult
    {
        return $this->result;
    }
}

class ThrowingConfigCheck implements ConfigCheckInterface
{
    public function __construct(
        private string $id,
        private string $package,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function package(): string
    {
        return $this->package;
    }

    public function level(): CheckLevel
    {
        return CheckLevel::ERROR;
    }

    public function check(): CheckResult
    {
        throw new \RuntimeException('check 实现异常');
    }
}
