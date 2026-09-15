<?php
declare(strict_types=1);

namespace wise\support\ws\handler;

/**
 * PING/PONG 心跳处理器
 *
 * 为 WebSocket 连接提供标准的 PING/PONG 心跳机制。
 * 客户端发送 {"type":"ping"}，服务端回复 {"type":"pong","time":<timestamp>}。
 *
 * 通过 ExtPoint(ws.heartbeat)->register() 注册，由 MessageRouter 调度。
 *
 * 使用示例：
 * <pre>
 * // SupportServiceProvider 中自动注册
 * ExtPoint::register(ExtPoints::WS_HEARTBEAT, 'ping_pong', PingPongHeartbeat::class);
 *
 * // 客户端
 * ws.send(JSON.stringify({type: 'ping'}));
 * // 收到: {"type":"pong","time":1692000000}
 * </pre>
 *
 * @package wise\support\ws\handler
 */
class PingPongHeartbeat
{
    /**
     * 处理心跳消息
     *
     * @param int    $fd      连接 fd
     * @param string $message 原始消息字符串（JSON）
     * @return string|null JSON 响应字符串，或 null（非 PING 消息）
     */
    public function handle(int $fd, string $message): ?string
    {
        $data = json_decode($message, true);

        if (!is_array($data) || ($data['type'] ?? '') !== 'ping') {
            return null;
        }

        return json_encode([
            'type' => 'pong',
            'time' => time(),
        ], JSON_UNESCAPED_UNICODE);
    }
}
