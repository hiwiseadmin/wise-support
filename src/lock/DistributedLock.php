<?php
declare(strict_types=1);

namespace wise\support\lock;

use think\facade\Cache;
use think\facade\Db;
use wise\support\runtime\Runtime;

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
 * 【Swoole 部署注意】Redis 连接取自 Cache::store($redisStore)->handler()，即 worker 内
 * 共享的同一条 phpredis 连接。本类的加锁/释放均为单命令或单次 Lua，原子性不受影响；
 * 但多协程共用一条连接时，大 value 传输会相互阻塞。建议为 redis store 开启连接池
 * （think-swoole 的 pool.redis），或在协程内按需 new \Redis() 建独立短连接后显式关闭。
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
     *   'db_table'      => 'system_lock',   // Database: 锁表名（不含表前缀，前缀取自连接配置）
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

    /**
     * 本实例是否持有该 key 的锁
     *
     * 与 isAcquired()（「该 key 是否被任意进程持有」）区分开：需要「我能安全释放它吗」
     * 这类自检时用本方法。未纳入 LockInterface，避免破坏既有实现。
     */
    public function isHeld(string $key): bool
    {
        return match ($this->driver) {
            'file'     => isset($this->fileHandles[$key]),
            'database' => isset($this->dbLockIds[$key]),
            default    => isset($this->tokens[$key]),
        };
    }


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
        if ($this->driver === 'file' && $this->blockingTimeout <= 0) {
            return $this->acquireFileNativeBlocking($key, $ttl);
        }

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

            $delay = min(100000, 1000 * (1 << min($attempt, 7)));
            $remaining = (int) (($deadline - $now) * 1_000_000);
            if ($remaining <= 0) {
                return false;
            }
            Runtime::sleep(min($delay, $remaining) / 1_000_000);
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

        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, (string) time());
            $this->fileHandles[$key] = $fp;
            return true;
        }

        fclose($fp);
        return false;
    }


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

            Runtime::sleep(min(50000, max(1000, ($waitMs - $elapsed) * 1000)) / 1_000_000);
        }
    }


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
            return $redis->get($key) !== false;
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
        }
        return null;
    }


    private function getLockFile(string $key): string
    {
        if (!is_dir($this->lockDir)) {
            @mkdir($this->lockDir, 0755, true);
        }
        return $this->lockDir . DIRECTORY_SEPARATOR . md5($key) . '.lock';
    }

    private function acquireFile(string $key, int $ttl): bool
    {
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

        fclose($fp);
        return false;
    }

    private function releaseFile(string $key): bool
    {
        if (!isset($this->fileHandles[$key])) {
            return false;
        }

        $fp = $this->fileHandles[$key];

        flock($fp, LOCK_UN);
        fclose($fp);
        unset($this->fileHandles[$key]);

        return true;
    }

    private function isAcquiredFile(string $key): bool
    {
        $lockFile = $this->getLockFile($key);

        if (!file_exists($lockFile)) {
            return false;
        }

        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
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


    private function acquireDb(string $key, int $ttl): bool
    {
        $this->ensureDbTable();

        $now = time();
        $lockKey = md5($key);

        try {
            $db = Db::connect($this->dbConnection);

            try {
                $inserted = $db->name($this->dbTable)->insert([
                    'lock_key'    => $lockKey,
                    'task_name'   => $key,
                    'create_time' => date('Y-m-d H:i:s'),
                    'expires_at'  => $now + $ttl,
                ]);
                if ($inserted) {
                    $this->dbLockIds[$key] = (int) $db->getLastInsID();
                    return true;
                }
            } catch (\Throwable $e) {
            }

            $db->name($this->dbTable)
                ->where('lock_key', $lockKey)
                ->where('expires_at', '<', $now)
                ->delete();

            try {
                $inserted = $db->name($this->dbTable)->insert([
                    'lock_key'    => $lockKey,
                    'task_name'   => $key,
                    'create_time' => date('Y-m-d H:i:s'),
                    'expires_at'  => $now + $ttl,
                ]);
                if ($inserted) {
                    $this->dbLockIds[$key] = (int) $db->getLastInsID();
                    return true;
                }
            } catch (\Throwable $e) {
            }
        } catch (\Throwable $e) {
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
                ->name($this->dbTable)
                ->where('id', $id)
                ->delete();
            return $affected > 0;
        } catch (\Throwable $e) {
            $this->dbLockIds[$key] = $id;
            return false;
        }
    }

    private function isAcquiredDb(string $key): bool
    {
        try {
            return Db::connect($this->dbConnection)
                ->name($this->dbTable)
                ->where('lock_key', md5($key))
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

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $this->dbTable)) {
            error_log('[DistributedLock] Invalid db_table name: ' . $this->dbTable);
            return;
        }

        try {
            $db = Db::connect($this->dbConnection);

            $table = (string) $db->name($this->dbTable)->getTable();

            $exists = $db->query(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1',
                ['table' => $table]
            );
            if (empty($exists)) {
                $db->execute("CREATE TABLE `{$table}` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `lock_key` VARCHAR(64) NOT NULL COMMENT '锁键（MD5）',
                    `task_name` VARCHAR(255) NOT NULL COMMENT '任务名称',
                    `create_time` DATETIME NOT NULL COMMENT '创建时间',
                    `expires_at` INT UNSIGNED NOT NULL COMMENT '过期时间戳（Unix 秒）',
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


    private function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function __destruct()
    {
        foreach ($this->fileHandles as $key => $fp) {
            try {
                flock($fp, LOCK_UN);
                fclose($fp);
            } catch (\Throwable $e) {
            }
        }
        $this->fileHandles = [];

        foreach ($this->dbLockIds as $key => $id) {
            try {
                Db::connect($this->dbConnection)
                    ->name($this->dbTable)
                    ->where('id', $id)
                    ->delete();
            } catch (\Throwable $e) {
            }
        }
    }
}
