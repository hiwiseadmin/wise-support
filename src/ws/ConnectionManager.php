<?php
declare(strict_types=1);

namespace wise\support\ws;

use Psr\Log\LoggerInterface;

/**
 * WebSocket 连接管理器
 *
 * 不负责建立底层 Socket 连接（那是 Swoole/Workerman 的事），
 * 而是管理连接元数据：fd ↔ 用户映射、房间/频道管理、心跳状态、消息发送。
 *
 * 各 wise-* 包通过此管理器实现：
 * - 按用户推送消息（sendToUser）
 * - 按房间广播（sendToRoom / broadcast）
 * - 查询用户在线状态（isUserOnline）
 *
 * 使用示例：
 * <pre>
 * $manager = app(ConnectionManager::class);
 *
 * // 连接建立时
 * $manager->onOpen($fd, ['user_id' => 42, 'ip' => '127.0.0.1']);
 *
 * // 给指定用户推送
 * $manager->sendToUser(42, json_encode(['action' => 'notification', 'msg' => 'hello']));
 *
 * // 加入房间
 * $manager->joinRoom($fd, 'game_room_123');
 * $manager->sendToRoom('game_room_123', json_encode(['action' => 'player_joined']));
 *
 * // 心跳
 * $manager->heartbeat($fd);
 * $timeoutFds = $manager->checkHeartbeat(60); // 60秒无心跳的 fd
 *
 * // 连接关闭
 * $manager->onClose($fd);
 * </pre>
 *
 * @package wise\support\ws
 */
class ConnectionManager
{
    /**
     * 连接信息池：fd → Connection
     *
     * @var array<int, Connection>
     */
    protected array $connections = [];

    /**
     * 用户 ID → fd[] 映射
     *
     * @var array<string|int, int[]>
     */
    protected array $userToFds = [];

    /**
     * 房间名 → fd[] 映射
     *
     * @var array<string, int[]>
     */
    protected array $rooms = [];

    /**
     * 频道名 → Channel 接口
     *
     * @var array<string, ChannelInterface>
     */
    protected array $channels = [];

    /**
     * WebSocket 服务端引用（用于底层 send/close 操作）
     */
    protected ?WsServerInterface $server = null;

    /**
     * 日志记录器
     */
    protected ?LoggerInterface $logger = null;

    /**
     * 最大连接数（0 表示无限制）
     */
    protected int $maxConnections = 0;

    // ============================================================
    // 连接生命周期
    // ============================================================

    /**
     * 新连接建立
     *
     * @param int   $fd   连接 fd
     * @param array $meta 连接元数据（user_id, ip, user_agent 等）
     * @return bool 是否成功（超出最大连接数时拒绝）
     */
    public function onOpen(int $fd, array $meta = []): bool
    {
        // 如果 fd 已存在（重连场景），先清理旧状态
        if (isset($this->connections[$fd])) {
            $oldUserId = $this->connections[$fd]->userId;
            if ($oldUserId !== null && isset($this->userToFds[$oldUserId])) {
                $this->userToFds[$oldUserId] = array_values(
                    array_filter($this->userToFds[$oldUserId], fn(int $f) => $f !== $fd)
                );
                if (empty($this->userToFds[$oldUserId])) {
                    unset($this->userToFds[$oldUserId]);
                }
            }
            unset($this->connections[$fd]);
        }

        // 连接数限制检查
        if ($this->maxConnections > 0 && count($this->connections) >= $this->maxConnections) {
            $this->log('warning', "ConnectionManager: 达到最大连接数 {$this->maxConnections}，拒绝 fd={$fd}");
            return false;
        }

        $connection = new Connection(
            fd: $fd,
            userId: $meta['user_id'] ?? null,
            ip: $meta['ip'] ?? '',
            userAgent: $meta['user_agent'] ?? '',
            metadata: $meta,
        );

        $this->connections[$fd] = $connection;

        // 建立用户映射
        if ($connection->userId !== null) {
            $this->userToFds[$connection->userId][] = $fd;
            $this->userToFds[$connection->userId] = array_unique($this->userToFds[$connection->userId]);
        }

        $this->log('info', "ConnectionManager: 连接建立 fd={$fd}, userId={$connection->userId}");

        return true;
    }

