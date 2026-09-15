<?php
declare(strict_types=1);

namespace wise\support\bus;

use Psr\Log\LoggerInterface;
use think\facade\Config;
use wise\support\ExtPoint;

/**
 * 插件服务总线（Plugin Service Bus）
 *
 * 为 WiseAdmin 插件生态提供统一的跨插件通信机制。
 * 支持三种通信模式：请求-响应（ask）、发布-订阅（publish）、广播（broadcast）。
 *
 * 消息处理器通过 ExtPoint::register('bus.handler.{action}', $name, $handler) 注册。
 *
 * 异步分发：publish() 和 broadcast() 支持通过 think-queue 异步分发。
 * 调用 ->async() 启用，可选 ->onQueue('queue_name') 指定队列。
 * 未启用 async 时，publish/broadcast 为同步 foreach 调用。
 * ask() 始终同步（需要等待响应）。
 *
 * 与 Event 系统的关系：
 *  - ServiceBus::publish() — 有名称的分发，处理器明确注册
 *  - ServiceBus::broadcast() — 无回复的分发
 *  - Event::trigger() — 框架内部生命周期事件（继续使用）
 *
 * 使用示例：
 * <pre>
 * $bus = app(ServiceBus::class);
 *
 * // 请求-响应：同步调用一个处理器并获取回复
 * $result = $bus->ask('payment.charge', ['amount' => 100]);
 * if ($result->isError()) {
 *     throw new \Exception($result->getError());
 * }
 *
 * // 发布-订阅：异步发布给所有注册的处理器
 * $bus->async()->publish('order.created', ['order_id' => 42]);
 *
 * // 发布到指定队列
 * $bus->async()->onQueue('high_priority')->publish('order.paid', ['order_id' => 42]);
 *
 * // 广播：不关心回复，所有处理器都会收到
 * $bus->async()->broadcast('cache.invalidate', ['tag' => 'product_list']);
 * </pre>
 *
 * @package wise\support
 */
class ServiceBus
{
    /**
     * 消息处理器注册前缀
     */
    protected const HANDLER_PREFIX = 'bus.handler';

    /**
     * @var LoggerInterface|null
     */
    protected ?LoggerInterface $logger = null;

    /**
     * 是否启用异步分发（通过 think-queue）
     */
    protected bool $async = false;

    /**
     * 异步分发使用的队列名称
     */
    protected string $queueName = 'default';

    /**
     * 请求-响应模式
     *
     * 按以下策略选择处理器：
     *  1. 如果 payload 或 context 中指定了 _handler，使用指定的
     *  2. 如果有 active 标记的处理器，使用它
     *  3. 如果只有一个处理器，直接使用
     *  4. 多个处理器时，取 priority 最高的并记录警告
     *
     * @param string $action  操作名称，如 'payment.charge'
     * @param array  $payload 请求数据
     * @param array  $context 上下文（_handler 可指定处理器名称）
     * @return Message 回复消息；无可用处理器时返回 isError()===true 的错误消息（不抛异常）
     */
    public function ask(string $action, array $payload = [], array $context = []): Message
    {
        $handlers = ExtPoint::all(self::HANDLER_PREFIX . '.' . $action);

        if (empty($handlers)) {
            $this->log('warning', "ServiceBus: 无处理器处理 [{$action}]");
            return Message::error(
                Message::request($action, $payload, $context),
                "没有注册的处理器处理操作 [{$action}]",
                404
            );
        }

        // 选择处理器
        $selected = $this->selectHandler($action, $handlers, $context);
        $handler  = $selected['handler'];
        $handlerName = $selected['name'];

        $message = Message::request($action, $payload, $context);

        // 调用处理器
        try {
            $result = $this->invokeHandler($handler, $message);
        } catch (\Throwable $e) {
            $this->log('error', "ServiceBus: 处理器 [{$handlerName}] 执行 [{$action}] 失败: {$e->getMessage()}");
            // 生产不向调用方透传底层异常文本（可能含 SQL/路径等内部信息），
            // 详情保留在日志；确需调试信息时依赖 app.debug。
            // Config 门面在异常路径上可能不可用，故 try-catch 保护，避免 catch 内二度抛错。
            $debug = false;
            try {
                $debug = (bool) Config::get('app.debug', false);
            } catch (\Throwable) {
                // 容器不可用时按生产模式处理
            }
            $detail = $debug ? $e->getMessage() : "处理器 [{$handlerName}] 执行失败";
            return Message::error($message, $detail, 500);
        }

        if (!$result instanceof Message) {
            $result = Message::reply($message, is_array($result) ? $result : ['result' => $result]);
        }

        return $result;
    }

