<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\ws\ChannelInterface;
use wise\support\ws\Connection;
use wise\support\ws\ConnectionManager;
use wise\support\ws\WsServerInterface;

/**
 * WebSocket 连接管理器 ConnectionManager 单元测试
 */
class ConnectionManagerTest extends TestCase
{
    private ConnectionManager $manager;
    private WsServerStub $server;
    private ArrayLoggerStub $logger;

    protected function setUp(): void
    {
        $this->server  = new WsServerStub();
        $this->logger  = new ArrayLoggerStub();
        $this->manager = (new ConnectionManager())
            ->setServer($this->server)
            ->setLogger($this->logger);
    }

    // ============================================================
    // onOpen / onClose
    // ============================================================

    public function test_on_open_registers_connection(): void
    {
        $ok = $this->manager->onOpen(1, ['user_id' => 42, 'ip' => '1.1.1.1', 'user_agent' => 'UA']);

        $this->assertTrue($ok);
        $this->assertTrue($this->manager->isOnline(1));
        $this->assertSame(1, $this->manager->count());

        $conn = $this->manager->getConnection(1);
        $this->assertInstanceOf(Connection::class, $conn);
        $this->assertSame(42, $conn->userId);
        $this->assertSame('1.1.1.1', $conn->ip);
        $this->assertSame('UA', $conn->userAgent);
    }

    public function test_on_open_without_user_id_skips_user_mapping(): void
    {
        $this->manager->onOpen(1);

        $this->assertSame([], $this->manager->getUserConnections(1));
        $this->assertNull($this->manager->getUserId(1));
    }

    public function test_on_open_same_fd_reconnect_cleans_old_user_mapping(): void
    {
        $this->manager->onOpen(1, ['user_id' => 42]);
        $this->manager->onOpen(1, ['user_id' => 43]);

        $this->assertSame([], $this->manager->getUserConnections(42));
        $this->assertSame([1], array_values($this->manager->getUserConnections(43)));
        $this->assertSame(1, $this->manager->count());
    }

    public function test_on_open_returns_false_when_max_connections_reached(): void
    {
        $this->manager->setMaxConnections(1);
        $this->manager->onOpen(1);

        $ok = $this->manager->onOpen(2);

        $this->assertFalse($ok);
        $this->assertFalse($this->manager->isOnline(2));
        $this->assertStringContainsString('达到最大连接数', $this->logger->lastMessage());
    }

    public function test_max_connections_zero_means_unlimited(): void
    {
        $this->manager->setMaxConnections(0);

        for ($fd = 1; $fd <= 5; $fd++) {
            $this->assertTrue($this->manager->onOpen($fd));
        }
        $this->assertSame(5, $this->manager->count());
    }

    public function test_on_close_removes_connection_and_mappings(): void
    {
        $this->manager->onOpen(1, ['user_id' => 42]);
        $this->manager->joinRoom(1, 'room_a');

        $this->manager->onClose(1);

        $this->assertFalse($this->manager->isOnline(1));
        $this->assertSame([], $this->manager->getUserConnections(42));
        $this->assertSame([], $this->manager->getRoomMembers('room_a'));
        $this->assertSame(0, $this->manager->count());
        $this->assertSame([], $this->manager->getRooms());
    }

    public function test_on_close_unknown_fd_is_noop(): void
    {
        $this->manager->onClose(999);

        $this->assertSame(0, $this->manager->count());
        $this->assertSame([], $this->logger->records);
    }

    public function test_on_close_only_clears_its_own_fd(): void
    {
        $this->manager->onOpen(1, ['user_id' => 42]);
        $this->manager->onOpen(2, ['user_id' => 42]);
        $this->manager->joinRoom(1, 'room_a');
        $this->manager->joinRoom(2, 'room_a');

        $this->manager->onClose(1);

        $this->assertSame([2], array_values($this->manager->getUserConnections(42)));
        $this->assertSame([2], array_values($this->manager->getRoomMembers('room_a')));
    }

