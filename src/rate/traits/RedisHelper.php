<?php

declare(strict_types=1);

namespace wise\support\rate\traits;

use think\facade\Cache;

/**
 * Redis 连接获取 Trait
 *
 * 为 SlidingWindow / TokenBucket 提供统一的 Redis 连接获取能力。
 * 采用 fail-open 策略：Redis 不可用时返回 null，由调用方放行请求。
 */
trait RedisHelper
{
    /**
     * 获取 Redis 连接（fail-open 模式）
     *
     * @return \Redis|null
     */
    protected function getRedis()
    {
        try {
            $handler = Cache::store('redis')->handler();
            if (is_object($handler) && get_class($handler) === 'Redis') {
                return $handler;
            }
        } catch (\Throwable $e) {
            // 连接不可用 — 放行，避免业务雪崩，但需记录以便排查
            error_log(sprintf(
                '[RateLimiter] Redis unavailable, fallback to fail-open: %s',
                $e->getMessage()
            ));
        }

        return null;
    }
}
