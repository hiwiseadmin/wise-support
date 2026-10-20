<?php
declare(strict_types=1);

namespace wise\support\ws;

/**
 * WebSocket 连接实体
 *
 * 存储单个连接的元数据：fd、用户 ID、IP、心跳时间、所属房间/频道等。
 *
 * @package wise\support\ws
 */
class Connection
{
    /**
     * 连接建立时间戳
     */
    public readonly int $connectedAt;

    /**
     * 最后心跳时间戳
     */
    public int $lastHeartbeat;

    /**
     * 所属房间名列表
     *
     * @var string[]
     */
    protected array $rooms = [];

    /**
     * 所属频道名列表
     *
     * @var string[]
     */
    protected array $channels = [];

    /**
     * @param int            $fd        连接 fd
     * @param string|int|null $userId   用户 ID（认证后设置）
     * @param string         $ip        客户端 IP
     * @param string         $userAgent 客户端 User-Agent
     * @param array<string, mixed> $metadata 附加元数据
     */
    public function __construct(
        public readonly int $fd,
        public string|int|null $userId = null,
        public readonly string $ip = '',
        public readonly string $userAgent = '',
        public readonly array $metadata = [],
    ) {
        $this->connectedAt = time();
        $this->lastHeartbeat = time();
    }

    /**
     * 记录心跳
     */
    public function heartbeat(): void
    {
        $this->lastHeartbeat = time();
    }

    /**
     * 检查心跳是否超时
     *
     * @param int $timeoutSeconds 超时秒数
     * @param int|null $now       当前时间戳（null 使用 time()）
     */
    public function isHeartbeatTimeout(int $timeoutSeconds = 60, ?int $now = null): bool
    {
        $now = $now ?? time();
        return ($now - $this->lastHeartbeat) > $timeoutSeconds;
    }

    /**
     * 获取连接的持续时间（秒）
     */
    public function getDuration(): int
    {
        return time() - $this->connectedAt;
    }

    // ── 房间 ──

    public function addRoom(string $room): void
    {
        if (!in_array($room, $this->rooms, true)) {
            $this->rooms[] = $room;
        }
    }

    public function removeRoom(string $room): void
    {
        $this->rooms = array_values(array_filter($this->rooms, fn(string $r) => $r !== $room));
    }

    /**
     * @return string[]
     */
    public function getRooms(): array
    {
        return $this->rooms;
    }

    public function isInRoom(string $room): bool
    {
        return in_array($room, $this->rooms, true);
    }

    // ── 频道 ──

    public function addChannel(string $channel): void
    {
        if (!in_array($channel, $this->channels, true)) {
            $this->channels[] = $channel;
        }
    }

    public function removeChannel(string $channel): void
    {
        $this->channels = array_values(array_filter($this->channels, fn(string $c) => $c !== $channel));
    }

    /**
     * @return string[]
     */
    public function getChannels(): array
    {
        return $this->channels;
    }

    public function isInChannel(string $channel): bool
    {
        return in_array($channel, $this->channels, true);
    }

    // ── 序列化 ──

    /**
     * 转为数组（用于监控面板）
     */
    public function toArray(): array
    {
        return [
            'fd'           => $this->fd,
            'user_id'      => $this->userId,
            'ip'           => $this->ip,
            'user_agent'   => $this->userAgent,
            'connected_at' => $this->connectedAt,
            'last_heartbeat' => $this->lastHeartbeat,
            'duration'     => $this->getDuration(),
            'rooms'        => $this->rooms,
            'channels'     => $this->channels,
        ];
    }
}