    public function test_on_close_triggers_channel_on_leave(): void
    {
        $channel = new ChannelStub('chan_a');
        $this->manager->registerChannel($channel);
        $this->manager->onOpen(1);
        $this->manager->joinChannel(1, 'chan_a');

        $this->manager->onClose(1);

        $this->assertSame([1], $channel->left);
    }

    public function test_on_close_swallows_channel_exception_and_logs(): void
    {
        $channel = new ChannelStub('chan_a');
        $channel->throwOnLeave = true;
        $this->manager->registerChannel($channel);
        $this->manager->onOpen(1);
        $this->manager->joinChannel(1, 'chan_a');
        $this->logger->records = [];

        $this->manager->onClose(1);

        $this->assertFalse($this->manager->isOnline(1));
        // onClose 随后还会打一条 info「连接关闭」，故按内容定位错误日志
        $this->assertSame('error', $this->logger->findLevel('onLeave 失败'));
        $this->assertNotNull($this->logger->find('onLeave 失败'));
    }

    // ============================================================
    // 用户管理
    // ============================================================

    public function test_bind_user_moves_mapping(): void
    {
        $this->manager->onOpen(1, ['user_id' => 42]);

        $this->manager->bindUser(1, 43);

        $this->assertSame([], $this->manager->getUserConnections(42));
        $this->assertSame([1], array_values($this->manager->getUserConnections(43)));
        $this->assertSame(43, $this->manager->getUserId(1));
    }

    public function test_bind_user_on_unknown_fd_is_noop(): void
    {
        $this->manager->bindUser(999, 42);

        $this->assertSame([], $this->manager->getUserConnections(42));
    }

    public function test_is_user_online_and_connection_count(): void
    {
        $this->manager->onOpen(1, ['user_id' => 'u1']);
        $this->manager->onOpen(2, ['user_id' => 'u1']);

        $this->assertTrue($this->manager->isUserOnline('u1'));
        $this->assertSame(2, $this->manager->getUserConnectionCount('u1'));
        $this->assertFalse($this->manager->isUserOnline('u2'));
        $this->assertSame(0, $this->manager->getUserConnectionCount('u2'));
    }

    public function test_is_user_online_false_after_close(): void
    {
        $this->manager->onOpen(1, ['user_id' => 'u1']);
        $this->manager->onClose(1);

        $this->assertFalse($this->manager->isUserOnline('u1'));
    }

    // ============================================================
    // 房间管理
    // ============================================================

    public function test_join_room_is_idempotent_and_tracks_connection(): void
    {
        $this->manager->onOpen(1);
        $this->manager->joinRoom(1, 'room_a');
        $this->manager->joinRoom(1, 'room_a');

        $this->assertSame(1, $this->manager->getRoomCount('room_a'));
        $this->assertTrue($this->manager->getConnection(1)->isInRoom('room_a'));
    }

    public function test_join_room_on_unknown_fd_is_noop(): void
    {
        $this->manager->joinRoom(999, 'room_a');

        $this->assertSame(0, $this->manager->getRoomCount('room_a'));
    }

    public function test_leave_room(): void
    {
        $this->manager->onOpen(1);
        $this->manager->joinRoom(1, 'room_a');

        $this->manager->leaveRoom(1, 'room_a');

        $this->assertSame(0, $this->manager->getRoomCount('room_a'));
        $this->assertSame([], $this->manager->getRooms());
        $this->assertFalse($this->manager->getConnection(1)->isInRoom('room_a'));
    }

    public function test_leave_room_unknown_is_noop(): void
    {
        $this->manager->leaveRoom(1, 'nope');

        $this->assertSame([], $this->manager->getRooms());
    }

    public function test_get_room_members_skips_offline_fds(): void
    {
        $this->manager->onOpen(1);
        $this->manager->onOpen(2);
        $this->manager->joinRoom(1, 'room_a');
        $this->manager->joinRoom(2, 'room_a');
        // 直接摘除连接，模拟房间表中残留离线 fd
        $this->manager->onClose(2);
        $this->manager->joinRoom(1, 'room_a');

        $this->assertSame(1, $this->manager->getRoomCount('room_a'));
    }