    /**
     * 连接关闭
     *
     * @param int $fd 连接 fd
     */
    public function onClose(int $fd): void
    {
        $connection = $this->connections[$fd] ?? null;
        if ($connection === null) {
            return;
        }

        // 从用户映射中移除
        if ($connection->userId !== null && isset($this->userToFds[$connection->userId])) {
            $this->userToFds[$connection->userId] = array_values(
                array_filter($this->userToFds[$connection->userId], fn(int $f) => $f !== $fd)
            );
            if (empty($this->userToFds[$connection->userId])) {
                unset($this->userToFds[$connection->userId]);
            }
        }

        // 从所有房间中移除
        foreach ($this->rooms as $room => &$fds) {
            $fds = array_values(array_filter($fds, fn(int $f) => $f !== $fd));
            if (empty($fds)) {
                unset($this->rooms[$room]);
            }
        }
        unset($fds);

        // 从连接的频道中通知离开
        foreach ($connection->getChannels() as $channelName) {
            if (isset($this->channels[$channelName])) {
                try {
                    $this->channels[$channelName]->onLeave($fd);
                } catch (\Throwable $e) {
                    $this->log('error', "ConnectionManager: 频道 [{$channelName}] onLeave 失败: {$e->getMessage()}");
                }
            }
        }

        $this->log('info', "ConnectionManager: 连接关闭 fd={$fd}, userId={$connection->userId}, duration={$connection->getDuration()}s");

        unset($this->connections[$fd]);
    }

    // ============================================================
    // 用户管理
    // ============================================================

    /**
     * 绑定用户到连接
     *
     * 通常在用户认证完成后调用。
     *
     * @param int         $fd     连接 fd
     * @param string|int  $userId 用户 ID
     */
    public function bindUser(int $fd, string|int $userId): void
    {
        $connection = $this->connections[$fd] ?? null;
        if ($connection === null) {
            return;
        }

        // 从旧用户映射中移除
        if ($connection->userId !== null && isset($this->userToFds[$connection->userId])) {
            $this->userToFds[$connection->userId] = array_values(
                array_filter($this->userToFds[$connection->userId], fn(int $f) => $f !== $fd)
            );
        }

        $connection->userId = $userId;
        $this->userToFds[$userId][] = $fd;
        $this->userToFds[$userId] = array_unique($this->userToFds[$userId]);

        $this->log('debug', "ConnectionManager: fd={$fd} 绑定用户 userId={$userId}");
    }

    /**
     * 获取指定用户的在线连接 fd 列表
     *
     * @return int[]
     */
    public function getUserConnections(string|int $userId): array
    {
        return $this->userToFds[$userId] ?? [];
    }

