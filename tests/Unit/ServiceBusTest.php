<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\bus\Message;
use wise\support\bus\ServiceBus;

/**
 * ServiceBus 服务总线单元测试
 *
 * 测试 ask/publish/broadcast 三种通信模式和 Message 值对象。
 */
class ServiceBusTest extends TestCase
{
    // ============================================================
    // Message 值对象
    // ============================================================

    public function test_message_request_creates_correct_type(): void
    {
        $msg = Message::request('test.action', ['data' => 1]);

        $this->assertEquals(Message::TYPE_REQUEST, $msg->getType());
        $this->assertEquals('test.action', $msg->getAction());
        $this->assertEquals(['data' => 1], $msg->getPayload());
        $this->assertTrue($msg->isRequest());
    }

    public function test_message_reply_creates_correct_type(): void
    {
        $request = Message::request('test.action', []);
        $reply = Message::reply($request, ['result' => 'ok']);

        $this->assertEquals(Message::TYPE_REPLY, $reply->getType());
        $this->assertSame($request->getId(), $reply->getCorrelationId());
        $this->assertEquals(['result' => 'ok'], $reply->getPayload());
        $this->assertTrue($reply->isReply());
    }

    public function test_message_event_creates_correct_type(): void
    {
        $msg = Message::event('order.created', ['order_id' => 42]);

        $this->assertEquals(Message::TYPE_EVENT, $msg->getType());
        $this->assertEquals('order.created', $msg->getAction());
        $this->assertTrue($msg->isEvent());
    }

    public function test_message_error_creates_correct_type(): void
    {
        $request = Message::request('test.action', []);
        $error = Message::error($request, 'Something went wrong', 500);

        // 错误消息以「回复」类型承载，通过 payload 中的 __error 标记区分
        $this->assertEquals(Message::TYPE_REPLY, $error->getType());
        $this->assertTrue($error->isReply());
        $this->assertTrue($error->isError());
        $this->assertEquals('Something went wrong', $error->getError());
        $this->assertEquals(500, $error->getErrorCode());
        $this->assertSame($request->getId(), $error->getReplyTo());
    }

    public function test_message_unique_ids(): void
    {
        $msg1 = Message::request('action.a', []);
        $msg2 = Message::request('action.b', []);

        $this->assertNotEmpty($msg1->getId());
        $this->assertNotEmpty($msg2->getId());
        $this->assertNotEquals($msg1->getId(), $msg2->getId());
    }

    public function test_message_to_array_and_from_array(): void
    {
        $original = Message::request('test.action', ['x' => 1], ['_handler' => 'foo']);
        $arr = $original->toArray();
        $restored = Message::fromArray($arr);

        $this->assertEquals($original->getType(), $restored->getType());
        $this->assertEquals($original->getAction(), $restored->getAction());
        $this->assertEquals($original->getPayload(), $restored->getPayload());
        $this->assertEquals($original->getContext(), $restored->getContext());
        $this->assertEquals($original->getId(), $restored->getId());
    }

    // ============================================================
    // ServiceBus — empty handlers
    // ============================================================

    public function test_ask_no_handlers_returns_error(): void
    {
        $bus = new ServiceBus();
        $result = $bus->ask('nonexistent.action', ['data' => 1]);

        $this->assertTrue($result->isError());
        $this->assertEquals(404, $result->getErrorCode());
    }

    public function test_can_handle_returns_false_for_unknown_action(): void
    {
        $bus = new ServiceBus();

        $this->assertFalse($bus->canHandle('unknown.action'));
    }

    public function test_list_handlers_returns_empty_for_unknown_action(): void
    {
        $bus = new ServiceBus();

        $this->assertEquals([], $bus->listHandlers('unknown.action'));
    }

    public function test_publish_no_handlers_does_not_throw(): void
    {
        $bus = new ServiceBus();

        // Should not throw
        $bus->publish('nonexistent.action', ['data' => 1]);
        $this->assertTrue(true);
    }

    public function test_broadcast_no_handlers_does_not_throw(): void
    {
        $bus = new ServiceBus();

        // Should not throw
        $bus->broadcast('nonexistent.action', ['data' => 1]);
        $this->assertTrue(true);
    }

    // ============================================================
    // ServiceBus — setLogger
    // ============================================================

    public function test_set_logger_returns_self(): void
    {
        $bus = new ServiceBus();
        $mockLogger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $result = $bus->setLogger($mockLogger);

        $this->assertSame($bus, $result);
    }

    // ============================================================
    // ServiceBus — ask with handler edge cases
    // ============================================================

    public function test_ask_invalid_handler_class_returns_error(): void
    {
        $bus = $this->makeBusWithHandlers([
            'bus.handler.test.action' => [
                'bad_handler' => ['concrete' => 'NonExistentClass', 'active' => false, 'priority' => 100],
            ],
        ]);

        $result = $bus->ask('test.action');

        $this->assertTrue($result->isError());
        $this->assertEquals(500, $result->getErrorCode());
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * 创建一个预先注入了处理器的 ServiceBus
     * 通过反射将处理器注入 ExtPoint 的静态注册表
     */
    private function makeBusWithHandlers(array $handlers): ServiceBus
    {
        // 使用 \wise\support\ExtPoint 注册处理器
        foreach ($handlers as $point => $items) {
            if (!\wise\support\ExtPoint::defined($point)) {
                \wise\support\ExtPoint::definePoint($point, \wise\support\ExtPoint::POLICY_MULTI);
            }
            foreach ($items as $name => $item) {
                // register() 第 4 参是 int 优先级，不是元数据数组
                \wise\support\ExtPoint::register(
                    $point,
                    $name,
                    $item['concrete'],
                    (int) ($item['priority'] ?? 100)
                );
            }
        }

        return new ServiceBus();
    }
}
