<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use think\Container;
use think\facade\Config;
use wise\support\ExtPoint;
use wise\support\bus\BusHandlerJob;
use wise\support\bus\Message;
use wise\support\bus\ServiceBus;

/**
 * ServiceBus 分发机制单元测试（ask / publish / broadcast / async）
 */
class ServiceBusDispatchTest extends TestCase
{
    private BusLogStub $logger;
    private string|false $originalErrorLog;

    protected function setUp(): void
    {
        ExtPoint::reset();
        $this->logger = new BusLogStub();

        $this->originalErrorLog = ini_get('error_log');
        ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise_bus_error.log');
    }

    protected function tearDown(): void
    {
        ExtPoint::reset();
        Container::getInstance()->delete('queue');
        Container::getInstance()->delete('config');
        if ($this->originalErrorLog !== false) {
            ini_set('error_log', $this->originalErrorLog);
        }
    }

    private function bus(): ServiceBus
    {
        return (new ServiceBus())->setLogger($this->logger);
    }

    /**
     * 注册处理器到 bus.handler.{action}
     */
    private function register(string $action, string $name, mixed $concrete, int $priority = 100): void
    {
        $point = 'bus.handler.' . $action;
        if (!ExtPoint::defined($point)) {
            ExtPoint::definePoint($point, ExtPoint::POLICY_MULTI);
        }
        ExtPoint::register($point, $name, $concrete, $priority);
    }

    // ============================================================
    // ask —— 请求-响应
    // ============================================================

    public function test_ask_with_closure_returning_message(): void
    {
        $this->register('payment.charge', 'charge', function (Message $message): Message {
            return Message::reply($message, ['charged' => $message->getPayload()['amount']]);
        });

        $result = $this->bus()->ask('payment.charge', ['amount' => 100]);

        $this->assertFalse($result->isError());
        $this->assertTrue($result->isReply());
        $this->assertSame(['charged' => 100], $result->getPayload());
    }

    public function test_ask_wraps_non_message_result(): void
    {
        $this->register('calc.sum', 'sum', fn(Message $m) => ['sum' => array_sum($m->getPayload())]);

        $result = $this->bus()->ask('calc.sum', [1, 2, 3]);

        $this->assertSame(['sum' => 6], $result->getPayload());
    }

    public function test_ask_wraps_scalar_result(): void
    {
        $this->register('calc.count', 'count', fn() => 42);

        $this->assertSame(['result' => 42], $this->bus()->ask('calc.count')->getPayload());
    }

    public function test_ask_resolves_handler_class_with_handle_method(): void
    {
        $this->register('order.notify', 'notifier', BusAskHandlerStub::class);
        BusAskHandlerStub::$handled = null;

        $result = $this->bus()->ask('order.notify', ['id' => 9]);

        $this->assertSame(9, BusAskHandlerStub::$handled);
        $this->assertSame(['notified' => 9], $result->getPayload());
    }

    public function test_ask_resolves_invokable_handler_class(): void
    {
        $this->register('order.invoke', 'invoker', BusAskInvokableStub::class);

        $result = $this->bus()->ask('order.invoke', ['id' => 3]);

        $this->assertSame(['invoked' => 3], $result->getPayload());
    }

    public function test_ask_selects_handler_by_explicit_name(): void
    {
        $this->register('route.me', 'first', fn() => 'first');
        $this->register('route.me', 'second', fn() => 'second');

        $result = $this->bus()->ask('route.me', [], ['_handler' => 'second']);

        $this->assertSame(['result' => 'second'], $result->getPayload());
        $this->assertSame([], $this->logger->records);
    }

    public function test_ask_prefers_active_handler(): void
    {
        $this->register('route.active', 'a', fn() => 'a');
        $this->register('route.active', 'b', fn() => 'b');
        // multi 策略下默认全部 active，停用第一个后应命中仍处于 active 的 b
        ExtPoint::setActive('bus.handler.route.active', 'a', false);

        $this->assertSame(['result' => 'b'], $this->bus()->ask('route.active')->getPayload());
        $this->assertSame([], $this->logger->records);
    }

    public function test_ask_selects_single_handler_without_warning(): void
    {
        $this->register('route.single', 'only', fn() => 'only');

        $this->bus()->ask('route.single');

        $this->assertSame([], $this->logger->records);
    }

