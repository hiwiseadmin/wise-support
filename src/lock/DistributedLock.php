<?php
declare(strict_types=1);

namespace wise\support\lock;

use think\facade\Cache;
use think\facade\Db;

/**
 * 分布式锁 — 三驱动：Redis | File | Database
 *
 * - redis    → SET NX EX + Lua 原子释放，多机部署首选
 * - file     → flock 内核级排他锁，单机零依赖
 * - database → INSERT UNIQUE KEY 互斥，多机无 Redis 时用
 *
 * 核心特性：
 * - Token 所有权：每个锁持有唯一 token，防止进程 A 误删进程 B 的锁
 * - Lua 原子释放：GET + DEL 原子操作，避免竞争
 * - TTL 兜底：锁到期自动释放，防止死锁
 * - withLock() 语法糖：自动 acquire + 执行 + release
 * - 阻塞/非阻塞双模式共存：blocking 选项切换，blocking_timeout 防止永远等不到
 *
 * 用法：
 * ```php
 * // 默认非阻塞（立即返回）
 * $lock = new DistributedLock('redis');
 * if ($lock->acquire('task:send_sms', 60)) {
 *     try { doSomething(); }
 *     finally { $lock->release('task:send_sms'); }
 * }
 *
 * // 阻塞模式：等最多 10 秒（指数退避轮询，避免永远等不到）
 * $lock = new DistributedLock('redis', ['blocking' => true, 'blocking_timeout' => 10]);
 * if ($lock->acquire('task:send_sms', 60)) {
 *     try { doSomething(); }
 *     finally { $lock->release('task:send_sms'); }
 * }
 *
 * // 文件驱动原生阻塞（内核级挂起，blocking_timeout=0）
 * $lock = new DistributedLock('file', ['blocking' => true, 'blocking_timeout' => 0]);
 *
 * // 语法糖模式
 * $lock->withLock('task:send_sms', 60, function () { doSomething(); });
 * ```
 */
class DistributedLock implements LockInterface
{
    /** @var array<string, string> 已持有的锁 token（key => token） */
    private array $tokens = [];

    /** @var array<string, mixed> 文件锁句柄（key => resource） */
    private array $fileHandles = [];

    /** @var string 驱动类型：redis | file | database */
    private string $driver;

    /** @var string 缓存 store（Redis 驱动时使用） */
    private string $redisStore;

    /** @var string 文件锁目录（File 驱动时使用） */
    private string $lockDir;

    /** @var string 数据库表名（Database 驱动时使用） */
    private string $dbTable;

    /** @var string 数据库连接名（Database 驱动时使用，'' = 默认） */
    private string $dbConnection;

    /** @var array<string, int> 持有的 DB 锁 ID（key => id） */
    private array $dbLockIds = [];

    /** @var bool 锁表是否已初始化 */
    private bool $dbTableReady = false;

    /** @var bool 阻塞模式：true 时 acquire() 会等待直到获取锁或超时 */
    private bool $blocking = false;

    /** @var int 阻塞超时秒数（>0 时通过指数退避轮询实现，0 仅在 file 驱动且 blocking=true 时使用原生 flock 阻塞） */
    private int $blockingTimeout = 10;

    /**
     * @param string $driver  驱动类型：'redis'（默认）| 'file' | 'database'
     * @param array  $options [
     *   'redis_store'   => 'redis',         // Redis: 缓存 store 名
     *   'lock_dir'      => runtime_path().'lock',  // File: 锁文件目录
     *   'db_table'      => 'system_lock',   // Database: 锁表名
     *   'db_connection' => '',              // Database: 数据库连接名（'' = 默认）
     *   'blocking'      => false,           // 是否启用阻塞模式（与默认非阻塞共存）
     *   'blocking_timeout' => 10,          // 阻塞超时秒数（0=不限制，仅 file 原生 flock 可用，防止永远等不到）
     * ]
     */
    public function __construct(string $driver = 'redis', array $options = [])
    {
        $this->driver       = $driver;
        $this->redisStore   = $options['redis_store'] ?? 'redis';
        $this->lockDir      = $options['lock_dir'] ?? (runtime_path() . 'lock');
        $this->dbTable      = $options['db_table'] ?? 'system_lock';
        $this->dbConnection = $options['db_connection'] ?? '';
        $this->blocking     = $options['blocking'] ?? false;
        $this->blockingTimeout = (int) ($options['blocking_timeout'] ?? 10);
    }

