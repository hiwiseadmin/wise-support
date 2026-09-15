<?php

declare(strict_types=1);

namespace wise\support\rate;

/**
 * 频率限制统一接口
 *
 * 所有限流算法实现此接口，支持多态替换与中间件集成。
 * 
 * attempt() 为原子检查+消费操作，status() 为只读查询。
 */
interface RateLimitInterface
{
    /**
     * 尝试通过限流检查并消费（原子操作）
     *
     * @param string $key     限流主键
     * @param array  $options 算法特定参数（如 limit/window/capacity/rate/weight）
     * @return array{allowed: bool, remaining: int|float, ...} 算法特定扩展字段
     */
    public function attempt(string $key, array $options = []): array;

    /**
     * 查询当前状态（不消费）
     *
     * @param string $key     限流主键
     * @param array  $options 算法特定参数
     * @return array{current: int|float, ...}
     */
    public function status(string $key, array $options = []): array;

    /**
     * 重置指定 key 的限流状态
     *
     * @param string $key 限流主键
     */
    public function reset(string $key): void;
}
