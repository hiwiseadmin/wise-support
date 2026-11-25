<?php
declare(strict_types=1);

namespace wise\support\ws;

use Psr\Log\LoggerInterface;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\bus\ServiceBus;
use wise\support\ws\handler\PingPongHeartbeat;

/**
 * WebSocket 消息路由器
 *
 * 将接收到的原始 WebSocket 消息解析并路由到对应的处理器。
 * 处理器通过 ExtPoint('ws.handler') 注册，或通过 ServiceBus 分发。
 *
 * 消息格式约定（JSON）：
 * <pre>
 * {
 *     "action": "game.frame_sync",   // 操作名（映射到 ws.handler.{action}）
 *     "room": "room_123",            // 可选：房间名
 *     "data": {  }                   // 业务数据
 * }
 * </pre>
 *
 * 使用示例：
 * <pre>
 * $router = app(MessageRouter::class);
 *
 * // WebSocket 服务器收到消息时：
 * $router->dispatch($fd, $rawMessage);
 *
 * // 注册自定义处理器
 * ExtPoint::register('ws.handler', 'game.frame_sync', FrameSyncHandler::class);
 * </pre>
 *
 * @package wise\support\ws
 */
class MessageRouter
{
    /**
     * 消息处理器注册前缀
     */
    protected const HANDLER_PREFIX = 'ws.handler';

    protected ?ConnectionManager $connectionManager = null;
    protected ?LoggerInterface $logger = null;

    /**
     * 连接级鉴权预检回调。
     *
     * 可选：fn(int $fd, array $payload): ?string
     *  - 返回 null 表示通过鉴权，继续路由；
     *  - 返回字符串视为拒绝原因，立即对该 fd 发送 error 帧并终止本次分发。
     *
     * 用于对未登录/无权限连接做统一拦截。连接的主鉴权仍应在握手/绑定用户
     * 阶段完成；此回调作为「业务 action 分发前」的兜底护栏。
     *
     * @var callable|null
     */
    protected $authorizeCallback = null;

    /**
     * 分发原始消息
     *
     * 流程：
     *  1. 解析 JSON
     *  2. 提取 action
     *  3. 通过 ExtPoint + ServiceBus 查找处理器
     *  4. 调用处理器
     *
     * @param int    $fd         发送方连接 fd
     * @param string $rawMessage 原始消息字符串
     * @return mixed 处理结果
     */
    public function dispatch(int $fd, string $rawMessage): mixed
    {
        $data = json_decode($rawMessage, true);
        if (!is_array($data)) {
            $this->log('warning', "MessageRouter: fd={$fd} 收到无效 JSON: " . substr($rawMessage, 0, 100));
            $this->sendError($fd, '无效的消息格式');
            return null;
        }

        $action = $data['action'] ?? '';
        if (empty($action)) {
            if ($this->dispatchHeartbeat($fd, $rawMessage, $data)) {
                return true;
            }
            $this->log('warning', "MessageRouter: fd={$fd} 消息缺少 action 字段");
            return null;
        }

        if ($action === 'ping' || $action === 'pong') {
            if ($this->dispatchHeartbeat($fd, $rawMessage, $data)) {
                return true;
            }
        }

        $payload = [
            'fd'      => $fd,
            'action'  => $action,
            'data'    => is_array($data['data'] ?? null) ? $data['data'] : [],
            'room'    => $data['room'] ?? null,
            'user_id' => $this->connectionManager?->getUserId($fd),
        ];

        $denyReason = $this->authorizePayload($fd, $payload);
        if ($denyReason !== null) {
            $this->sendError($fd, $denyReason);
            $this->log('warning', "MessageRouter: fd={$fd} 鉴权拦截 action [{$action}]: {$denyReason}");
            return null;
        }

        return $this->route($action, $fd, $payload);
    }

    /**
     * 设置连接鉴权预检回调。
     *
     * @param callable|null $authorize fn(int $fd, array $payload): ?string
     *       返回 null = 放行；返回非 null 字符串 = 拒绝原因。
     * @return $this
     */
    public function authorize(?callable $authorize): self
    {
        $this->authorizeCallback = $authorize;
        return $this;
    }

    /**
     * 对 payload 执行鉴权预检。
     *
     * @return string|null null=放行；字符串=拒绝原因
     */
    protected function authorizePayload(int $fd, array $payload): ?string
    {
        if ($this->authorizeCallback === null) {
            return null;
        }
        try {
            $result = ($this->authorizeCallback)($fd, $payload);
            return is_string($result) && $result !== '' ? $result : null;
        } catch (\Throwable $e) {
            $this->log('error', "MessageRouter: 鉴权回调异常 (fd={$fd}): {$e->getMessage()}");
            return '鉴权失败，请求被拒绝';
        }
    }

