<?php

declare(strict_types=1);

namespace wise\support\traits;

/**
 * 事件调度 Trait
 *
 * 为控制器 / Service / 中间件提供统一的事件触发能力，
 * 解耦业务逻辑与事件派发。
 *
 * 使用场景：
 *   - 触发框架级事件：$this->dispatch('UserLogin', $user);
 *   - 传参：$this->dispatch('OrderPaid', $order, ['channel' => 'wechat']);
 *   - 批量事件：$this->dispatchMany(['UserCreated', 'WelcomeEmail'], $user);
 *   - 短路事件：$this->dispatchUntil('BeforeDelete', $id);
 *
 * @package wise\support\traits
 */
trait EventTrait
{
    /**
     * 事件命名空间前缀（子类按需覆盖）
     *
     * 例：'plugin.cms.' 则 dispatch('ArticlePublished') → 实际事件 'plugin.cms.ArticlePublished'
     *
     * @var string
     */
    protected string $eventPrefix = '';

    /**
     * 触发单个事件
     *
     * @param string $event 事件名
     * @param mixed  ...$args 传递参数
     * @return mixed 监听器返回值（非 null 则停止传播）
     */
    protected function dispatch(string $event, ...$args)
    {
        $eventName = $this->eventPrefix . $event;
        return \event($eventName, ...$args);
    }

    /**
     * 批量触发事件
     *
     * @param string[] $events 事件名列表
     * @param mixed    ...$args 传递参数（每个事件共享同一组参数）
     * @return array 每个事件的返回值
     */
    protected function dispatchMany(array $events, ...$args): array
    {
        $results = [];
        foreach ($events as $event) {
            $results[$event] = $this->dispatch($event, ...$args);
        }
        return $results;
    }

    /**
     * 触发事件，任意监听器返回 false 则停止后续传播
     *
     * @param string $event 事件名
     * @param mixed  ...$args 传递参数
     * @return bool 是否全部通过
     */
    protected function dispatchUntil(string $event, ...$args): bool
    {
        if (!function_exists('eventUntil')) {
            return true;
        }
        $eventName = $this->eventPrefix . $event;
        return \eventUntil($eventName, ...$args);
    }
}
