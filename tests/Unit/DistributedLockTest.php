<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use think\Container;
use wise\support\lock\DistributedLock;
use wise\support\lock\LockInterface;

/**
 * 分布式锁 DistributedLock 单元测试
 *
 * 覆盖 File 驱动（可用文件系统真实验证）、Redis / Database 驱动的不可用降级路径，
 * 以及阻塞模式、withLock() 语法糖与析构释放。
 */
class DistributedLockTest extends TestCase
{
    private string $lockDir;
    private string|false $originalErrorLog;

    protected function setUp(): void
    {
        $this->lockDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise_lock_' . bin2hex(random_bytes(4));
        // 降级路径会调用 error_log，重定向到临时文件避免污染测试输出
        $this->originalErrorLog = ini_get('error_log');
        ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise_lock_error.log');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->lockDir);
        Container::getInstance()->delete(\think\App::class);
        if ($this->originalErrorLog !== false) {
            ini_set('error_log', $this->originalErrorLog);
        }
    }

    private function makeFileLock(array $options = []): DistributedLock
    {
        return new DistributedLock('file', array_merge(['lock_dir' => $this->lockDir], $options));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            is_dir($file) ? $this->removeDir($file) : @unlink($file);
        }
        @rmdir($dir);
    }

    // ============================================================
    // 契约与构造
    // ============================================================

    public function test_implements_lock_interface(): void
    {
        $this->assertInstanceOf(LockInterface::class, $this->makeFileLock());
    }

    public function test_constructor_option_defaults(): void
    {
        // 未传 lock_dir 时回落到 runtime_path()
        Container::getInstance()->bind(\think\App::class, new DistributedLockAppStub());

        $lock = new DistributedLock('file');

        $this->assertSame(
            DistributedLockAppStub::RUNTIME . 'lock',
            $this->readPrivate($lock, 'lockDir')
        );
    }

    public function test_constructor_uses_provided_options(): void
    {
        $lock = new DistributedLock('database', [
            'redis_store'      => 'my_redis',
            'lock_dir'         => '/tmp/xx',
            'db_table'         => 'my_lock',
            'db_connection'    => 'conn1',
            'blocking'         => true,
            'blocking_timeout' => 3,
        ]);

        $this->assertSame('database', $this->readPrivate($lock, 'driver'));
        $this->assertSame('my_redis', $this->readPrivate($lock, 'redisStore'));
        $this->assertSame('/tmp/xx', $this->readPrivate($lock, 'lockDir'));
        $this->assertSame('my_lock', $this->readPrivate($lock, 'dbTable'));
        $this->assertSame('conn1', $this->readPrivate($lock, 'dbConnection'));
        $this->assertTrue($this->readPrivate($lock, 'blocking'));
        $this->assertSame(3, $this->readPrivate($lock, 'blockingTimeout'));
    }

    private function readPrivate(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        return $reflection->getValue($object);
    }

    // ============================================================
    // File 驱动
    // ============================================================

    public function test_file_driver_acquire_release_roundtrip(): void
    {
        $lock = $this->makeFileLock();
        $key  = 'task:send_sms';

        $this->assertFalse($lock->isAcquired($key));
        $this->assertTrue($lock->acquire($key, 60));
        $this->assertTrue($lock->isAcquired($key));
        $this->assertTrue($lock->release($key));
        $this->assertFalse($lock->isAcquired($key));
    }

    public function test_file_driver_creates_lock_dir_and_file(): void
    {
        $lock = $this->makeFileLock();

        $lock->acquire('k', 60);

        $this->assertDirectoryExists($this->lockDir);
        $this->assertFileExists($this->lockDir . DIRECTORY_SEPARATOR . md5('k') . '.lock');
    }

    public function test_file_driver_release_unknown_key_returns_false(): void
    {
        $this->assertFalse($this->makeFileLock()->release('never'));
    }

    public function test_file_driver_second_instance_cannot_acquire_held_lock(): void
    {
        $first  = $this->makeFileLock();
        $second = $this->makeFileLock();

        $this->assertTrue($first->acquire('k', 60));
        $this->assertFalse($second->acquire('k', 60));

        $first->release('k');
        $this->assertTrue($second->acquire('k', 60));
    }

    public function test_file_driver_release_then_reacquire(): void
    {
        $lock = $this->makeFileLock();
        $lock->acquire('k', 60);
        $lock->release('k');

        $this->assertTrue($lock->acquire('k', 60));
        $this->assertTrue($lock->isAcquired('k'));
    }

    public function test_file_driver_destructor_releases_lock_but_keeps_file(): void
    {
        $lock = $this->makeFileLock();
        $lock->acquire('k', 60);
        $file = $this->lockDir . DIRECTORY_SEPARATOR . md5('k') . '.lock';
        $this->assertFileExists($file);

        unset($lock);

        // 锁文件必须保留：删除目录项会让等待者与新打开者锁住不同 inode，破坏 flock 互斥
        $this->assertFileExists($file);

        // 且锁确实已释放：另一个实例可以重新获取
        $this->assertTrue($this->makeFileLock()->acquire('k', 60));
    }

    public function test_file_driver_release_keeps_lock_file_and_inode(): void
    {
        $lock = $this->makeFileLock();
        $this->assertTrue($lock->acquire('k', 60));

        $file = $this->lockDir . DIRECTORY_SEPARATOR . md5('k') . '.lock';
        $inode = fileinode($file);
        $this->assertIsInt($inode);

        $this->assertTrue($lock->release('k'));

        $this->assertFileExists($file, '释放锁不得删除锁文件');
        $this->assertSame($inode, fileinode($file), '锁文件的 inode 必须保持稳定');

        // 竞争方仍可拿到同一把锁
        $this->assertTrue($this->makeFileLock()->acquire('k', 60));
    }

    public function test_is_acquired_is_occupancy_not_ownership(): void
    {
        $holder = $this->makeFileLock();
        $other  = $this->makeFileLock();

        $this->assertFalse($other->isAcquired('k'));
        $this->assertFalse($other->isHeld('k'), '未持有者 isHeld 必须为 false');

        $this->assertTrue($holder->acquire('k', 60));

        $this->assertTrue($other->isAcquired('k'), 'isAcquired 报告「被任意进程持有」');
        $this->assertFalse($other->isHeld('k'), 'isHeld 只报告本实例是否持有');
        $this->assertTrue($holder->isHeld('k'));

        $holder->release('k');

        $this->assertFalse($other->isAcquired('k'));
        $this->assertFalse($holder->isHeld('k'));
    }

    public function test_file_driver_native_blocking_acquire(): void
    {
        $lock = $this->makeFileLock(['blocking' => true, 'blocking_timeout' => 0]);

        $this->assertTrue($lock->acquire('k', 60));
        $this->assertTrue($lock->isAcquired('k'));
        $this->assertTrue($lock->release('k'));
    }

    public function test_file_driver_blocking_polling_acquires_free_lock(): void
    {
        $lock = $this->makeFileLock(['blocking' => true, 'blocking_timeout' => 1]);

        $this->assertTrue($lock->acquire('k', 60));
        $lock->release('k');
    }

    public function test_file_driver_blocking_polling_returns_false_on_timeout(): void
    {
        $holder = $this->makeFileLock();
        $holder->acquire('k', 60);

        // blocking_timeout > 0 → 走指数退避轮询，超时后返回 false
        $waiter = $this->makeFileLock(['blocking' => true, 'blocking_timeout' => 1]);

        $start = microtime(true);
        $acquired = $waiter->acquire('k', 60);
        $elapsed  = microtime(true) - $start;

        $this->assertFalse($acquired);
        $this->assertGreaterThanOrEqual(0.9, $elapsed, '阻塞模式应等待至超时');
    }

    public function test_redis_driver_blocking_timeout_zero_returns_immediately(): void
    {
        // 非 file 驱动 + blocking_timeout=0 → 死线即当前时刻，首轮失败后立即返回
        $lock = new DistributedLock('redis', ['blocking' => true, 'blocking_timeout' => 0]);

        $this->assertFalse($lock->acquire('k', 60));
    }

    // ============================================================
    // withLock() 语法糖
    // ============================================================

    public function test_with_lock_executes_callback_and_releases(): void
    {
        $lock = $this->makeFileLock();
        $ran  = false;

        $result = $lock->withLock('k', 60, function () use (&$ran) {
            $ran = true;
            return 'done';
        });

        $this->assertSame(['acquired' => true, 'result' => 'done'], $result);
        $this->assertTrue($ran);
        $this->assertFalse($lock->isAcquired('k'), '回调结束后应释放锁');
    }

    public function test_with_lock_returns_not_acquired_when_locked(): void
    {
        $holder = $this->makeFileLock();
        $holder->acquire('k', 60);

        $ran = false;
        $result = $this->makeFileLock()->withLock('k', 60, function () use (&$ran) {
            $ran = true;
            return 'x';
        });

        $this->assertSame(['acquired' => false, 'result' => null], $result);
        $this->assertFalse($ran);
    }

    public function test_with_lock_polls_until_timeout(): void
    {
        $holder = $this->makeFileLock();
        $holder->acquire('k', 60);

        $start  = microtime(true);
        $result = $this->makeFileLock()->withLock('k', 60, fn() => 'x', 60);
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertFalse($result['acquired']);
        $this->assertGreaterThanOrEqual(50, $elapsedMs);
    }

    public function test_with_lock_blocking_mode_success(): void
    {
        $lock = $this->makeFileLock(['blocking' => true, 'blocking_timeout' => 1]);

        $result = $lock->withLock('k', 60, fn() => 'blocking-done');

        $this->assertSame(['acquired' => true, 'result' => 'blocking-done'], $result);
    }

    public function test_with_lock_blocking_mode_returns_false_when_unavailable(): void
    {
        // redis 驱动 + 无连接 → acquire 失败 → 阻塞模式直接返回未获取
        $lock = new DistributedLock('redis', ['blocking' => true, 'blocking_timeout' => 1]);

        $result = $lock->withLock('k', 60, fn() => 'never');

        $this->assertSame(['acquired' => false, 'result' => null], $result);
    }

    // ============================================================
    // Redis / Database 驱动降级
    // ============================================================

    public function test_redis_driver_without_connection_returns_false(): void
    {
        $lock = new DistributedLock('redis');

        $this->assertFalse($lock->acquire('k', 60));
        $this->assertFalse($lock->isAcquired('k'));
        $this->assertFalse($lock->release('k'));
    }

    public function test_database_driver_without_connection_returns_false(): void
    {
        $lock = new DistributedLock('database', ['db_table' => 'system_lock']);

        $this->assertFalse($lock->acquire('k', 60));
        $this->assertFalse($lock->isAcquired('k'));
        $this->assertFalse($lock->release('k'));
    }

    public function test_database_driver_rejects_invalid_table_name(): void
    {
        $lock = new DistributedLock('database', ['db_table' => 'bad table;drop']);

        $this->assertFalse($lock->acquire('k', 60));
    }

    public function test_unknown_driver_falls_back_to_redis_behaviour(): void
    {
        $lock = new DistributedLock('mystery');

        $this->assertFalse($lock->acquire('k', 60));
        $this->assertFalse($lock->isAcquired('k'));
        $this->assertFalse($lock->release('k'));
    }
}

/**
 * 最小 App 替身：仅提供 runtime_path() 需要的 getRuntimePath()
 */
class DistributedLockAppStub
{
    public const RUNTIME = '/tmp/wise-runtime/';

    public function getRuntimePath(): string
    {
        return self::RUNTIME;
    }
}