    /**
     * 调度心跳消息到 WS_HEARTBEAT 处理器集合。
     *
     * 心跳处理器通过 ExtPoint('ws.heartbeat') 注册（如内置 PingPongHeartbeat），
     * 约定签名为 handle(int $fd, string $rawMessage): ?string，返回非 null 的
     * JSON 字符串即应答帧（如 PONG），由本方法回传给客户端。
     *
     * 【安全边界】心跳在本类的鉴权预检之前执行（控制消息不能被业务鉴权拦住），
     * 因此这里必须区分「谁有权在未认证阶段执行」：未绑定用户（未登录）的连接
     * 只允许运行内置的、无副作用的 PingPongHeartbeat；其余 ws.heartbeat 实现
     * （含闭包）必须先登录。否则未认证帧即可驱动任意扩展点代码。豁免是显式且
     * 有日志留痕的，而不是把整个处理器集合交给未认证对端。
     *
     * @param int    $fd         连接 fd
     * @param string $rawMessage 原始消息
     * @param array  $data       已解析的 JSON 数组
     * @return bool 是否有心跳处理器接管该消息
     */
    protected function dispatchHeartbeat(int $fd, string $rawMessage, array $data): bool
    {
        $handlers = ExtPoint::all(ExtPoints::WS_HEARTBEAT);
        if (empty($handlers)) {
            return false;
        }

        $boundUserId = $this->connectionManager?->getUserId($fd);

        foreach ($handlers as $name => $item) {
            $concrete = $item['concrete'] ?? null;
            if ($concrete === null) {
                continue;
            }

            $isBuiltin = is_string($concrete) && ltrim($concrete, '\\') === PingPongHeartbeat::class;
            if ($boundUserId === null && !$isBuiltin) {
                $this->log('debug', "MessageRouter: fd={$fd} 未登录，跳过心跳处理器 [{$name}]（鉴权前仅放行内置心跳）");
                continue;
            }

            try {
                $handler = is_callable($concrete)
                    ? $concrete
                    : (is_string($concrete) ? app($concrete) : null);
                if ($handler === null) {
                    continue;
                }
                if (is_object($handler) && method_exists($handler, 'handle')) {
                    $reply = $handler->handle($fd, $rawMessage);
                    if ($reply !== null && is_string($reply)) {
                        $this->connectionManager?->send($fd, $reply);
                        return true;
                    }
                    continue;
                }
                if (is_callable($handler)) {
                    $reply = $handler($fd, $data);
                    if ($reply !== null) {
                        $this->connectionManager?->send($fd, (string) $reply);
                        return true;
                    }
                    continue;
                }
            } catch (\Throwable $e) {
                $this->log('error', "MessageRouter: 心跳处理器 [{$name}] 处理失败: {$e->getMessage()}");
            }
        }

        return false;
    }

    /**
     * 路由消息到处理器
     *
     * @param string $action  操作名
     * @param int    $fd      连接 fd
     * @param array  $payload 消息载荷
     */
    protected function route(string $action, int $fd, array $payload): mixed
    {
        $handlerKey = self::HANDLER_PREFIX . '.' . $action;

        if (!ExtPoint::isEmpty($handlerKey)) {
            return $this->routeViaExtPoint($handlerKey, $fd, $payload);
        }

        if ($this->connectionManager !== null && class_exists(ServiceBus::class)) {
            try {
                $bus = app(ServiceBus::class);
                if ($bus->canHandle($action)) {
                    return $bus->publish($action, array_merge($payload, ['fd' => $fd]));
                }
            } catch (\Throwable $e) {
                $this->log('debug', "MessageRouter: ServiceBus 路由 [{$action}] 失败: {$e->getMessage()}");
            }
        }

        $this->log('warning', "MessageRouter: 无处理器处理 [{$action}] (fd={$fd})");
        $this->sendError($fd, "未知操作: {$action}");
        return null;
    }

    /**
     * 通过 ExtPoint 路由
     */
    protected function routeViaExtPoint(string $handlerKey, int $fd, array $payload): mixed
    {
        $handlers = ExtPoint::all($handlerKey);
        $results = [];

        foreach ($handlers as $name => $item) {
            $concrete = $item['concrete'] ?? null;
            if ($concrete === null) {
                continue;
            }

            try {
                $handler = is_callable($concrete) ? $concrete : (is_string($concrete) ? app($concrete) : null);
                if ($handler === null) {
                    continue;
                }

                if ($handler instanceof ChannelInterface) {
                    $results[$name] = $handler->onMessage($fd, $payload['data'] ?? []);
                } elseif ($handler instanceof \Closure || is_callable($handler)) {
                    $results[$name] = ($handler)($payload);
                } elseif (is_object($handler) && method_exists($handler, 'handle')) {
                    $results[$name] = $handler->handle($payload);
                }
            } catch (\Throwable $e) {
                $this->log('error', "MessageRouter: 处理器 [{$name}] 处理 [{$handlerKey}] 失败: {$e->getMessage()}");
            }
        }

        return $results;
    }

    /**
     * 发送错误响应给客户端
     */
    protected function sendError(int $fd, string $message): void
    {
        if ($this->connectionManager !== null) {
            $this->connectionManager->send($fd, json_encode([
                'action' => 'error',
                'data'   => ['message' => $message],
            ], JSON_UNESCAPED_UNICODE));
        }
    }


    /**
     * 设置连接管理器引用
     */
    public function setConnectionManager(ConnectionManager $manager): self
    {
        $this->connectionManager = $manager;
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


    protected function log(string $level, string $message): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message);
        }
    }
}
