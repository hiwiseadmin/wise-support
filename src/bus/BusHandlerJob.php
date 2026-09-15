<?php
declare(strict_types=1);

namespace wise\support\bus;

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

        if (empty($handlerClass)) {
            $job->delete();
            throw new \RuntimeException('BusHandlerJob: handler_class 为空');
        }

        try {
            $message = Message::fromArray($messageData);

            $result = $this->callHandler($handlerClass, $message);

            // 结果写入 Job 数据，供 onReply 回调或其他消费者读取
            if ($result instanceof Message) {
                $data['__result'] = $result->toArray();
            }

            $job->delete();
        } catch (\Throwable $e) {
            // 判断是否为可重试异常
            if ($job->attempts() < ($data['max_attempts'] ?? 3)) {
                $job->release($data['retry_delay'] ?? 10);
            } else {
                $job->failed($e);
                $job->delete();
            }
        }
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
