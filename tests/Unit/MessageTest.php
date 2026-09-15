<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\bus\Message;

/**
 * bus\Message 服务总线消息单元测试
 *
 * 仅使用公开 API，验证消息创建、访问器与序列化。
 */
class MessageTest extends TestCase
{
    // ============================================================
    // 请求消息
    // ============================================================

    public function test_request_creates_request_type(): void
    {
        $message = Message::request('payment.charge', ['amount' => 100]);

        $this->assertTrue($message->isRequest());
        $this->assertFalse($message->isEvent());
        $this->assertFalse($message->isReply());
        $this->assertSame('payment.charge', $message->getAction());
    }

    public function test_request_has_payload_and_context(): void
    {
        $message = Message::request('x', ['a' => 1], ['trace' => 'id']);

        $this->assertSame(['a' => 1], $message->getPayload());
        $this->assertSame(['trace' => 'id'], $message->getContext());
    }

    public function test_request_generates_unique_id(): void
    {
        $m1 = Message::request('x');
        $m2 = Message::request('x');

        $this->assertNotSame($m1->getId(), $m2->getId());
    }

    public function test_request_has_timestamp(): void
    {
        $message = Message::request('x');

        $this->assertGreaterThan(0, $message->getTimestamp());
    }

    // ============================================================
    // 事件消息
    // ============================================================

    public function test_event_creates_event_type(): void
    {
        $message = Message::event('order.created', ['order_id' => 42]);

        $this->assertTrue($message->isEvent());
        $this->assertFalse($message->isRequest());
        $this->assertFalse($message->isReply());
        $this->assertSame('order.created', $message->getAction());
    }

    // ============================================================
    // 回复消息
    // ============================================================

    public function test_reply_creates_reply_type(): void
    {
        $original = Message::request('payment.charge');
        $reply = Message::reply($original, ['transaction_id' => 'tx_123']);

        $this->assertTrue($reply->isReply());
        $this->assertSame('payment.charge.reply', $reply->getAction());
    }

    public function test_reply_sets_correlation_id(): void
    {
        $original = Message::request('payment.charge');
        $reply = Message::reply($original);

        $this->assertSame($original->getId(), $reply->getCorrelationId());
        $this->assertSame($original->getId(), $reply->getReplyTo());
    }

    public function test_reply_merges_context(): void
    {
        $original = Message::request('x', [], ['trace' => 'abc']);
        $reply = Message::reply($original, [], ['extra' => 'val']);

        $this->assertSame(['trace' => 'abc', 'extra' => 'val'], $reply->getContext());
    }

    public function test_reply_with_empty_action_falls_back_to_unknown(): void
    {
        // 通过 fromArray 构造空 action 的原始消息
        $original = Message::fromArray(['type' => 'request', 'action' => '']);
        $reply = Message::reply($original);

        $this->assertSame('unknown.reply', $reply->getAction());
    }

    // ============================================================
    // 错误回复
    // ============================================================

    public function test_error_creates_error_reply(): void
    {
        $original = Message::request('payment.charge');
        $error = Message::error($original, '余额不足', 402);

        $this->assertTrue($error->isReply());
        $this->assertTrue($error->isError());
        $this->assertSame('余额不足', $error->getError());
        $this->assertSame(402, $error->getErrorCode());
    }

    public function test_error_correlates_to_original(): void
    {
        $original = Message::request('payment.charge');
        $error = Message::error($original, 'x');

        $this->assertSame($original->getId(), $error->getCorrelationId());
    }

    // ============================================================
    // 访问器
    // ============================================================

    public function test_get_returns_payload_value(): void
    {
        $message = Message::request('x', ['key' => 'value']);

        $this->assertSame('value', $message->get('key'));
    }

    public function test_get_returns_default_for_missing_key(): void
    {
        $message = Message::request('x', []);

        $this->assertNull($message->get('missing'));
        $this->assertSame('fallback', $message->get('missing', 'fallback'));
    }

    public function test_get_error_on_non_error_message(): void
    {
        $message = Message::request('x');

        $this->assertFalse($message->isError());
        $this->assertNull($message->getError());
        $this->assertSame(0, $message->getErrorCode());
    }

    // ============================================================
    // 序列化
    // ============================================================

    public function test_to_array_contains_all_fields(): void
    {
        $message = Message::request('payment.charge', ['amount' => 100], ['trace' => 't']);

        $array = $message->toArray();

        $this->assertSame($message->getId(), $array['id']);
        $this->assertSame('request', $array['type']);
        $this->assertSame('payment.charge', $array['action']);
        $this->assertSame(['amount' => 100], $array['payload']);
        $this->assertSame(['trace' => 't'], $array['context']);
        $this->assertNull($array['correlation_id']);
        $this->assertSame($message->getTimestamp(), $array['timestamp']);
    }

    public function test_from_array_restores_request(): void
    {
        $original = Message::request('payment.charge', ['amount' => 100], ['trace' => 't']);

        $restored = Message::fromArray($original->toArray());

        $this->assertSame($original->getId(), $restored->getId());
        $this->assertSame($original->getAction(), $restored->getAction());
        $this->assertSame($original->getPayload(), $restored->getPayload());
        $this->assertTrue($restored->isRequest());
    }

    public function test_from_array_restores_reply_correlation(): void
    {
        $original = Message::request('payment.charge');
        $reply = Message::reply($original, ['ok' => true]);

        $restored = Message::fromArray($reply->toArray());

        $this->assertTrue($restored->isReply());
        $this->assertSame($original->getId(), $restored->getCorrelationId());
    }

    public function test_from_array_defaults_missing_fields(): void
    {
        $message = Message::fromArray([]);

        $this->assertTrue($message->isEvent()); // 无效 type 回退到 event
        $this->assertSame('', $message->getAction());
        $this->assertSame([], $message->getPayload());
        $this->assertSame([], $message->getContext());
        $this->assertNull($message->getCorrelationId());
    }

    public function test_from_array_invalid_type_falls_back_to_event(): void
    {
        $message = Message::fromArray(['type' => 'bogus']);

        $this->assertTrue($message->isEvent());
    }

    public function test_from_array_accepts_all_valid_types(): void
    {
        $this->assertTrue(Message::fromArray(['type' => 'request'])->isRequest());
        $this->assertTrue(Message::fromArray(['type' => 'event'])->isEvent());
        $this->assertTrue(Message::fromArray(['type' => 'reply'])->isReply());
    }

    // ============================================================
    // 常量
    // ============================================================

    public function test_type_constants(): void
    {
        $this->assertSame('request', Message::TYPE_REQUEST);
        $this->assertSame('event', Message::TYPE_EVENT);
        $this->assertSame('reply', Message::TYPE_REPLY);
    }
}