    /**
     * 检查用户是否在线
     */
    public function isUserOnline(string|int $userId): bool
    {
        $fds = $this->userToFds[$userId] ?? [];
        foreach ($fds as $fd) {
            if ($this->isOnline($fd)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 获取用户的连接数
     */
    public function getUserConnectionCount(string|int $userId): int
    {
        $fds = $this->userToFds[$userId] ?? [];
        $count = 0;
        foreach ($fds as $fd) {
            if ($this->isOnline($fd)) {
                $count++;
            }
        }
        return $count;
    }

    // ============================================================
    // 房间管理
    // ============================================================

    /**
     * 加入房间
     */
    public function joinRoom(int $fd, string $room): void
    {
        if (!isset($this->connections[$fd])) {
            return;
        }

        $this->rooms[$room][] = $fd;
        $this->rooms[$room] = array_unique($this->rooms[$room]);

        $this->connections[$fd]->addRoom($room);

        $this->log('debug', "ConnectionManager: fd={$fd} 加入房间 [{$room}]");
    }

    /**
     * 离开房间
     */
    public function leaveRoom(int $fd, string $room): void
    {
        if (isset($this->rooms[$room])) {
            $this->rooms[$room] = array_values(
                array_filter($this->rooms[$room], fn(int $f) => $f !== $fd)
            );
            if (empty($this->rooms[$room])) {
                unset($this->rooms[$room]);
            }
        }

        if (isset($this->connections[$fd])) {
            $this->connections[$fd]->removeRoom($room);
        }
    }

    /**
     * 获取房间内的所有连接 fd
     *
     * @return int[]
     */
    public function getRoomMembers(string $room): array
    {
        // 只返回在线成员
        return array_filter(
            $this->rooms[$room] ?? [],
            fn(int $fd) => $this->isOnline($fd),
        );
    }

    /**
     * 获取房间人数
     */
    public function getRoomCount(string $room): int
    {
        return count($this->getRoomMembers($room));
    }

    /**
     * 用户是否在房间中
     */
    public function isInRoom(string|int $userId, string $room): bool
    {
        $fds = $this->userToFds[$userId] ?? [];
        $roomFds = $this->rooms[$room] ?? [];
        return !empty(array_intersect($fds, $roomFds));
    }

    // ============================================================
    // 消息发送
    // ============================================================

    /**
     * 设置 WebSocket 服务端（用于底层 send/close）
     */
    public function setServer(WsServerInterface $server): self
    {
        $this->server = $server;
        return $this;
    }

    /**
     * 获取 WebSocket 服务端
     */
    public function getServer(): ?WsServerInterface
    {
        return $this->server;
    }

    /**
     * 给指定连接发送消息
     */
    public function send(int $fd, string $message): bool
    {
        if (!$this->isOnline($fd)) {
            return false;
        }

        try {
            $this->server?->send($fd, $message);
            return true;
        } catch (\Throwable $e) {
            $this->log('error', "ConnectionManager: 发送消息到 fd={$fd} 失败: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 给指定用户的所有连接推送消息
     *
     * @param string|int $userId  用户 ID
     * @param string     $message 消息内容
     * @return int 成功发送的连接数
     */
    public function sendToUser(string|int $userId, string $message): int
    {
        $fds = $this->userToFds[$userId] ?? [];
        $sent = 0;

        foreach ($fds as $fd) {
            if ($this->send($fd, $message)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * 给房间内所有成员广播消息
     *
     * @param string|null $excludeFd 排除的 fd（通常是发送方自己）
     * @return int 成功发送的连接数
     */
    public function sendToRoom(string $room, string $message, ?int $excludeFd = null): int
    {
        $fds = $this->getRoomMembers($room);
        $sent = 0;

        foreach ($fds as $fd) {
            if ($excludeFd !== null && $fd === $excludeFd) {
                continue;
            }
            if ($this->send($fd, $message)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * 广播给所有在线连接
     *
     * @return int 成功发送的连接数
     */
    public function broadcast(string $message): int
    {
        $sent = 0;
        foreach (array_keys($this->connections) as $fd) {
            if ($this->send($fd, $message)) {
                $sent++;
            }
        }
        return $sent;
    }

    // ============================================================
    // 心跳管理
    // ============================================================

    /**
     * 记录心跳（连接收到 PING 时调用）
     */
    public function heartbeat(int $fd): void
    {
        if (isset($this->connections[$fd])) {
            $this->connections[$fd]->heartbeat();
        }
    }

    /**
     * 检查心跳超时的连接
     *
     * @param int $timeoutSeconds 超时秒数
     * @return int[] 超时的 fd 列表
     */
    public function checkHeartbeat(int $timeoutSeconds = 60): array
    {
        $timeoutFds = [];
        $now = time();

        foreach ($this->connections as $fd => $connection) {
            if ($connection->isHeartbeatTimeout($timeoutSeconds, $now)) {
                $timeoutFds[] = $fd;
            }
        }

        return $timeoutFds;
    }

    /**
     * 获取连接的最后心跳时间
     *
     * @return int Unix timestamp，连接不存在返回 0
     */
    public function getLastHeartbeat(int $fd): int
    {
        return $this->connections[$fd]->lastHeartbeat ?? 0;
    }

    // ============================================================
    // 频道管理
    // ============================================================

    /**
     * 注册频道
     */
    public function registerChannel(ChannelInterface $channel): self
    {
        $this->channels[$channel->getName()] = $channel;
        return $this;
    }

    /**
     * 获取频道
     */
    public function getChannel(string $name): ?ChannelInterface
    {
        return $this->channels[$name] ?? null;
    }

    /**
     * 获取所有已注册频道
     *
     * @return array<string, ChannelInterface>
     */
    public function getChannels(): array
    {
        return $this->channels;
    }

    /**
     * 加入频道
     */
    public function joinChannel(int $fd, string $channelName): bool
    {
        $channel = $this->channels[$channelName] ?? null;
        if ($channel === null || !isset($this->connections[$fd])) {
            return false;
        }

        $this->connections[$fd]->addChannel($channelName);

        try {
            $channel->onJoin($fd);
        } catch (\Throwable $e) {
            $this->log('error', "ConnectionManager: 频道 [{$channelName}] onJoin fd={$fd} 失败: {$e->getMessage()}");
        }

        return true;
    }

    /**
     * 离开频道
     */
    public function leaveChannel(int $fd, string $channelName): bool
    {
        $channel = $this->channels[$channelName] ?? null;
        if (!isset($this->connections[$fd])) {
            return false;
        }

        $this->connections[$fd]->removeChannel($channelName);

        if ($channel !== null) {
            try {
                $channel->onLeave($fd);
            } catch (\Throwable $e) {
                $this->log('error', "ConnectionManager: 频道 [{$channelName}] onLeave fd={$fd} 失败: {$e->getMessage()}");
            }
        }

        return true;
    }

    // ============================================================
    // 查询统计
    // ============================================================

    /**
     * 获取当前在线连接数
     */
    public function count(): int
    {
        return count($this->connections);
    }

    /**
     * 获取总连接数（含历史）
     */
    public function totalConnections(): int
    {
        // 统计所有已注册过的连接数（包含已断开的统计）
        // 简单实现：当前连接数
        return $this->count();
    }

    /**
     * 检查连接是否在线
     */
    public function isOnline(int $fd): bool
    {
        return isset($this->connections[$fd]);
    }

    /**
     * 获取连接信息
     */
    public function getConnection(int $fd): ?Connection
    {
        return $this->connections[$fd] ?? null;
    }

    /**
     * 获取连接对应用户 ID
     */
    public function getUserId(int $fd): string|int|null
    {
        return $this->connections[$fd]->userId ?? null;
    }

    /**
     * 获取所有房间及其人数
     *
     * @return array<string, int>
     */
    public function getRooms(): array
    {
        $result = [];
        foreach ($this->rooms as $room => $fds) {
            $result[$room] = count($this->getRoomMembers($room));
        }
        return $result;
    }

    /**
     * 获取统计信息
     *
     * @return array{connections: int, users: int, rooms: int, channels: int}
     */
    public function stats(): array
    {
        return [
            'connections' => $this->count(),
            'users'       => count($this->userToFds),
            'rooms'       => count($this->rooms),
            'channels'    => count($this->channels),
        ];
    }

    // ============================================================
    // 配置
    // ============================================================

    /**
     * 设置最大连接数
     */
    public function setMaxConnections(int $max): self
    {
        $this->maxConnections = $max;
        return $this;
    }

    /**
     * 设置日志记录器
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    // ============================================================
    // 清理
    // ============================================================

    /**
     * 清理所有连接（服务关闭时调用）
     */
    public function clear(): void
    {
        $this->connections = [];
        $this->userToFds = [];
        $this->rooms = [];
        $this->channels = [];
    }

    // ============================================================
    // Protected
    // ============================================================

    /**
     * 记录日志
     */
    protected function log(string $level, string $message): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message);
        }
    }
}
