<?php
declare(strict_types=1);

namespace wise\support\ws;

/**
 * WebSocket 服务端接口
 *
 * 定义 WiseAdmin 框架与底层 WebSocket 服务器的统一契约。
 * 由具体运行时集成（如 think-swoole）提供实现，经容器绑定使用。
 *
 * @package wise\support\ws
 */
interface WsServerInterface
{
    /**
     * 启动 WebSocket 服务
     */
    public function start(): void;

    /**
     * 停止 WebSocket 服务
     */
    public function stop(): void;

    /**
     * 重启 WebSocket 服务
     */
    public function reload(): void;

    /**
     * 发送数据到指定连接
     *
     * @param int    $fd  连接 fd
     * @param string $data 要发送的数据（通常为 JSON）
     */
    public function send(int $fd, string $data): void;

    /**
     * 关闭指定连接
     *
     * @param int    $fd     连接 fd
     * @param int    $code   关闭码
     * @param string $reason 关闭原因
     */
    public function close(int $fd, int $code = 1000, string $reason = ''): void;

    /**
     * 获取当前活跃连接数
     */
    public function count(): int;

    /**
     * 检查连接是否在线
     */
    public function isOnline(int $fd): bool;

    /**
     * 获取当前所有连接的 fd 列表
     *
     * @return int[]
     */
    public function getFds(): array;

    /**
     * 获取服务端名称
     */
    public function getName(): string;
}
