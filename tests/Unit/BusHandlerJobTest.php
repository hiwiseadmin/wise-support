<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use think\Container;
use wise\support\ExtPoint;
use wise\support\bus\BusHandlerJob;
use wise\support\bus\Message;

/**
 * 服务总线异步作业 BusHandlerJob 单元测试
 *
 * 注意：fire() 现在要求 handler_class 必须是 payload['action'] 对应扩展点
 * （'bus.handler.{action}'）的已注册实现，因此 setUp 里注册了各夹具类。
 */
class BusHandlerJobTest extends TestCase
{
    private const POINT = 'bus.handler.order.created';

    private BusHandlerJob $job;

    protected function setUp(): void
    {
        ExtPoint::reset();
        ExtPoint::definePoint(self::POINT, ExtPoint::POLICY_MULTI);

        // 注册夹具：与 ServiceBus::dispatchAsync 写入 concrete 的行为一致
        ExtPoint::register(self::POINT, 'msg_handler', BusMessageHandlerStub::class);
        ExtPoint::register(self::POINT, 'invokable', BusInvokableHandlerStub::class);
        ExtPoint::register(self::POINT, 'throwing', BusThrowingHandlerStub::class);
        ExtPoint::register(self::POINT, 'invalid', BusInvalidHandlerStub::class);
        // 用于验证「已注册但类不存在」仍走 callHandler 的原有报错分支
        ExtPoint::register(self::POINT, 'missing', 'No\\Such\\HandlerClass');

        $this->job = new BusHandlerJob();
    }

    protected function tearDown(): void
    {
        ExtPoint::reset();
    }

    private function payload(string $handlerClass, array $overrides = []): array
    {
        return array_merge([
            'action'        => 'order.created',
            'handler_class' => $handlerClass,
            'message'       => Message::request('order.created', ['id' => 7])->toArray(),
        ], $overrides);
    }

    // ============================================================
    // handler_class 校验
    // ============================================================

