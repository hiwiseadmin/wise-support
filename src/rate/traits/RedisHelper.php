<?php

declare(strict_types=1);

namespace wise\support\rate\traits;

use think\facade\Cache;

/**
 * Redis 连接获取 Trait
 *
 * 为 SlidingWindow / TokenBucket 提供统一的 Redis 连接获取能力。
 * Redis 不可用时返回 null，由调用方按实例策略决定：默认 fail-closed（拒绝），
 * 可用性优先的入口在构造期显式 ->failOpen() 放行。
 */
trait RedisHelper
{
    /**
     * 获取 Redis 连接；不可用时返回 null，交由调用方决策
     *
     * @return \Redis|null
     */
    protected function getRedis()
    {
        try {
            $handler = Cache::store('redis')->handler();
            // instanceof 兼容 Redis 子类与代理实现；RedisCluster 的 eval 语义不同，
            // 需单独适配，这里按不支持处理并留下可排查的日志。
            if ($handler instanceof \Redis) {
                return $handler;
            }
            if (is_object($handler)) {
                error_log(sprintf(
                    '[RateLimiter] Redis handler type not supported, return null: %s',
                    get_class($handler)
                ));
            }
        } catch (\Throwable $e) {
            // 连接不可用 — 返回 null，由调用方按实例策略决策（默认 fail-closed），需记录以便排查
            error_log(sprintf(
                '[RateLimiter] Redis unavailable, return null: %s',
                $e->getMessage()
            ));
        }

        return null;
    }
}
