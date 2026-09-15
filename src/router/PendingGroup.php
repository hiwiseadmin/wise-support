<?php

declare(strict_types=1);

namespace wise\support\router;

/**
 * 延迟路由分组句柄
 *
 * 应用路由不再在 ServiceProvider::boot() 阶段直接写入 Route，而是先投递到
 * route.{app} 扩展点，等到 think-multi-app 解析出当前应用、加载该应用的
 * route 文件时，才由 RouteRegistrar::applyRoutes() 真正创建分组。
 *
 * RouteRegistrar::group() 因此无法返回一个真实的 \think\route\RuleGroup，
 * 这里返回一个句柄承接基类返回之后的链式调用（allowCrossDomain() 等），
 * 待分组真正创建后再原样重放。
 *
 * @package wise\support\router
 */
class PendingGroup
{
    /**
     * 记录下来的链式调用
     *
     * @var array<int, array{method: string, args: array<int, mixed>}>
     */
    protected array $calls = [];

    /**
     * 承接任意链式调用
     *
     * @param array<int, mixed> $args
     */
    public function __call(string $method, array $args): static
    {
        $this->calls[] = ['method' => $method, 'args' => $args];

        return $this;
    }

    /**
     * 把记录的调用重放到真实分组对象上
     */
    public function replay(?object $group): void
    {
        if ($group === null) {
            return;
        }

        foreach ($this->calls as $call) {
            if (!is_callable([$group, $call['method']])) {
                continue;
            }
            $group->{$call['method']}(...$call['args']);
        }
    }
}