    public function test_is_in_room(): void
    {
        $this->manager->onOpen(1, ['user_id' => 'u1']);
        $this->manager->joinRoom(1, 'room_a');

        $this->assertTrue($this->manager->isInRoom('u1', 'room_a'));
        $this->assertFalse($this->manager->isInRoom('u1', 'room_b'));
        $this->assertFalse($this->manager->isInRoom('u2', 'room_a'));
    }

    public function test_get_rooms_returns_member_counts(): void
    {
        $this->manager->onOpen(1);
        $this->manager->onOpen(2);
        $this->manager->joinRoom(1, 'room_a');
        $this->manager->joinRoom(2, 'room_a');
        $this->manager->joinRoom(2, 'room_b');

        $this->assertSame(['room_a' => 2, 'room_b' => 1], $this->manager->getRooms());
    }

    // ============================================================
    // 消息发送
    // ============================================================

    public function test_set_and_get_server(): void
    {
        $server = new WsServerStub();
        $manager = (new ConnectionManager())->setServer($server);

        $this->assertSame($server, $manager->getServer());
    }

    public function test_send_returns_false_when_offline(): void
    {
        $this->assertFalse($this->manager->send(1, 'hello'));
        $this->assertSame([], $this->server->sent);
    }

    public function test_send_pushes_message_to_server(): void
    {
        $this->manager->onOpen(1);

        $this->assertTrue($this->manager->send(1, 'hello'));
        $this->assertSame([[1, 'hello']], $this->server->sent);
    }

    public function test_send_without_server_still_returns_true(): void
    {
        $manager = new ConnectionManager();
        $manager->onOpen(1);

        $this->assertTrue($manager->send(1, 'hello'));
    }

    public function test_send_returns_false_when_server_throws(): void
    {
        $server = new WsServerStub();
        $server->throwOnSend = true;
        $manager = (new ConnectionManager())->setServer($server)->setLogger($this->logger);
        $manager->onOpen(1);

        $this->assertFalse($manager->send(1, 'hello'));
        $this->assertStringContainsString('发送消息到 fd=1 失败', $this->logger->lastMessage());
    }

    public function test_send_to_user_counts_successful_sends(): void
    {
        $this->manager->onOpen(1, ['user_id' => 42]);
        $this->manager->onOpen(2, ['user_id' => 42]);

        $sent = $this->manager->sendToUser(42, 'hi');

        $this->assertSame(2, $sent);
        $this->assertCount(2, $this->server->sent);
    }

    public function test_send_to_user_without_connections_returns_zero(): void
    {
        $this->assertSame(0, $this->manager->sendToUser('nobody', 'hi'));
    }

    public function test_send_to_room_excludes_sender(): void
    {
        $this->manager->onOpen(1);
        $this->manager->onOpen(2);
        $this->manager->joinRoom(1, 'room_a');
        $this->manager->joinRoom(2, 'room_a');

        $sent = $this->manager->sendToRoom('room_a', 'sync', 1);

        $this->assertSame(1, $sent);
        $this->assertSame([[2, 'sync']], $this->server->sent);
    }

    public function test_send_to_room_without_exclude_sends_to_all(): void
    {
        $this->manager->onOpen(1);
        $this->manager->onOpen(2);
        $this->manager->joinRoom(1, 'room_a');
        $this->manager->joinRoom(2, 'room_a');

        $this->assertSame(2, $this->manager->sendToRoom('room_a', 'sync'));
    }

    public function test_broadcast_sends_to_all_online(): void
    {
        $this->manager->onOpen(1);
        $this->manager->onOpen(2);
        $this->manager->onOpen(3);

        $this->assertSame(3, $this->manager->broadcast('all'));
    }

