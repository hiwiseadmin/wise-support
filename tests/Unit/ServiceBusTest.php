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

        $this->assertEquals(Message::TYPE_REQUEST, $msg->type);
        $this->assertEquals('test.action', $msg->action);
        $this->assertEquals(['data' => 1], $msg->payload);
    }

    public function test_message_reply_creates_correct_type(): void
    {
        $request = Message::request('test.action', []);
        $reply = Message::reply($request, ['result' => 'ok']);

        $this->assertEquals(Message::TYPE_REPLY, $reply->type);
        $this->assertSame($request->id, $reply->replyTo);
        $this->assertEquals(['result' => 'ok'], $reply->payload);
    }

    public function test_message_event_creates_correct_type(): void
    {
        $msg = Message::event('order.created', ['order_id' => 42]);

        $this->assertEquals(Message::TYPE_EVENT, $msg->type);
        $this->assertEquals('order.created', $msg->action);
    }

    public function test_message_error_creates_correct_type(): void
    {
        $request = Message::request('test.action', []);
        $error = Message::error($request, 'Something went wrong', 500);

        $this->assertEquals(Message::TYPE_ERROR, $error->type);
        $this->assertTrue($error->isError());
        $this->assertEquals('Something went wrong', $error->getError());
        $this->assertEquals(500, $error->getErrorCode());
    }

    public function test_message_unique_ids(): void
    {
        $msg1 = Message::request('action.a', []);
        $msg2 = Message::request('action.b', []);

        $this->assertNotEmpty($msg1->id);
        $this->assertNotEmpty($msg2->id);
        $this->assertNotEquals($msg1->id, $msg2->id);
    }

    public function test_message_to_array_and_from_array(): void
    {
        $original = Message::request('test.action', ['x' => 1], ['_handler' => 'foo']);
        $arr = $original->toArray();
        $restored = Message::fromArray($arr);

        $this->assertEquals($original->type, $restored->type);
        $this->assertEquals($original->action, $restored->action);
        $this->assertEquals($original->payload, $restored->payload);
        $this->assertEquals($original->context, $restored->context);
        $this->assertEquals($original->id, $restored->id);
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
                \wise\support\ExtPoint::register($point, $name, $item['concrete'], $item);
            }
        }

        return new ServiceBus();
    }
}
