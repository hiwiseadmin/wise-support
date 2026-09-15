<?php
declare(strict_types=1);

namespace wise\support\rate;

use think\facade\Cache;

/**
 * 原子递增计数器
 *
 * 提供统一的原子递增能力，三层降级策略：
 *  1. Redis Lua INCR+EXPIRE（真原子，生产推荐）
 *  2. ThinkPHP Cache::inc()（驱动级原子）
 *  3. Cache::get()+set()（非原子兜底，低并发场景可接受）
 *
 */
class AtomicCounter
{
    /**
     * 原子递增并返回当前计数
     *
     * @param string $key     缓存键
     * @param int    $expire  过期秒数
     * @param string $context 调用方标识（用于日志追踪）
     * @return int 递增后的值
     */
    public static function increment(string $key, int $expire, string $context = 'AtomicCounter'): int
    {
        // L1: Redis Lua 原子递增（显式使用 redis store，与同目录 RedisHelper 一致）
        try {
            $handler = Cache::store('redis')->handler();
            if (is_object($handler) && get_class($handler) === 'Redis') {
                $lua = <<<'LUA'
local v = redis.call('INCR', KEYS[1])
if v == 1 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end
return v
LUA;
                return (int) $handler->eval($lua, [$key, $expire], 1);
            }
        } catch (\Throwable $e) {
            \think\facade\Log::warning(
                "[{$context}] Redis atomicIncrement failed, fallback to cache: {$e->getMessage()}"
            );
        }

        // L2: ThinkPHP Cache::inc() 驱动级原子递增
        try {
            $val = Cache::inc($key, 1);
            if ($val === false) {
                throw new \RuntimeException('Cache::inc() returned false');
            }
            $val = (int) $val;
            if ($val === 1) {
                // 计数键重建时必须确保 TTL 存在：若此前计数键被外部删除而哨兵键
                // 仍在，不设 TTL 会让计数键永久残留。哨兵仅用于降低重复写，
                // 不应成为正确性前提。
                Cache::set($key, 1, $expire);
                if (!Cache::has($key . ':expire_set')) {
                    Cache::set($key . ':expire_set', 1, $expire);
                }
            }
            return $val;
        } catch (\Throwable $e) {
            \think\facade\Log::warning(
                "[{$context}] Cache atomicIncrement failed, using non-atomic fallback: {$e->getMessage()}"
            );

            // L3: 非原子兜底，极低并发下可接受
            $val = Cache::get($key, 0);
            $val = (int) $val + 1;
            Cache::set($key, $val, $expire);
            return $val;
        }
    }
}
