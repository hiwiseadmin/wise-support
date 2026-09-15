<?php
declare(strict_types=1);

namespace wise\support\lock;

/**
 * 分布式锁核心接口
 */
interface LockInterface
{
    /**
     * 尝试获取锁
     *
     * @param string $key 锁标识（如 'crontab:send_email'）
     * @param int    $ttl 锁有效期（秒），到期自动释放防止死锁
     * @return bool 获取成功返回 true，已被占用返回 false
     */
    public function acquire(string $key, int $ttl): bool;

    /**
     * 释放锁（仅锁持有者可释放）
     *
     * @param string $key 锁标识
     * @return bool 释放成功返回 true
     */
    public function release(string $key): bool;

    /**
     * 检查是否已被锁定
     *
     * @param string $key 锁标识
     */
    public function isAcquired(string $key): bool;
}
