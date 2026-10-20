<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use think\Container;
use wise\support\context\ContextualLogger;
use wise\support\context\RequestContext;

/**
 * 上下文感知日志记录器 ContextualLogger 单元测试
 */
class ContextualLoggerTest extends TestCase
{
    private ChannelRecordLogStub $log;

    protected function setUp(): void
    {
        RequestContext::clear();
        $this->log = new ChannelRecordLogStub();
        Container::getInstance()->bind('log', $this->log);
    }

    protected function tearDown(): void
    {
        RequestContext::clear();
        Container::getInstance()->delete('log');
    }

    /**
     * @return array{level:string,message:string,context:array,channel:string}
     */
    private function lastRecord(): array
    {
        $records = $this->log->records->getArrayCopy();
        $this->assertNotEmpty($records, '应至少记录一条日志');

        return $records[count($records) - 1];
    }

    // ============================================================
    // 静态调用
    // ============================================================

    public function test_static_info_writes_message_and_context(): void
    {
        ContextualLogger::info('支付成功', ['order_id' => 42]);

        $record = $this->lastRecord();
        $this->assertSame('info', $record['level']);
        $this->assertSame('支付成功', $record['message']);
        $this->assertSame(42, $record['context']['order_id']);
        $this->assertSame('', $record['channel']);
    }

    public function test_static_call_merges_request_context(): void
    {
        RequestContext::setRequestId('rid-1');
        RequestContext::setUser((object) ['id' => 9]);
        RequestContext::setGuard('api');

        ContextualLogger::warning('限流触发', ['key' => 'api:1']);

        $context = $this->lastRecord()['context'];
        $this->assertSame('rid-1', $context['request_id']);
        $this->assertSame(9, $context['user_id']);
        $this->assertSame('api', $context['guard']);
        $this->assertSame('api:1', $context['key']);
    }

    public function test_request_context_defaults_when_empty(): void
    {
        ContextualLogger::debug('无上下文');

        $context = $this->lastRecord()['context'];
        $this->assertSame('unknown', $context['request_id']);
        $this->assertSame('admin', $context['guard']);
        $this->assertArrayNotHasKey('user_id', $context);
    }

    public function test_custom_context_overrides_auto_injected_keys(): void
    {
        RequestContext::setRequestId('rid-1');

        ContextualLogger::error('覆盖', ['request_id' => 'custom']);

        $this->assertSame('custom', $this->lastRecord()['context']['request_id']);
    }

    public function test_static_call_without_arguments_uses_defaults(): void
    {
        ContextualLogger::notice();

        $record = $this->lastRecord();
        $this->assertSame('notice', $record['level']);
        $this->assertSame('', $record['message']);
    }

    public function test_sql_is_logged_as_debug_with_prefix(): void
    {
        ContextualLogger::sql('SELECT 1');

        $record = $this->lastRecord();
        $this->assertSame('debug', $record['level']);
        $this->assertSame('[SQL] SELECT 1', $record['message']);
    }

    public function test_all_levels_are_routed(): void
    {
        foreach (['info', 'error', 'warning', 'debug', 'notice', 'alert', 'critical', 'emergency'] as $level) {
            ContextualLogger::$level("msg-{$level}");
        }

        $records = $this->log->records->getArrayCopy();
        $this->assertCount(8, $records);

        foreach (['info', 'error', 'warning', 'debug', 'notice', 'alert', 'critical', 'emergency'] as $index => $level) {
            $this->assertSame($level, $records[$index]['level']);
            $this->assertSame("msg-{$level}", $records[$index]['message']);
        }
    }

    public function test_unknown_static_method_throws(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('does not exist');

        ContextualLogger::nope('x');
    }

    // ============================================================
    // 通道实例
    // ============================================================

    public function test_channel_returns_instance_with_channel_name(): void
    {
        $logger = ContextualLogger::channel('backup');

        $this->assertInstanceOf(ContextualLogger::class, $logger);
        $this->assertNotSame($logger, ContextualLogger::channel('backup'));
    }

    public function test_channel_instance_routes_to_named_channel(): void
    {
        ContextualLogger::channel('ai')->error('AI 任务失败', ['task_id' => 7]);

        $record = $this->lastRecord();
        $this->assertSame('ai', $record['channel']);
        $this->assertSame('error', $record['level']);
        $this->assertSame(7, $record['context']['task_id']);
    }

    public function test_channel_instance_sql_pushes_to_channel(): void
    {
        ContextualLogger::channel('db')->sql('DELETE FROM t');

        $record = $this->lastRecord();
        $this->assertSame('db', $record['channel']);
        $this->assertSame('debug', $record['level']);
        $this->assertSame('[SQL] DELETE FROM t', $record['message']);
    }

    public function test_unknown_instance_method_throws(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Method ContextualLogger::unknownLevel() does not exist.');

        ContextualLogger::channel('x')->unknownLevel('m');
    }
}

// ============================================================
// 测试替身
// ============================================================

class ChannelRecordLogStub
{
    public \ArrayObject $records;
    public string $channelName = '';

    public function __construct(?\ArrayObject $records = null, string $channel = '')
    {
        $this->records     = $records ?? new \ArrayObject();
        $this->channelName = $channel;
    }

    public function channel(string $name): self
    {
        return new self($this->records, $name);
    }

    private function write(string $level, mixed $message, array $context = []): void
    {
        $this->records->append([
            'level'   => $level,
            'message' => (string) $message,
            'context' => $context,
            'channel' => $this->channelName,
        ]);
    }

    public function info(mixed $message, array $context = []): void { $this->write('info', $message, $context); }
    public function error(mixed $message, array $context = []): void { $this->write('error', $message, $context); }
    public function warning(mixed $message, array $context = []): void { $this->write('warning', $message, $context); }
    public function debug(mixed $message, array $context = []): void { $this->write('debug', $message, $context); }
    public function notice(mixed $message, array $context = []): void { $this->write('notice', $message, $context); }
    public function alert(mixed $message, array $context = []): void { $this->write('alert', $message, $context); }
    public function critical(mixed $message, array $context = []): void { $this->write('critical', $message, $context); }
    public function emergency(mixed $message, array $context = []): void { $this->write('emergency', $message, $context); }

    public function record(mixed $message, string $level = 'info'): void
    {
        $this->write($level, $message);
    }

    public function log(mixed $level, mixed $message, array $context = []): void
    {
        $this->write((string) $level, $message, $context);
    }
}