    /**
     * 发布-订阅模式
     *
     * 同步模式（默认）：foreach 调用所有处理器。
     * 异步模式（->async()）：通过 think-queue 将每个字符串类名处理器推送到队列。
     * 闭包处理器在异步模式下被跳过（无法序列化），并记录警告。
     *
     * @param string        $action  操作名称
     * @param array         $payload 事件数据
     * @param callable|null $onReply 回复回调 fn(Message $reply): void（异步模式下不触发）
     */
    public function publish(string $action, array $payload = [], ?callable $onReply = null): void
    {
        $handlers = ExtPoint::all(self::HANDLER_PREFIX . '.' . $action);
        $message  = Message::event($action, $payload);

        if ($this->async) {
            $this->dispatchAsync($handlers, $message);
            $this->async = false; // 重置模式，避免状态泄漏
            return;
        }

        foreach ($handlers as $name => $handlerItem) {
            if (!isset($handlerItem['concrete'])) {
                $this->log('warning', "ServiceBus: 处理器 [{$name}] 缺少 concrete 定义，已跳过");
                continue;
            }
            try {
                $result = $this->invokeHandler($handlerItem['concrete'], $message);
                if ($result instanceof Message && $onReply) {
                    $onReply($result);
                }
            } catch (\Throwable $e) {
                $this->log('warning', "ServiceBus: 处理器 [{$name}] 处理 [{$action}] 失败: {$e->getMessage()}");
            }
        }
    }

    /**
     * 广播模式
     *
     * 同步模式（默认）：foreach 调用所有处理器。
     * 异步模式（->async()）：通过 think-queue 推送。单个处理器异常不中断其余分发。
     *
     * @param string $action  操作名称
     * @param array  $payload 广播数据
     */
    public function broadcast(string $action, array $payload = []): void
    {
        $handlers = ExtPoint::all(self::HANDLER_PREFIX . '.' . $action);
        $message  = Message::event($action, $payload);

        if ($this->async) {
            $this->dispatchAsync($handlers, $message);
            $this->async = false; // 重置模式，避免状态泄漏
            return;
        }

        foreach ($handlers as $name => $handlerItem) {
            if (!isset($handlerItem['concrete'])) {
                $this->log('warning', "ServiceBus: 广播处理器 [{$name}] 缺少 concrete 定义，已跳过");
                continue;
            }
            try {
                $this->invokeHandler($handlerItem['concrete'], $message);
            } catch (\Throwable $e) {
                $this->log('debug', "ServiceBus: 广播处理器 [{$name}] 处理 [{$action}] 失败: {$e->getMessage()}");
            }
        }
    }

    /**
     * 异步分发处理器到 think-queue
     *
     * 将每个字符串类名处理器封装为 BusHandlerJob 推送到队列。
     * 闭包类处理器无法序列化，在异步模式下会被跳过并记录警告。
     *
     * @param array   $handlers ExtPoint 返回的处理器列表
     * @param Message $message  消息
     */
    protected function dispatchAsync(array $handlers, Message $message): void
    {
        if (!class_exists(\think\facade\Queue::class)) {
            $this->log('error', 'ServiceBus: think-queue 不可用，无法异步分发。请安装 topthink/think-queue');
            return;
        }

        $messageData = $message->toArray();
        $dispatched = 0;
        $skipped = 0;

        foreach ($handlers as $name => $handlerItem) {
            $concrete = $handlerItem['concrete'] ?? null;

            if ($concrete === null) {
                $this->log('warning', "ServiceBus: 处理器 [{$name}] 缺少 concrete 定义，已跳过");
                $skipped++;
                continue;
            }

            // 闭包无法序列化到队列，跳过
            if ($concrete instanceof \Closure) {
                $this->log('warning', "ServiceBus: 处理器 [{$name}] 为闭包类型，无法异步分发，已跳过");
                $skipped++;
                continue;
            }

            // 非字符串类名（如 callable 数组）也跳过
            if (!is_string($concrete)) {
                $this->log('warning', "ServiceBus: 处理器 [{$name}] 类型不支持异步分发，已跳过");
                $skipped++;
                continue;
            }

            try {
                \think\facade\Queue::push(BusHandlerJob::class, [
                    'action'        => $message->getAction(),
                    'handler_class' => $concrete,
                    'message'       => $messageData,
                ], $this->queueName);

                $dispatched++;
            } catch (\Throwable $e) {
                $this->log('error', "ServiceBus: 异步推送处理器 [{$name}] 失败: {$e->getMessage()}");
            }
        }

        if ($dispatched > 0 || $skipped > 0) {
            $this->log('info', sprintf(
                'ServiceBus: 异步分发 [%s] 完成 — %d 已推送, %d 已跳过, 队列: %s',
                $message->getAction(),
                $dispatched,
                $skipped,
                $this->queueName
            ));
        }
    }

