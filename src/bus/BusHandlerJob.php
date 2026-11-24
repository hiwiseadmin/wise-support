<?php
declare(strict_types=1);

namespace wise\support\bus;

use wise\support\ExtPoint;

/**
 * 服务总线异步作业
 *
 * 将 ServiceBus 的 publish/broadcast 处理器调用封装为 think-queue Job，
 * 实现真正的异步非阻塞分发。
 *
 * 兼容 think-queue v2/v3 fire() 约定。
 *
 * 使用方式（由 ServiceBus 自动创建）：
 * <pre>
 * Queue::push(BusHandlerJob::class, [
 *     'action'     => 'order.created',
 *     'handler_class' => 'app\\service\\OrderHandler',
 *     'message'    => $message->toArray(),
 * ], $queueName);
 * </pre>
 *
 * @package wise\support\bus
 */
class BusHandlerJob
{
    /**
     * think-queue v2/v3 标准入口
     *
     * @param \think\queue\Job $job  队列 Job 实例
     * @param array            $data 作业数据
     */
    public function fire(\think\queue\Job $job, array $data): void
    {
        $handlerClass = $data['handler_class'] ?? '';
        $messageData  = $data['message'] ?? [];
        $action       = $data['action'] ?? ($messageData['action'] ?? '');

        if (empty($handlerClass)) {
            $job->delete();
            throw new \RuntimeException('BusHandlerJob: handler_class 为空');
        }

        if (!self::isRegisteredHandler($action, $handlerClass)) {
            $job->delete();
            throw new \RuntimeException(
                "BusHandlerJob: [{$handlerClass}] 不是操作 [{$action}] 的已注册处理器"
            );
        }

        try {
            $message = Message::fromArray($messageData);

            $messageId = $message->getId();
            if ($this->alreadyProcessed($messageId)) {
                $job->delete();
                return;
            }

            $this->callHandler($handlerClass, $message);

            $this->markProcessed($messageId, (int) ($data['dedup_ttl'] ?? 86400));

            $job->delete();
        } catch (\Throwable $e) {
            if ($job->attempts() < ($data['max_attempts'] ?? 3)) {
                $job->release($data['retry_delay'] ?? 10);
            } else {
                $job->failed($e);
                $job->delete();
            }
        }
    }

    /**
     * 去重键前缀（与业务缓存共用同一 store，故加独立前缀避免键冲突）
     */
    private const DEDUP_PREFIX = 'wise_bus_done:';

    /**
     * 取缓存通道；不可用时返回 null（此时幂等守卫整体退化为放行）
     *
     * 直接经容器解析 'cache'，避免在测试/极早期 CLI 中因 Cache 门面不可用而抛错。
     */
    private static function dedupCache(): ?object
    {
        try {
            $cache = \think\Container::getInstance()->make('cache');
        } catch (\Throwable) {
            return null;
        }

        return is_object($cache) && method_exists($cache, 'get') && method_exists($cache, 'set')
            ? $cache
            : null;
    }

    /**
     * 该消息 id 是否已被处理过
     */
    private function alreadyProcessed(string $messageId): bool
    {
        $cache = self::dedupCache();
        if ($cache === null || $messageId === '') {
            return false;
        }

        try {
            return (bool) $cache->get(self::DEDUP_PREFIX . $messageId, false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 标记消息 id 已处理
     *
     * 写入失败只记日志，不抛出：标记失败不应把已成功的业务副作用变成失败作业。
     */
    private function markProcessed(string $messageId, int $ttl): void
    {
        $cache = self::dedupCache();
        if ($cache === null || $messageId === '') {
            return;
        }

        try {
            $cache->set(self::DEDUP_PREFIX . $messageId, 1, max(60, $ttl));
        } catch (\Throwable $e) {
            error_log('[BusHandlerJob] 幂等标记写入失败: ' . $e->getMessage());
        }
    }

    /**
     * 判断处理器是否为该 action 的已注册实现
     *
     * 注册键与 ServiceBus 保持一致：'bus.handler.' . $action。
     * action 为空时视为不可判定 → 拒绝（fail-closed）。
     *
     * @param string $action       操作名
     * @param string $handlerClass 载荷声明的处理器类名
     */
    private static function isRegisteredHandler(string $action, string $handlerClass): bool
    {
        if ($action === '') {
            return false;
        }

        foreach (ExtPoint::all('bus.handler.' . $action) as $item) {
            if (($item['concrete'] ?? null) === $handlerClass) {
                return true;
            }
        }

        return false;
    }

    /**
     * 调用处理器
     *
     * 与 ServiceBus::invokeHandler() 保持一致的约定：
     * - 类名 → 通过容器解析，优先调用 handle() 方法
     * - 闭包 → 不支持（队列无法序列化闭包，调用方应注入类名）
     *
     * @param string  $handlerClass 处理器类名
     * @param Message $message      消息
     * @return mixed
     */
    private function callHandler(string $handlerClass, Message $message): mixed
    {
        if (!class_exists($handlerClass)) {
            throw new \RuntimeException("消息处理器类 [{$handlerClass}] 不存在");
        }

        $instance = app($handlerClass);

        if (method_exists($instance, 'handle')) {
            return $instance->handle($message);
        }

        if (is_callable($instance)) {
            return $instance($message);
        }

        throw new \RuntimeException("消息处理器 [{$handlerClass}] 必须实现 handle(Message) 方法或 __invoke");
    }
}
