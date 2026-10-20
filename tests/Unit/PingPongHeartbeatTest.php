<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\ws\handler\PingPongHeartbeat;

/**
 * WebSocket PING/PONG 心跳处理器单元测试
 */
class PingPongHeartbeatTest extends TestCase
{
    private PingPongHeartbeat $handler;

    protected function setUp(): void
    {
        $this->handler = new PingPongHeartbeat();
    }

    public function test_ping_returns_pong_with_timestamp(): void
    {
        $before = time();
        $reply = $this->handler->handle(1, '{"type":"ping"}');
        $after = time();

        $this->assertIsString($reply);
        $payload = json_decode($reply, true);

        $this->assertSame('pong', $payload['type']);
        $this->assertGreaterThanOrEqual($before, $payload['time']);
        $this->assertLessThanOrEqual($after, $payload['time']);
    }

    public function test_ping_with_extra_fields_still_answers(): void
    {
        $reply = $this->handler->handle(1, '{"type":"ping","seq":8}');

        $this->assertNotNull($reply);
        $this->assertSame('pong', json_decode($reply, true)['type']);
    }

    public function test_non_ping_type_returns_null(): void
    {
        $this->assertNull($this->handler->handle(1, '{"type":"pong"}'));
        $this->assertNull($this->handler->handle(1, '{"type":"other"}'));
    }

    public function test_missing_type_returns_null(): void
    {
        $this->assertNull($this->handler->handle(1, '{"action":"x"}'));
    }

    public function test_invalid_json_returns_null(): void
    {
        $this->assertNull($this->handler->handle(1, 'not-a-json'));
        $this->assertNull($this->handler->handle(1, ''));
    }

    public function test_json_scalar_returns_null(): void
    {
        // json_decode 得到非数组（标量）时视为不识别
        $this->assertNull($this->handler->handle(1, '"ping"'));
        $this->assertNull($this->handler->handle(1, '123'));
    }

    public function test_type_is_case_sensitive(): void
    {
        $this->assertNull($this->handler->handle(1, '{"type":"PING"}'));
    }

    public function test_reply_is_unicode_unescaped_json(): void
    {
        $reply = $this->handler->handle(1, '{"type":"ping"}');

        $this->assertStringNotContainsString('\\u', $reply);
    }
}