    /**
     * 检查是否有处理器可处理指定操作
     */
    public function canHandle(string $action): bool
    {
        return !ExtPoint::isEmpty(self::HANDLER_PREFIX . '.' . $action);
    }

    /**
     * 列出指定操作的所有处理器
     *
     * @return array<string, string>
     */
    public function listHandlers(string $action): array
    {
        $handlers = ExtPoint::all(self::HANDLER_PREFIX . '.' . $action);
        $result = [];
        foreach ($handlers as $name => $item) {
            $result[$name] = is_string($item['concrete']) ? $item['concrete'] : 'Closure';
        }
        return $result;
    }

    /**
     * 设置日志记录器
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * 启用异步分发模式
     *
     * 调用后 publish() 和 broadcast() 将通过 think-queue 异步分发，
     * 每个处理器作为一个独立的 BusHandlerJob 推送到队列。
     * ask() 始终同步，不受此设置影响。
     *
     * @return $this
     */
    public function async(): self
    {
        $this->async = true;
        return $this;
    }

    /**
     * 恢复同步分发模式（默认）
     *
     * @return $this
     */
    public function sync(): self
    {
        $this->async = false;
        return $this;
    }

    /**
     * 指定异步分发使用的队列名称
     *
     * @param string $queueName 队列名称，默认 "default"
     * @return $this
     */
    public function onQueue(string $queueName): self
    {
        $this->queueName = $queueName;
        return $this;
    }

    /**
     * 选择处理器
     *
     * @return array{name: string, handler: string|callable}
     */
    protected function selectHandler(string $action, array $handlers, array $context): array
    {
        // 1. 显式指定
        $preferred = $context['_handler'] ?? null;
        if ($preferred && isset($handlers[$preferred])) {
            return ['name' => $preferred, 'handler' => $handlers[$preferred]['concrete']];
        }

        // 2. active 标记的
        foreach ($handlers as $name => $item) {
            if ($item['active'] ?? false) {
                return ['name' => $name, 'handler' => $item['concrete']];
            }
        }

        // 3. 只有一个处理器
        if (count($handlers) === 1) {
            $name = array_key_first($handlers);
            return ['name' => $name, 'handler' => $handlers[$name]['concrete']];
        }

        // 4. 多个处理器：按 priority 升序（值越小优先级越高），取最高优先级
        uasort($handlers, static fn(array $a, array $b): int =>
            ($a['priority'] ?? 100) <=> ($b['priority'] ?? 100)
        );
        $name = array_key_first($handlers);
        $available = implode(', ', array_keys($handlers));
        $this->log('warning', "ServiceBus: [{$action}] 有多个处理器，已选择 [{$name}]（priority 值最小=优先级最高）。可用: [{$available}]。建议通过 _handler 显式指定。");

        return ['name' => $name, 'handler' => $handlers[$name]['concrete']];
    }

    /**
     * 调用处理器
     *
     * @param string|callable $handler 类名或闭包
     * @param Message         $message 请求消息
     * @return mixed Message 实例或任意类型
     */
    protected function invokeHandler(string|callable $handler, Message $message): mixed
    {
        if (is_callable($handler)) {
            return ($handler)($message);
        }

        if (!class_exists($handler)) {
            throw new \RuntimeException("消息处理器类 [{$handler}] 不存在");
        }

        // 通过容器解析，支持构造函数依赖注入
        $instance = app($handler);

        // 优先调用 handle 方法
        if (method_exists($instance, 'handle')) {
            return $instance->handle($message);
        }

        // 回退到 __invoke
        if (is_callable($instance)) {
            return $instance($message);
        }

        throw new \RuntimeException("消息处理器 [{$handler}] 必须实现 handle(Message) 方法或 __invoke");
    }

    /**
     * 记录日志
     *
     * 配置了 logger 时使用 PSR-3 标准日志，未配置时降级到 error_log。
     */
    protected function log(string $level, string $message): void
    {
        if ($this->logger) {
            $this->logger->{$level}($message);
        } else {
            error_log("[BusServiceBus][{$level}] {$message}");
        }
    }
}
