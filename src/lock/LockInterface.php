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
     * 检查该 key 是否已被锁定（**占用语义**）
     *
     * 语义为「是否被任意进程持有」，三个驱动（redis / file / database）必须一致。
     * 需要判断「本实例是否持有」（例如释放前自检）请用具体实现上的
     * `DistributedLock::isHeld()`：本接口的方法集保持三项不变，不新增方法。
     *
     * @param string $key 锁标识
     * @return bool 被任意进程持有返回 true
     */
    public function isAcquired(string $key): bool;
}
