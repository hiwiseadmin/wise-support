<?php
declare(strict_types=1);

namespace wise\support\traits;

/**
 * 提供 tap() 方法用于在链式调用中执行回调 Trait
 *
 * 执行"旁观"操作（inspecting / side-effecting），不改变主调用链。
 *
 * 典型场景：
 *  - 调试输出中间状态：->tap(fn($v) => dump($v))->next()
 *  - 记录日志：->tap(fn($v) => Log::info($v))
 *  - 更新缓存而不影响最终返回值
 *
 * 使用示例：
 * <pre>
 * $result = Builder::make()
 *     ->size(300)
 *     ->tap(fn($b) => Log::info('构建参数', $b->toArray()))
 *     ->color('#ff0000')
 *     ->generate();
 * </pre>
 *
 * @package wise\support\traits
 */
trait Tappable
{
    /**
     * 执行回调并始终返回 $this
     *
     * @param callable $callback fn($this): void
     * @return self
     */
    public function tap(callable $callback): self
    {
        $callback($this);

        return $this;
    }
}