    // ── 核心接口 ──

    public function acquire(string $key, int $ttl): bool
    {
        if ($this->blocking) {
            return $this->acquireBlocking($key, $ttl);
        }
        return match ($this->driver) {
            'file'     => $this->acquireFile($key, $ttl),
            'database' => $this->acquireDb($key, $ttl),
            default    => $this->acquireRedis($key, $ttl),
        };
    }

    public function release(string $key): bool
    {
        return match ($this->driver) {
            'file'     => $this->releaseFile($key),
            'database' => $this->releaseDb($key),
            default    => $this->releaseRedis($key),
        };
    }

    public function isAcquired(string $key): bool
    {
        return match ($this->driver) {
            'file'     => $this->isAcquiredFile($key),
            'database' => $this->isAcquiredDb($key),
            default    => $this->isAcquiredRedis($key),
        };
    }

    // ── 阻塞模式（与非阻塞共存） ──

    /**
     * 阻塞获取锁，直到成功或超时
     *
     * 三驱动统一通过指数退避轮询实现，避免"永远等不到"。
     * file 驱动额外支持原生阻塞（blocking_timeout=0 且 blocking=true 时使用 flock LOCK_EX）。
     *
     * @param string $key 锁标识
     * @param int    $ttl 锁有效期
     * @return bool 是否成功
     */
    private function acquireBlocking(string $key, int $ttl): bool
    {
        // file 驱动 + 无超时 = 原生阻塞（调用方自行保证不会永远等不到）
        if ($this->driver === 'file' && $this->blockingTimeout <= 0) {
            return $this->acquireFileNativeBlocking($key, $ttl);
        }

        // 统一的指数退避轮询（所有驱动适用）
        $deadline = microtime(true) + $this->blockingTimeout;
        $attempt = 0;

        while (true) {
            $acquired = match ($this->driver) {
                'file'     => $this->acquireFile($key, $ttl),
                'database' => $this->acquireDb($key, $ttl),
                default    => $this->acquireRedis($key, $ttl),
            };

            if ($acquired) {
                return true;
            }

            $now = microtime(true);
            if ($now >= $deadline) {
                return false;
            }

            // 指数退避：1ms, 2ms, 4ms, 8ms, 16ms, 32ms, 64ms, 100ms (封顶)
            $delay = min(100000, 1000 * (1 << min($attempt, 7)));
            // 确保不会超过 deadline
            $remaining = (int) (($deadline - $now) * 1_000_000);
            if ($remaining <= 0) {
                return false;
            }
            usleep(min($delay, $remaining));
            $attempt++;
        }
    }

    /**
     * 文件驱动原生阻塞（flock LOCK_EX，内核级挂起）
     *
     * 仅在 blocking_timeout=0 时使用，调用方必须确保锁一定会被释放。
     */
    private function acquireFileNativeBlocking(string $key, int $ttl): bool
    {
        $lockFile = $this->getLockFile($key);
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            return false;
        }

