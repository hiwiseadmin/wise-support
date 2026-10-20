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
 *   - 多值传参：$this->dispatch('OrderPaid', ['order' => $order, 'channel' => 'wechat']);
 *   - 批量事件：$this->dispatchMany(['UserCreated', 'WelcomeEmail'], $user);
 *   - 短路事件：$this->dispatchUntil('BeforeDelete', $id);
 *
 * 注意：框架 event() 辅助函数只接收一个载荷参数（event($event, $args = null)），
 * 因此 dispatch() 传入多个参数时仅有第一个会到达监听器，需要多值请使用数组载荷。
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
     * 框架 event() 辅助函数仅接收 1 个载荷参数，多参调用只有第一个能到达监听器，
     * 需要传多个值时请使用数组载荷：$this->dispatch('OrderPaid', ['order' => $order])。
     *
     * @param string $event 事件名
     * @param mixed  ...$args 传递参数（框架限制：仅第一个生效）
     * @return mixed 监听器返回值（非 null 则停止传播）
     */
    protected function dispatch(string $event, ...$args)
    {
        $eventName = $this->eventPrefix . $event;

        if (\count($args) > 1) {
            $this->warnDroppedEventArgs($eventName, \count($args));
        }

        return \event($eventName, ...$args);
    }

    /**
     * 多参数丢参告警
     *
     * 仅在 app.debug 开启时输出，且任何异常都不得影响事件派发本身。
     */
    private function warnDroppedEventArgs(string $eventName, int $argCount): void
    {
        try {
            if (!\class_exists(\think\facade\Config::class)) {
                return;
            }
            if (!(bool) \think\facade\Config::get('app.debug', false)) {
                return;
            }
            error_log(sprintf(
                'EventTrait::dispatch(%s) 收到 %d 个参数，框架 event() 仅支持 1 个，其余参数已被丢弃；请改用数组载荷',
                $eventName,
                $argCount
            ));
        } catch (\Throwable) {
            // 容器 / 配置不可用时忽略告警
        }
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