    public function test_missing_handler_class_deletes_job_and_throws(): void
    {
        $job = new QueueJobStub();

        try {
            $this->job->fire($job, ['message' => []]);
            $this->fail('handler_class 为空应抛出 RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('handler_class 为空', $e->getMessage());
        }

        $this->assertSame(1, $job->deleteCount);
        $this->assertSame(0, $job->releaseCount);
    }

    // ============================================================
    // 成功路径
    // ============================================================

    public function test_handle_style_handler_is_invoked_and_job_deleted(): void
    {
        $job = new QueueJobStub();

        $this->job->fire($job, $this->payload(BusMessageHandlerStub::class));

        $this->assertSame(1, $job->deleteCount);
        $this->assertSame(0, $job->releaseCount);
        $this->assertSame(0, $job->failedCount);
        $this->assertSame('order.created', BusMessageHandlerStub::$lastAction);
        $this->assertSame(['id' => 7], BusMessageHandlerStub::$lastPayload);
    }

    public function test_invokable_handler_is_invoked(): void
    {
        $job = new QueueJobStub();

        $this->job->fire($job, $this->payload(BusInvokableHandlerStub::class));

        $this->assertSame(1, $job->deleteCount);
        $this->assertSame(0, $job->releaseCount);
        $this->assertSame('order.created', BusInvokableHandlerStub::$lastAction);
    }

    // ============================================================
    // 失败与重试
    // ============================================================

    public function test_unknown_handler_class_is_released_for_retry(): void
    {
        $job = new QueueJobStub();
        $job->attemptsValue = 1;

        $this->job->fire($job, $this->payload('No\\Such\\HandlerClass'));

        $this->assertSame(1, $job->releaseCount);
        $this->assertSame(10, $job->releasedDelays[0]);
        $this->assertSame(0, $job->deleteCount);
    }

    public function test_custom_retry_delay_is_used(): void
    {
        $job = new QueueJobStub();
        $job->attemptsValue = 1;

        $this->job->fire($job, $this->payload('No\\Such\\HandlerClass', ['retry_delay' => 25]));

        $this->assertSame([25], $job->releasedDelays);
    }

    public function test_job_is_failed_after_max_attempts(): void
    {
        $job = new QueueJobStub();
        $job->attemptsValue = 3;

        $this->job->fire($job, $this->payload('No\\Such\\HandlerClass', ['max_attempts' => 3]));

        $this->assertSame(0, $job->releaseCount);
        $this->assertSame(1, $job->failedCount);
        $this->assertSame(1, $job->deleteCount);
        $this->assertStringContainsString('不存在', $job->failures[0]->getMessage());
    }

    public function test_custom_max_attempts_is_respected(): void
    {
        $job = new QueueJobStub();
        $job->attemptsValue = 2;

        $this->job->fire($job, $this->payload('No\\Such\\HandlerClass', ['max_attempts' => 5]));

        $this->assertSame(1, $job->releaseCount, '未达自定义上限时应继续重试');
        $this->assertSame(0, $job->failedCount);
    }

    public function test_handler_without_handle_or_invoke_is_released(): void
    {
        $job = new QueueJobStub();
        $job->attemptsValue = 1;

        $this->job->fire($job, $this->payload(BusInvalidHandlerStub::class));

        $this->assertSame(1, $job->releaseCount);
        $this->assertSame(0, $job->deleteCount);
    }

    public function test_partial_message_payload_is_tolerated(): void
    {
        $job = new QueueJobStub();
        BusMessageHandlerStub::$lastAction = null;

        // Message::fromArray() 对缺字段做兜底（type 回落 event、action 为空），不抛异常
        $this->job->fire($job, [
            'action'        => 'order.created',
            'handler_class' => BusMessageHandlerStub::class,
            'message'       => ['unexpected' => 'shape'],
        ]);

        $this->assertSame(1, $job->deleteCount);
        $this->assertSame(0, $job->releaseCount);
        $this->assertSame('', BusMessageHandlerStub::$lastAction);
    }

    public function test_handler_throwing_exception_is_released(): void
    {
        $job = new QueueJobStub();
        $job->attemptsValue = 1;

        $this->job->fire($job, $this->payload(BusThrowingHandlerStub::class));

        $this->assertSame(1, $job->releaseCount);
        $this->assertSame(0, $job->deleteCount);
    }

    // ============================================================
    // 注册集约束（handler_class 不得由载荷自选任意类）
    // ============================================================

    public function test_existing_but_unregistered_handler_is_rejected(): void
    {
        $job = new QueueJobStub();
        BusUnregisteredHandlerStub::$invocations = 0;

        try {
            $this->job->fire($job, $this->payload(BusUnregisteredHandlerStub::class));
            $this->fail('未注册的处理器应被拒绝');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('不是操作', $e->getMessage());
        }

        $this->assertSame(1, $job->deleteCount);
        $this->assertSame(0, $job->releaseCount);
        $this->assertSame(0, BusUnregisteredHandlerStub::$invocations, '未注册处理器不得被实例化调用');
    }

    public function test_handler_for_other_action_is_rejected(): void
    {
        $job = new QueueJobStub();
        BusMessageHandlerStub::$lastAction = null;

        // 同一个类注册在 order.created 上，但载荷声明的 action 是另一个
        try {
            $this->job->fire($job, [
                'action'        => 'order.paid',
                'handler_class' => BusMessageHandlerStub::class,
                'message'       => Message::request('order.paid', [])->toArray(),
            ]);
            $this->fail('跨 action 的处理器应被拒绝');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('order.paid', $e->getMessage());
        }

        $this->assertNull(BusMessageHandlerStub::$lastAction);
        $this->assertSame(1, $job->deleteCount);
    }

    // ============================================================
    // 幂等：同一 message id 的重投递不得重复执行副作用
    // ============================================================

    public function test_redelivered_message_id_does_not_re_run_handler(): void
    {
        Container::getInstance()->bind('cache', new BusDedupCacheStub());
        BusMessageHandlerStub::$invocations = 0;

        try {
            // 同一个 payload（同一 message id）投递两次
            $payload = $this->payload(BusMessageHandlerStub::class);

            $first = new QueueJobStub();
            $this->job->fire($first, $payload);
            $this->assertSame(1, $first->deleteCount);
            $this->assertSame(1, BusMessageHandlerStub::$invocations);

            $second = new QueueJobStub();
            $this->job->fire($second, $payload);

            $this->assertSame(1, BusMessageHandlerStub::$invocations, '同一 message id 只应执行一次');
            $this->assertSame(1, $second->deleteCount, '重复投递应直接确认');
            $this->assertSame(0, $second->releaseCount);
        } finally {
            Container::getInstance()->delete('cache');
        }
    }

    public function test_different_message_ids_both_execute(): void
    {
        Container::getInstance()->bind('cache', new BusDedupCacheStub());
        BusMessageHandlerStub::$invocations = 0;

        try {
            // 两次 payload() 各自生成新的 message id → 都应执行
            $this->job->fire(new QueueJobStub(), $this->payload(BusMessageHandlerStub::class));
            $this->job->fire(new QueueJobStub(), $this->payload(BusMessageHandlerStub::class));

            $this->assertSame(2, BusMessageHandlerStub::$invocations);
        } finally {
            Container::getInstance()->delete('cache');
        }
    }
}

// ============================================================
// 测试替身与夹具
// ============================================================

class QueueJobStub extends \think\queue\Job
{
    public int $deleteCount = 0;
    public int $releaseCount = 0;
    public int $failedCount = 0;
    public int $attemptsValue = 1;
    /** @var int[] */
    public array $releasedDelays = [];
    /** @var \Throwable[] */
    public array $failures = [];

    public function getJobId(): string
    {
        return 'job-1';
    }

    public function attempts(): int
    {
        return $this->attemptsValue;
    }

    public function getRawBody(): string
    {
        return '{}';
    }

    public function delete(): void
    {
        $this->deleteCount++;
    }

    public function release($delay = 0): void
    {
        $this->releaseCount++;
        $this->releasedDelays[] = $delay;
    }

    public function failed($e): void
    {
        $this->failedCount++;
        $this->failures[] = $e;
    }
}

class BusMessageHandlerStub
{
    public static ?string $lastAction = null;
    public static array $lastPayload = [];
    public static int $invocations = 0;

    public function handle(Message $message): Message
    {
        self::$invocations++;
        self::$lastAction  = $message->getAction();
        self::$lastPayload = $message->getPayload();

        return Message::reply($message, ['ok' => true]);
    }
}

/**
 * 缓存替身：仅实现 BusHandlerJob 幂等守卫所需的 get/set
 */
class BusDedupCacheStub
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->data[$key] = $value;
        return true;
    }
}

class BusInvokableHandlerStub
{
    public static ?string $lastAction = null;

    public function __invoke(Message $message): string
    {
        self::$lastAction = $message->getAction();

        return 'invoked';
    }
}

class BusThrowingHandlerStub
{
    public function handle(Message $message): void
    {
        unset($message);
        throw new \RuntimeException('handler failed');
    }
}

class BusInvalidHandlerStub
{
    public function somethingElse(): void
    {
    }
}

class BusUnregisteredHandlerStub
{
    public static int $invocations = 0;

    public function handle(Message $message): void
    {
        unset($message);
        self::$invocations++;
    }
}