    public function test_broadcast_returns_zero_when_empty(): void
    {
        $this->assertSame(0, $this->manager->broadcast('all'));
    }

    // ============================================================
    // 心跳
    // ============================================================

    public function test_heartbeat_updates_timestamp(): void
    {
        $this->manager->onOpen(1);
        $this->manager->getConnection(1)->lastHeartbeat = time() - 120;

        $this->manager->heartbeat(1);

        $this->assertGreaterThanOrEqual(time() - 1, $this->manager->getLastHeartbeat(1));
    }

    public function test_heartbeat_unknown_fd_is_noop(): void
    {
        $this->manager->heartbeat(999);

        $this->assertSame(0, $this->manager->getLastHeartbeat(999));
    }

    public function test_check_heartbeat_returns_timeout_fds(): void
    {
        $this->manager->onOpen(1);
        $this->manager->onOpen(2);
        $this->manager->getConnection(1)->lastHeartbeat = time() - 300;

        $this->assertSame([1], $this->manager->checkHeartbeat(60));
    }

    public function test_check_heartbeat_returns_empty_when_all_alive(): void
    {
        $this->manager->onOpen(1);

        $this->assertSame([], $this->manager->checkHeartbeat(60));
    }

    // ============================================================
    // 频道管理
    // ============================================================

    public function test_register_and_get_channels(): void
    {
        $channel = new ChannelStub('chan_a');

        $this->assertSame($this->manager, $this->manager->registerChannel($channel));
        $this->assertSame($channel, $this->manager->getChannel('chan_a'));
        $this->assertNull($this->manager->getChannel('nope'));
        $this->assertSame(['chan_a' => $channel], $this->manager->getChannels());
    }

    public function test_join_channel_triggers_on_join(): void
    {
        $channel = new ChannelStub('chan_a');
        $this->manager->registerChannel($channel);
        $this->manager->onOpen(1);

        $this->assertTrue($this->manager->joinChannel(1, 'chan_a'));
        $this->assertSame([1], $channel->joined);
        $this->assertTrue($this->manager->getConnection(1)->isInChannel('chan_a'));
    }

    public function test_join_channel_fails_for_unknown_channel_or_offline_fd(): void
    {
        $this->manager->registerChannel(new ChannelStub('chan_a'));
        $this->manager->onOpen(1);

        $this->assertFalse($this->manager->joinChannel(1, 'missing'));
        $this->assertFalse($this->manager->joinChannel(999, 'chan_a'));
    }

    public function test_join_channel_swallows_on_join_exception(): void
    {
        $channel = new ChannelStub('chan_a');
        $channel->throwOnJoin = true;
        $this->manager->registerChannel($channel);
        $this->manager->onOpen(1);

        $this->assertTrue($this->manager->joinChannel(1, 'chan_a'));
        $this->assertStringContainsString('onJoin fd=1 失败', $this->logger->lastMessage());
    }

    public function test_leave_channel_triggers_on_leave(): void
    {
        $channel = new ChannelStub('chan_a');
        $this->manager->registerChannel($channel);
        $this->manager->onOpen(1);
        $this->manager->joinChannel(1, 'chan_a');

        $this->assertTrue($this->manager->leaveChannel(1, 'chan_a'));
        $this->assertSame([1], $channel->left);
        $this->assertFalse($this->manager->getConnection(1)->isInChannel('chan_a'));
    }

    public function test_leave_channel_fails_for_offline_fd(): void
    {
        $this->assertFalse($this->manager->leaveChannel(999, 'chan_a'));
    }

    public function test_leave_channel_without_registered_channel_still_detaches(): void
    {
        $this->manager->onOpen(1);
        $this->manager->getConnection(1)->addChannel('ghost');

        $this->assertTrue($this->manager->leaveChannel(1, 'ghost'));
        $this->assertFalse($this->manager->getConnection(1)->isInChannel('ghost'));
    }