    public function test_ask_warns_and_picks_highest_priority_when_ambiguous(): void
    {
        $this->register('route.multi', 'low', fn() => 'low', 100);
        $this->register('route.multi', 'high', fn() => 'high', 1);
        // 无 active 实现且存在多个候选时，才走「按 priority 兜底并告警」分支
        ExtPoint::setActive('bus.handler.route.multi', 'low', false);
        ExtPoint::setActive('bus.handler.route.multi', 'high', false);

        $result = $this->bus()->ask('route.multi');

        $this->assertSame(['result' => 'high'], $result->getPayload());
        $this->assertStringContainsString('有多个处理器，已选择 [high]', $this->logger->lastMessage());
        $this->assertSame('warning', $this->logger->lastLevel());
    }

    public function test_ask_no_handler_returns_404_error(): void
    {
        $result = $this->bus()->ask('missing.action', ['a' => 1]);

        $this->assertTrue($result->isError());
        $this->assertSame(404, $result->getErrorCode());
        $this->assertStringContainsString('没有注册的处理器', $result->getError());
    }

    public function test_ask_handler_exception_returns_500_without_leaking_details(): void
    {
        $this->register('boom.action', 'boom', function (): void {
            throw new \RuntimeException('SQLSTATE[42S02]: table not found');
        });

        $result = $this->bus()->ask('boom.action');

        $this->assertTrue($result->isError());
        $this->assertSame(500, $result->getErrorCode());
        $this->assertSame('处理器 [boom] 执行失败', $result->getError());
        $this->assertStringNotContainsString('SQLSTATE', (string) $result->getError());
        $this->assertStringContainsString('处理器 [boom] 执行 [boom.action] 失败', $this->logger->lastMessage());
        $this->assertSame('error', $this->logger->lastLevel());
    }

    public function test_ask_handler_exception_exposes_details_in_debug(): void
    {
        new \think\App(dirname(__DIR__, 5));
        Config::set(['debug' => true], 'app');

        $this->register('boom.debug', 'boom', function (): void {
            throw new \RuntimeException('debuggable detail');
        });

        $result = $this->bus()->ask('boom.debug');

        $this->assertSame('debuggable detail', $result->getError());
    }

    public function test_ask_missing_handler_class_returns_500(): void
    {
        $this->register('missing.class', 'bad', 'No\\Such\\HandlerClass');

        $result = $this->bus()->ask('missing.class');

        $this->assertTrue($result->isError());
        $this->assertSame(500, $result->getErrorCode());
    }

    // ============================================================
    // publish —— 发布-订阅
    // ============================================================

    public function test_publish_invokes_all_handlers(): void
    {
        BusPublishHandlerStub::$actions = [];
        $this->register('order.created', 'a', BusPublishHandlerStub::class);
        $this->register('order.created', 'b', BusPublishHandlerStub::class);

        $this->bus()->publish('order.created', ['id' => 1]);

        $this->assertSame(['order.created', 'order.created'], BusPublishHandlerStub::$actions);
    }

    public function test_publish_triggers_reply_callback_for_message_results(): void
    {
        $this->register('order.reply', 'replier', fn(Message $m) => Message::reply($m, ['ok' => true]));

        $replies = [];
        $this->bus()->publish('order.reply', [], function (Message $reply) use (&$replies) {
            $replies[] = $reply->getPayload();
        });

        $this->assertSame([['ok' => true]], $replies);
    }

    public function test_publish_does_not_invoke_callback_for_non_message_result(): void
    {
        $this->register('order.noreply', 'noop', fn() => 'plain');

        $called = false;
        $this->bus()->publish('order.noreply', [], function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);
    }

    public function test_publish_swallows_handler_exception_and_logs_warning(): void
    {
        $this->register('order.boom', 'boom', function (): void {
            throw new \RuntimeException('publish boom');
        });
        $this->register('order.boom', 'ok', fn() => 'fine');

        $this->bus()->publish('order.boom');

        $this->assertNotNull($this->logger->find('处理器 [boom] 处理 [order.boom] 失败'));
        $this->assertSame('warning', $this->logger->levelOf('处理器 [boom] 处理'));
    }

    // ============================================================
    // broadcast —— 广播
    // ============================================================

