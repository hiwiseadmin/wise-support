<?php
declare(strict_types=1);

namespace wise\support\ws;

/**
 * WebSocket 频道接口
 *
 * 频道是 WebSocket 消息的逻辑分组单元。
 * 多个智能包可注册不同的频道，ConnectionManager 按频道路由消息。
 *
 * 使用示例：
 * <pre>
 * // wise-game-versus 注册帧同步频道
 * class FrameSyncChannel implements ChannelInterface
 * {
 *     public function getName(): string { return 'game.frame_sync'; }
 *     public function onMessage(int $fd, array $data): void {  }
 *     public function onJoin(int $fd): void {  }
 *     public function onLeave(int $fd): void {  }
 * }
 * </pre>
 *
 * @package wise\support\ws
 */
interface ChannelInterface
{
    /**
     * 获取频道名称（唯一标识）
     */
    public function getName(): string;

    /**
     * 处理接收到的消息
     *
     * @param int   $fd   发送方的连接 fd
     * @param array $data 解析后的消息数据（已 JSON 解码）
     */
    public function onMessage(int $fd, array $data): void;

    /**
     * 连接加入频道时的回调
     */
    public function onJoin(int $fd): void;

    /**
     * 连接离开频道时的回调
     */
    public function onLeave(int $fd): void;
}
