<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\ws\Connection;

/**
 * WebSocket 连接实体 Connection 单元测试
 */
class ConnectionTest extends TestCase
{
    // ============================================================
    // 构造与默认值
    // ============================================================

    public function test_constructor_assigns_fields(): void
    {
        $conn = new Connection(
            fd: 7,
            userId: 42,
            ip: '127.0.0.1',
            userAgent: 'phpunit',
            metadata: ['user_id' => 42, 'extra' => 'x'],
        );

        $this->assertSame(7, $conn->fd);
        $this->assertSame(42, $conn->userId);
        $this->assertSame('127.0.0.1', $conn->ip);
        $this->assertSame('phpunit', $conn->userAgent);
        $this->assertSame(['user_id' => 42, 'extra' => 'x'], $conn->metadata);
    }

    public function test_constructor_defaults(): void
    {
        $conn = new Connection(1);

        $this->assertNull($conn->userId);
        $this->assertSame('', $conn->ip);
        $this->assertSame('', $conn->userAgent);
        $this->assertSame([], $conn->metadata);
        $this->assertSame([], $conn->getRooms());
        $this->assertSame([], $conn->getChannels());
    }

    public function test_constructor_initializes_timestamps(): void
    {
        $before = time();
        $conn = new Connection(1);
        $after = time();

        $this->assertGreaterThanOrEqual($before, $conn->connectedAt);
        $this->assertLessThanOrEqual($after, $conn->connectedAt);
        $this->assertSame($conn->connectedAt, $conn->lastHeartbeat);
    }

    public function test_get_duration_is_non_negative(): void
    {
        $conn = new Connection(1);

        $this->assertGreaterThanOrEqual(0, $conn->getDuration());
    }

    // ============================================================
    // 心跳
    // ============================================================

    public function test_heartbeat_updates_last_heartbeat(): void
    {
        $conn = new Connection(1);
        $conn->lastHeartbeat = time() - 100;

        $conn->heartbeat();

        $this->assertGreaterThanOrEqual(time() - 1, $conn->lastHeartbeat);
    }

    public function test_is_heartbeat_timeout_returns_true_when_elapsed(): void
    {
        $conn = new Connection(1);
        $conn->lastHeartbeat = 1000;

        // now - lastHeartbeat = 61 > 60
        $this->assertTrue($conn->isHeartbeatTimeout(60, 1061));
    }

    public function test_is_heartbeat_timeout_returns_false_on_boundary(): void
    {
        $conn = new Connection(1);
        $conn->lastHeartbeat = 1000;

        // 恰好等于超时阈值时不算超时（判断为 > 而非 >=）
        $this->assertFalse($conn->isHeartbeatTimeout(60, 1060));
        $this->assertTrue($conn->isHeartbeatTimeout(60, 1061));
    }

    public function test_is_heartbeat_timeout_uses_current_time_by_default(): void
    {
        $conn = new Connection(1);
        $conn->lastHeartbeat = time();

        $this->assertFalse($conn->isHeartbeatTimeout(60));
    }

    // ============================================================
    // 房间
    // ============================================================

    public function test_add_room_and_query(): void
    {
        $conn = new Connection(1);

        $conn->addRoom('room_a');
        $conn->addRoom('room_b');

        $this->assertSame(['room_a', 'room_b'], $conn->getRooms());
        $this->assertTrue($conn->isInRoom('room_a'));
        $this->assertFalse($conn->isInRoom('room_c'));
    }

    public function test_add_room_is_idempotent(): void
    {
        $conn = new Connection(1);
        $conn->addRoom('room_a');
        $conn->addRoom('room_a');

        $this->assertCount(1, $conn->getRooms());
    }

    public function test_remove_room(): void
    {
        $conn = new Connection(1);
        $conn->addRoom('room_a');
        $conn->addRoom('room_b');

        $conn->removeRoom('room_a');

        $this->assertSame(['room_b'], $conn->getRooms());
        $this->assertFalse($conn->isInRoom('room_a'));
    }

    public function test_remove_unknown_room_keeps_state(): void
    {
        $conn = new Connection(1);
        $conn->addRoom('room_a');

        $conn->removeRoom('nope');

        $this->assertSame(['room_a'], $conn->getRooms());
    }

    // ============================================================
    // 频道
    // ============================================================

    public function test_add_channel_and_query(): void
    {
        $conn = new Connection(1);

        $conn->addChannel('game.frame_sync');
        $conn->addChannel('game.frame_sync');

        $this->assertSame(['game.frame_sync'], $conn->getChannels());
        $this->assertTrue($conn->isInChannel('game.frame_sync'));
        $this->assertFalse($conn->isInChannel('other'));
    }

    public function test_remove_channel(): void
    {
        $conn = new Connection(1);
        $conn->addChannel('a');
        $conn->addChannel('b');

        $conn->removeChannel('a');

        $this->assertSame(['b'], $conn->getChannels());
        $this->assertFalse($conn->isInChannel('a'));
    }

    // ============================================================
    // 序列化
    // ============================================================

    public function test_to_array_contains_all_fields(): void
    {
        $conn = new Connection(9, 'u-1', '10.0.0.1', 'UA', ['k' => 'v']);
        $conn->addRoom('room_a');
        $conn->addChannel('chan_a');

        $data = $conn->toArray();

        $this->assertSame(9, $data['fd']);
        $this->assertSame('u-1', $data['user_id']);
        $this->assertSame('10.0.0.1', $data['ip']);
        $this->assertSame('UA', $data['user_agent']);
        $this->assertSame($conn->connectedAt, $data['connected_at']);
        $this->assertSame($conn->lastHeartbeat, $data['last_heartbeat']);
        $this->assertIsInt($data['duration']);
        $this->assertSame(['room_a'], $data['rooms']);
        $this->assertSame(['chan_a'], $data['channels']);
    }
}