    public function test_broadcast_invokes_all_handlers(): void
    {
        BusBroadcastHandlerStub::$count = 0;
        $this->register('cache.invalidate', 'a', BusBroadcastHandlerStub::class);
        $this->register('cache.invalidate', 'b', BusBroadcastHandlerStub::class);

        $this->bus()->broadcast('cache.invalidate', ['tag' => 'x']);

        $this->assertSame(2, BusBroadcastHandlerStub::$count);
    }

    public function test_broadcast_single_handler_failure_does_not_stop_others(): void
    {
        BusBroadcastHandlerStub::$count = 0;
        $this->register('cache.boom', 'boom', function (): void {
            throw new \RuntimeException('broadcast boom');
        });
        $this->register('cache.boom', 'ok', BusBroadcastHandlerStub::class);

        $this->bus()->broadcast('cache.boom');

        $this->assertSame(1, BusBroadcastHandlerStub::$count);
        $this->assertSame('debug', $this->logger->levelOf('广播处理器 [boom] 处理'));
    }

    // ============================================================
    // listHandlers / canHandle
    // ============================================================

    public function test_can_handle_and_list_handlers(): void
    {
        $this->register('list.me', 'closure', fn() => null);
        $this->register('list.me', 'class', BusAskHandlerStub::class);

        $bus = $this->bus();

        $this->assertTrue($bus->canHandle('list.me'));
        $this->assertSame([
            'closure' => 'Closure',
            'class'   => BusAskHandlerStub::class,
        ], $bus->listHandlers('list.me'));
    }

    // ============================================================
    // async —— think-queue 异步分发
    // ============================================================

    public function test_async_publish_pushes_string_handlers_to_queue(): void
    {
        $queue = new BusQueueStub();
        Container::getInstance()->bind('queue', $queue);

        $this->register('async.push', 'job', BusAskHandlerStub::class);
        $this->register('async.push', 'closure', fn() => null);

        $this->bus()->async()->onQueue('high')->publish('async.push', ['id' => 5]);

        $this->assertCount(1, $queue->pushes);
        $this->assertSame(BusHandlerJob::class, $queue->pushes[0][0]);
        $this->assertSame('high', $queue->pushes[0][2]);
        $this->assertSame('async.push', $queue->pushes[0][1]['action']);
        $this->assertSame(BusAskHandlerStub::class, $queue->pushes[0][1]['handler_class']);
        $this->assertNotNull($this->logger->find('为闭包类型，无法异步分发'));
        $this->assertNotNull($this->logger->find('1 已推送, 1 已跳过'));
    }

    public function test_async_broadcast_pushes_handlers(): void
    {
        $queue = new BusQueueStub();
        Container::getInstance()->bind('queue', $queue);

        $this->register('async.broadcast', 'job', BusAskHandlerStub::class);

        $this->bus()->async()->broadcast('async.broadcast');

        $this->assertCount(1, $queue->pushes);
        $this->assertSame('default', $queue->pushes[0][2]);
    }

    public function test_async_mode_is_reset_after_publish(): void
    {
        $queue = new BusQueueStub();
        Container::getInstance()->bind('queue', $queue);

        BusPublishHandlerStub::$actions = [];
        $this->register('async.reset', 'stub', BusPublishHandlerStub::class);

        $bus = $this->bus();
        $bus->async()->publish('async.reset');
        $bus->publish('async.reset');

        $this->assertCount(1, $queue->pushes, '仅第一次应走异步');
        $this->assertSame(['async.reset'], BusPublishHandlerStub::$actions, '第二次应走同步');
    }

    public function test_sync_resets_async_mode(): void
    {
        $queue = new BusQueueStub();
        Container::getInstance()->bind('queue', $queue);

        BusPublishHandlerStub::$actions = [];
        $this->register('async.sync', 'stub', BusPublishHandlerStub::class);

        $this->bus()->async()->sync()->publish('async.sync');

        $this->assertSame([], $queue->pushes);
        $this->assertSame(['async.sync'], BusPublishHandlerStub::$actions);
    }

    public function test_async_skips_non_string_concrete(): void
    {
        $queue = new BusQueueStub();
        Container::getInstance()->bind('queue', $queue);

        $callable = [new BusPublishHandlerStub(), 'handle'];
        $this->register('async.callable', 'array', $callable);

        $this->bus()->async()->publish('async.callable');

        $this->assertSame([], $queue->pushes);
        $this->assertNotNull($this->logger->find('类型不支持异步分发'));
    }