        // 原生阻塞：无 LOCK_NB，进程由内核挂起直到锁可用
        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, (string) time());
            $this->fileHandles[$key] = $fp;
            return true;
        }

        fclose($fp);
        return false;
    }

    // ── 语法糖 ──

    /**
     * 在锁保护下执行回调
     *
     * @param string   $key      锁标识
     * @param int      $ttl      锁有效期
     * @param callable $callback 回调函数
     * @param int      $waitMs   未获取锁时的等待毫秒数（0=立即返回）
     * @return array{acquired: bool, result: mixed} acquired 表示是否成功获取锁
     * @throws \RuntimeException 回调执行失败时抛出
     */
    public function withLock(string $key, int $ttl, callable $callback, int $waitMs = 0): array
    {
        // 阻塞模式：acquire() 已内部处理等待和超时，不需要外层轮询
        if ($this->blocking) {
            if ($this->acquire($key, $ttl)) {
                try {
                    return ['acquired' => true, 'result' => $callback()];
                } finally {
                    $this->release($key);
                }
            }
            return ['acquired' => false, 'result' => null];
        }

        // 非阻塞模式：外层轮询（保持向后兼容）
        $start = microtime(true);
        while (true) {
            if ($this->acquire($key, $ttl)) {
                try {
                    return ['acquired' => true, 'result' => $callback()];
                } finally {
                    $this->release($key);
                }
            }

            if ($waitMs <= 0) {
                return ['acquired' => false, 'result' => null];
            }

            $elapsed = (int) ((microtime(true) - $start) * 1000);
            if ($elapsed >= $waitMs) {
                return ['acquired' => false, 'result' => null];
            }

            usleep(min(50000, max(1000, ($waitMs - $elapsed) * 1000)));
        }
    }

    // ── Redis 驱动 ──

    private function acquireRedis(string $key, int $ttl): bool
    {
        $token = $this->generateToken();
        $redis = $this->getRedis();

        if ($redis === null) {
            return false;
        }

        try {
            $result = $redis->set($key, $token, ['nx', 'ex' => $ttl]);
            if ($result) {
                $this->tokens[$key] = $token;
                return true;
            }
        } catch (\Throwable $e) {
            // Redis 不可用，fail-open，但记录日志便于排查
            error_log(sprintf(
                '[DistributedLock] Redis acquire failed for key "%s": %s',
                $key,
                $e->getMessage()
            ));
        }

        return false;
    }

    private function releaseRedis(string $key): bool
    {
        if (!isset($this->tokens[$key])) {
            return false;
        }

        $expectedToken = $this->tokens[$key];
        unset($this->tokens[$key]);

        $redis = $this->getRedis();
        if ($redis === null) {
            return false;
        }

        try {
            $lua = <<<'LUA'
if redis.call("GET", KEYS[1]) == ARGV[1] then
    return redis.call("DEL", KEYS[1])
else
    return 0
end
LUA;
            return (int) $redis->eval($lua, [$key, $expectedToken], 1) === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function isAcquiredRedis(string $key): bool
    {
        $redis = $this->getRedis();
        if ($redis === null) {
            return false;
        }

        try {
            $storedToken = $redis->get($key);
            if ($storedToken === false) {
                return false;
            }
            return isset($this->tokens[$key]) && hash_equals($this->tokens[$key], $storedToken);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return \Redis|null
     */
    private function getRedis(): ?object
    {
        try {
            $handler = Cache::store($this->redisStore)->handler();
            if (class_exists('\Redis') && $handler instanceof \Redis) {
                return $handler;
            }
        } catch (\Throwable $e) {
            // 连接不可用
        }
        return null;
    }

    // ── File 驱动 ──

    private function getLockFile(string $key): string
    {
        if (!is_dir($this->lockDir)) {
            @mkdir($this->lockDir, 0755, true);
        }
        return $this->lockDir . DIRECTORY_SEPARATOR . md5($key) . '.lock';
    }

    private function acquireFile(string $key, int $ttl): bool
    {
        // 文件锁由内核 flock 管理生命周期，无 TTL 自动过期语义，$ttl 仅用于统一驱动接口
        unset($ttl);

        $lockFile = $this->getLockFile($key);

        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            return false;
        }

        if (flock($fp, LOCK_EX | LOCK_NB)) {
            ftruncate($fp, 0);
            fwrite($fp, (string) time());
            $this->fileHandles[$key] = $fp;
            return true;
        }

        // flock 是内核级排他锁：只要旧进程仍持有该 fd，此处的 LOCK_NB 就必然失败。
        // 基于 filemtime 的"过期覆盖"无法绕过内核锁，属于不可达的死代码，
        // 且会误导"文件锁具备 TTL 自动过期"的语义。故获取失败时直接释放 fd 并返回 false。
        // 陈旧锁文件的清理应由锁所有者显式 release() 时 unlink 完成。
        fclose($fp);
        return false;
    }

    private function releaseFile(string $key): bool
    {
        if (!isset($this->fileHandles[$key])) {
            return false;
        }

        $fp = $this->fileHandles[$key];
        $lockFile = $this->getLockFile($key);

        flock($fp, LOCK_UN);
        fclose($fp);
        unset($this->fileHandles[$key]);

        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }

        return true;
    }

    private function isAcquiredFile(string $key): bool
    {
        $lockFile = $this->getLockFile($key);

        // 非阻塞尝试获取锁来判断是否被占用
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            // fopen 失败说明文件不存在或无权限，保守返回 false 避免误判
            return false;
        }

        if (flock($fp, LOCK_EX | LOCK_NB)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }

        fclose($fp);
        return true;
    }

    // ── Database 驱动 ──

    private function acquireDb(string $key, int $ttl): bool
    {
        $this->ensureDbTable();

        $now = time();
        $lockKey = md5($key);

        try {
            $db = Db::connect($this->dbConnection);

            // 先尝试获取锁：INSERT 唯一键，冲突即被占用
            try {
                $inserted = $db->table($this->dbTable)->insert([
                    'lock_key'    => $lockKey,
                    'task_name'   => $key,
                    'create_time' => $now,
                    'expires_at'  => $now + $ttl,
                ]);
                if ($inserted) {
                    $this->dbLockIds[$key] = (int) $db->getLastInsID();
                    return true;
                }
            } catch (\Throwable $e) {
                // INSERT 冲突：说明该 key 的锁已存在，检查是否为过期锁
            }

            // 仅清理当前 key 的过期锁，缩小竞态窗口
            $db->table($this->dbTable)
                ->where('lock_key', $lockKey)
                ->where('expires_at', '<', $now)
                ->delete();

            // 重试插入
            try {
                $inserted = $db->table($this->dbTable)->insert([
                    'lock_key'    => $lockKey,
                    'task_name'   => $key,
                    'create_time' => $now,
                    'expires_at'  => $now + $ttl,
                ]);
                if ($inserted) {
                    $this->dbLockIds[$key] = (int) $db->getLastInsID();
                    return true;
                }
            } catch (\Throwable $e) {
                // 二次冲突，锁确实被占用
            }
        } catch (\Throwable $e) {
            // 连接失败
        }

        return false;
    }

    private function releaseDb(string $key): bool
    {
        if (!isset($this->dbLockIds[$key])) {
            return false;
        }

        $id = $this->dbLockIds[$key];
        unset($this->dbLockIds[$key]);

        try {
            $affected = Db::connect($this->dbConnection)
                ->table($this->dbTable)
                ->where('id', $id)
                ->delete();
            return $affected > 0;
        } catch (\Throwable $e) {
            // 释放失败，重新注册 ID 以备后续重试
            $this->dbLockIds[$key] = $id;
            return false;
        }
    }

    private function isAcquiredDb(string $key): bool
    {
        if (!isset($this->dbLockIds[$key])) {
            return false;
        }

        try {
            return Db::connect($this->dbConnection)
                ->table($this->dbTable)
                ->where('id', $this->dbLockIds[$key])
                ->where('expires_at', '>', time())
                ->count() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function ensureDbTable(): void
    {
        if ($this->dbTableReady) {
            return;
        }
        $this->dbTableReady = true;

        // 校验表名，仅允许字母、数字、下划线，防止 SQL 注入
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $this->dbTable)) {
            error_log('[DistributedLock] Invalid db_table name: ' . $this->dbTable);
            return;
        }

        try {
            $db = Db::connect($this->dbConnection);
            $exists = $db->query(
                'SHOW TABLES LIKE :table',
                ['table' => $this->dbTable]
            );
            if (empty($exists)) {
                $db->execute("CREATE TABLE `{$this->dbTable}` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `lock_key` VARCHAR(64) NOT NULL COMMENT '锁键（MD5）',
                    `task_name` VARCHAR(255) NOT NULL COMMENT '任务名称',
                    `create_time` INT UNSIGNED NOT NULL COMMENT '创建时间戳',
                    `expires_at` INT UNSIGNED NOT NULL COMMENT '过期时间戳',
                    UNIQUE KEY `uk_lock_key` (`lock_key`),
                    KEY `idx_expires_at` (`expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分布式锁表'");
            }
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[DistributedLock] 初始化锁表 [%s] 失败: %s (connection=%s, trace=%s:%d)',
                $this->dbTable,
                $e->getMessage(),
                $this->dbConnection ?: 'default',
                $e->getFile(),
                $e->getLine()
            ));
        }
    }

    // ── 内部 ──

    private function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function __destruct()
    {
        // 释放所有持有的文件锁
        foreach ($this->fileHandles as $key => $fp) {
            try {
                flock($fp, LOCK_UN);
                fclose($fp);
                $lockFile = $this->getLockFile($key);
                if (file_exists($lockFile)) {
                    @unlink($lockFile);
                }
            } catch (\Throwable $e) {
                // destruct 中静默
            }
        }

        // 释放所有持有的 DB 锁
        foreach ($this->dbLockIds as $key => $id) {
            try {
                Db::connect($this->dbConnection)
                    ->table($this->dbTable)
                    ->where('id', $id)
                    ->delete();
            } catch (\Throwable $e) {
                // destruct 中静默
            }
        }
    }
}