    public function test_leave_channel_swallows_on_leave_exception(): void
    {
        $channel = new ChannelStub('chan_a');
        $channel->throwOnLeave = true;
        $this->manager->registerChannel($channel);
        $this->manager->onOpen(1);

        $this->assertTrue($this->manager->leaveChannel(1, 'chan_a'));
        $this->assertStringContainsString('onLeave fd=1 失败', $this->logger->lastMessage());
    }

    // ============================================================
    // 统计与清理
    // ============================================================

    public function test_stats_reports_totals(): void
    {
        $this->manager->registerChannel(new ChannelStub('chan_a'));
        $this->manager->onOpen(1, ['user_id' => 'u1']);
        $this->manager->onOpen(2, ['user_id' => 'u2']);
        $this->manager->joinRoom(1, 'room_a');

        $stats = $this->manager->stats();

        $this->assertSame(2, $stats['connections']);
        $this->assertSame(2, $stats['users']);
        $this->assertSame(1, $stats['rooms']);
        $this->assertSame(1, $stats['channels']);
    }

    public function test_total_connections_equals_current_count(): void
    {
        $this->manager->onOpen(1);
        $this->manager->onOpen(2);

        $this->assertSame(2, $this->manager->totalConnections());
    }

    public function test_clear_resets_all_state(): void
    {
        $this->manager->registerChannel(new ChannelStub('chan_a'));
        $this->manager->onOpen(1, ['user_id' => 'u1']);
        $this->manager->joinRoom(1, 'room_a');

        $this->manager->clear();

        $this->assertSame(0, $this->manager->count());
        $this->assertSame([], $this->manager->getRooms());
        $this->assertSame([], $this->manager->getChannels());
        $this->assertFalse($this->manager->isOnline(1));
    }

    public function test_set_logger_is_chainable(): void
    {
        $manager = new ConnectionManager();

        $this->assertSame($manager, $manager->setLogger($this->logger));
    }
}

// ============================================================
// 测试替身
// ============================================================

class WsServerStub implements WsServerInterface
{
    /** @var array<int, array{0:int,1:string}> */
    public array $sent = [];
    public bool $throwOnSend = false;

    public function start(): void {}
    public function stop(): void {}
    public function reload(): void {}

    public function send(int $fd, string $data): void
    {
        if ($this->throwOnSend) {
            throw new \RuntimeException('socket closed');
        }
        $this->sent[] = [$fd, $data];
    }

    public function close(int $fd, int $code = 1000, string $reason = ''): void {}
    public function count(): int { return count($this->sent); }
    public function isOnline(int $fd): bool { return true; }
    public function getFds(): array { return []; }
    public function getName(): string { return 'stub'; }
}

class ChannelStub implements ChannelInterface
{
    /** @var int[] */
    public array $joined = [];
    /** @var int[] */
    public array $left = [];
    /** @var array<int, array<int, mixed>> */
    public array $messages = [];
    public bool $throwOnJoin = false;
    public bool $throwOnLeave = false;

    public function __construct(private string $name)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function onMessage(int $fd, array $data): void
    {
        $this->messages[] = [$fd, $data];
    }

    public function onJoin(int $fd): void
    {
        if ($this->throwOnJoin) {
            throw new \RuntimeException('join failed');
        }
        $this->joined[] = $fd;
    }

    public function onLeave(int $fd): void
    {
        if ($this->throwOnLeave) {
            throw new \RuntimeException('leave failed');
        }
        $this->left[] = $fd;
    }
}

class ArrayLoggerStub implements \Psr\Log\LoggerInterface
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

    public function lastMessage(): string
    {
        return $this->records === [] ? '' : $this->records[count($this->records) - 1]['message'];
    }

    public function lastLevel(): string
    {
        return $this->records === [] ? '' : $this->records[count($this->records) - 1]['level'];
    }

    /**
     * 按内容查找日志级别
     */
    public function findLevel(string $needle): string
    {
        foreach ($this->records as $record) {
            if (str_contains($record['message'], $needle)) {
                return $record['level'];
            }
        }
        return '';
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
}