    public function test_async_push_failure_is_logged(): void
    {
        $queue = new BusQueueStub();
        $queue->throwOnPush = true;
        Container::getInstance()->bind('queue', $queue);

        $this->register('async.fail', 'job', BusAskHandlerStub::class);

        $this->bus()->async()->publish('async.fail');

        $this->assertNotNull($this->logger->find('异步推送处理器 [job] 失败'));
        $this->assertSame('error', $this->logger->levelOf('异步推送处理器'));
    }

    public function test_async_without_handlers_logs_nothing(): void
    {
        $queue = new BusQueueStub();
        Container::getInstance()->bind('queue', $queue);

        $this->bus()->async()->publish('async.empty');

        $this->assertSame([], $queue->pushes);
        $this->assertSame([], $this->logger->records);
    }

    // ============================================================
    // 日志降级
    // ============================================================

    public function test_log_falls_back_to_error_log_without_logger(): void
    {
        $errorLog = ini_get('error_log');
        @unlink($errorLog);

        (new ServiceBus())->ask('no.logger.action');

        $this->assertStringContainsString('[BusServiceBus][warning]', (string) @file_get_contents($errorLog));
    }

    public function test_fluent_configuration_returns_self(): void
    {
        $bus = new ServiceBus();

        $this->assertSame($bus, $bus->async());
        $this->assertSame($bus, $bus->sync());
        $this->assertSame($bus, $bus->onQueue('q'));
    }
}

// ============================================================
// 测试替身
// ============================================================

class BusAskHandlerStub
{
    public static ?int $handled = null;

    public function handle(Message $message): Message
    {
        self::$handled = $message->getPayload()['id'] ?? null;

        return Message::reply($message, ['notified' => self::$handled]);
    }
}

class BusAskInvokableStub
{
    public function __invoke(Message $message): array
    {
        return ['invoked' => $message->getPayload()['id'] ?? null];
    }
}

class BusPublishHandlerStub
{
    /** @var string[] */
    public static array $actions = [];

    public function handle(Message $message): void
    {
        self::$actions[] = $message->getAction();
    }
}

class BusBroadcastHandlerStub
{
    public static int $count = 0;

    public function handle(Message $message): void
    {
        unset($message);
        self::$count++;
    }
}

class BusQueueStub
{
    /** @var array<int, array{0:mixed,1:array,2:?string}> */
    public array $pushes = [];
    public bool $throwOnPush = false;

    public function push(mixed $job, array $data = [], ?string $queue = null): string
    {
        if ($this->throwOnPush) {
            throw new \RuntimeException('queue unavailable');
        }
        $this->pushes[] = [$job, $data, $queue];

        return 'job-id';
    }
}

class BusLogStub implements \Psr\Log\LoggerInterface
{
    /** @var array<int, array{level:string,message:string}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }

    public function emergency(\Stringable|string $message, array $context = []): void { $this->log('emergency', $message, $context); }
    public function alert(\Stringable|string $message, array $context = []): void { $this->log('alert', $message, $context); }
    public function critical(\Stringable|string $message, array $context = []): void { $this->log('critical', $message, $context); }
    public function error(\Stringable|string $message, array $context = []): void { $this->log('error', $message, $context); }
    public function warning(\Stringable|string $message, array $context = []): void { $this->log('warning', $message, $context); }
    public function notice(\Stringable|string $message, array $context = []): void { $this->log('notice', $message, $context); }
    public function info(\Stringable|string $message, array $context = []): void { $this->log('info', $message, $context); }
    public function debug(\Stringable|string $message, array $context = []): void { $this->log('debug', $message, $context); }

    public function lastLevel(): string
    {
        return $this->records === [] ? '' : $this->records[count($this->records) - 1]['level'];
    }

    public function lastMessage(): string
    {
        return $this->records === [] ? '' : $this->records[count($this->records) - 1]['message'];
    }

    public function find(string $needle): ?string
    {
        foreach ($this->records as $record) {
            if (str_contains($record['message'], $needle)) {
                return $record['message'];
            }
        }
        return null;
    }

    public function levelOf(string $needle): string
    {
        foreach ($this->records as $record) {
            if (str_contains($record['message'], $needle)) {
                return $record['level'];
            }
        }
        return '';
    }
}
