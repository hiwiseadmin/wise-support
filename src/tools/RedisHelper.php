<?php
declare(strict_types=1);

namespace wise\support\tools;

use wise\support\context\ContextualLogger;
use Predis\Client as PredisClient;
use Redis;
use RedisException;
use Exception;

/**
 * Redis 操作辅助类（双驱动版）
 *
 * 自动检测：优先使用 phpredis 扩展，若未安装则降级使用 Predis 库。
 * 支持应用级数据隔离与全局数据操作。
 *
 * 每次 getInstance() 创建新实例，不使用静态单例，
 * 保证请求级连接隔离。
 */
class RedisHelper
{
    /**
     * @var Redis|PredisClient 当前使用的客户端实例
     */
    private $client;

    /**
     * @var string 当前使用的驱动类型：'phpredis' 或 'predis'
     */
    private $driver;

    /**
     * @var string 应用隔离前缀
     */
    private $prefix = '';

    /**
     * @var bool 是否启用前缀
     */
    private $usePrefix = true;

    /**
     * 构造函数
     *
     * @param string $appName 应用标识
     * @param array  $config  自定义配置数组
     * @throws Exception
     */
    private function __construct(string $appName = '', array $config = [])
    {
        if (empty($config)) {
            throw new Exception('Redis 配置不能为空，请通过 RedisHelper::getInstance($appName, $config) 或构造函数显式传入');
        }

        // 检测并初始化驱动
        $this->initDriver($config);

        // 设置应用前缀
        if (!empty($appName)) {
            $this->prefix = "app:{$appName}:";
        } else {
            $this->prefix = $config['prefix'] ?? '';
        }
    }

    /**
     * 初始化 Redis 驱动
     *
     * @param array $config
     * @throws Exception
     */
    private function initDriver(array $config): void
    {
        // 检查是否明确指定了驱动类型（可在配置中添加 'client' 字段）
        $preferClient = $config['client'] ?? 'phpredis';

        // 优先使用 phpredis
        if ($preferClient === 'phpredis' && extension_loaded('redis')) {
            $this->driver = 'phpredis';
            $this->initPhpRedis($config);
            return;
        }

        // 降级使用 Predis（需确保已 composer require predis/predis）
        if (class_exists(PredisClient::class)) {
            $this->driver = 'predis';
            $this->initPredis($config);
            return;
        }

        // 均不可用
        throw new Exception('Neither phpredis extension nor Predis library is available.');
    }

    /**
     * 初始化 phpredis 客户端
     *
     * @param array $config
     * @throws RedisException
     */
    private function initPhpRedis(array $config): void
    {
        $this->client = new Redis();
        $connectMethod = $config['persistent'] ? 'pconnect' : 'connect';
        $connected = $this->client->{$connectMethod}(
            $config['host'],
            (int)$config['port'],
            (float)($config['timeout'] ?? 3.0)
        );

        if (!$connected) {
            throw new RedisException("phpredis: Failed to connect to {$config['host']}:{$config['port']}");
        }

        if (!empty($config['password'])) {
            if (!$this->client->auth($config['password'])) {
                throw new RedisException('phpredis: Authentication failed');
            }
        }

        if (isset($config['select']) && $config['select'] > 0) {
            $this->client->select((int)$config['select']);
        }

        // 设置序列化器（phpredis 支持，Predis 需另外处理）
        $this->client->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
    }

    /**
     * 初始化 Predis 客户端
     *
     * @param array $config
     */
    private function initPredis(array $config): void
    {
        $parameters = [
            'scheme' => 'tcp',
            'host'   => $config['host'],
            'port'   => (int)$config['port'],
        ];

        if (!empty($config['password'])) {
            $parameters['password'] = $config['password'];
        }

        $options = [];
        if (isset($config['select']) && $config['select'] > 0) {
            $options['parameters']['database'] = (int)$config['select'];
        }

        // 设置超时
        if (isset($config['timeout'])) {
            $parameters['timeout'] = (float)$config['timeout'];
        }

        // 是否持久连接（Predis 默认短连接，可配置 persistent=true）
        if (!empty($config['persistent'])) {
            $options['parameters']['persistent'] = true;
        }

        $this->client = new PredisClient($parameters, $options);

        // Predis 序列化：使用配置文件中的序列化设置，或默认使用 PHP 序列化
        // 注意：Predis 不自动序列化，需要在 set/get 时手动处理，这里我们沿用之前的设计，
        // 之前 phpredis 使用了 SERIALIZER_PHP，所以为了兼容，Predis 也需手动序列化。
        // 为了统一，我们在封装方法中统一处理序列化，而不是依赖驱动自身。
    }

    /**
     * 获取 RedisHelper 实例
     *
     * 每次调用创建新实例（FPM + Swoole 均可安全使用）。
     * 如需应用级 Key 前缀隔离，传入 $appName 参数。
     * 连接配置需由调用方显式传入（不再从框架配置文件读取）。
     *
     * @param string $appName 应用标识
     * @param array  $config  Redis 连接配置（host/port/password/select/persistent/client/prefix 等）
     * @return self
     */
    public static function getInstance(string $appName = '', array $config = []): self
    {
        return new self($appName, $config);
    }

    private function __clone() {}

    /**
     * 从连接池获取 Redis 原生连接
     *
     * 每次调用创建新连接实例。
     *
     * @return Redis|PredisClient
     * @throws Exception
     */
    public static function borrowFromPool(array $config = [])
    {
        return (new self('', $config))->raw();
    }

    /**
     * 归还连接到池
     *
     * 当前为无操作（FPM 模型下连接随请求生命周期自动释放）。
     *
     * @param mixed $connection Redis|PredisClient
     */
    public static function returnToPool(mixed $connection): void
    {
        // FPM 下连接随进程结束自动释放，无需手动归还
    }

    /**
     * 获取当前使用的驱动名称
     *
     * @return string
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * 临时禁用前缀
     *
     * @return $this
     */
    public function withoutPrefix(): self
    {
        $this->usePrefix = false;
        return $this;
    }

    /**
     * 启用前缀
     *
     * @return $this
     */
    public function withPrefix(): self
    {
        $this->usePrefix = true;
        return $this;
    }

    /**
     * 为 Key 添加前缀
     *
     * @param string|array $key
     * @return string|array
     */
    private function prefixKey($key)
    {
        if (!$this->usePrefix || empty($this->prefix)) {
            return $key;
        }

        if (is_array($key)) {
            return array_map(function ($k) {
                return $this->prefix . $k;
            }, $key);
        }
        return $this->prefix . $key;
    }

    /**
     * 获取原生客户端对象（phpredis 或 Predis）
     *
     * @return Redis|PredisClient
     */
    public function raw()
    {
        return $this->client;
    }

    // ======================= 序列化处理（兼容双驱动） =======================

    /**
     * 编码数据（模拟 phpredis 的 SERIALIZER_PHP）
     *
     * @param mixed $value
     * @return string
     */
    private function encode($value): string
    {
        // 如果已经是字符串，且驱动为 phpredis（已设置自动序列化），则无需额外处理
        if ($this->driver === 'phpredis') {
            // phpredis 会自动序列化，直接返回原值
            return $value;
        }
        // Predis 需要手动序列化
        return serialize($value);
    }

    /**
     * 解码数据
     *
     * @param mixed $value
     * @return mixed
     */
    private function decode($value)
    {
        if ($this->driver === 'phpredis') {
            return $value;
        }
        // Predis 返回的是字符串，需要反序列化
        if (!is_string($value)) {
            return $value;
        }
        // 尝试反序列化，失败则返回原字符串
        $result = @unserialize($value);
        return $result === false && $value !== 'b:0;' ? $value : $result;
    }

    // ======================= 通用方法封装（兼容双驱动） =======================

    public function set(string $key, $value, ?int $ttl = null): bool
    {
        try {
            $key = $this->prefixKey($key);
            $encoded = $this->encode($value);
            if ($ttl !== null && $ttl > 0) {
                // phpredis: setex, Predis: setex 同样支持
                return (bool)$this->client->setex($key, $ttl, $encoded);
            }
            return (bool)$this->client->set($key, $encoded);
        } catch (Exception $e) {
            $this->logError(__FUNCTION__, $e, compact('key'));
            return false;
        }
    }

    public function get(string $key)
    {
        try {
            $key = $this->prefixKey($key);
            $value = $this->client->get($key);
            return $this->decode($value);
        } catch (Exception $e) {
            $this->logError(__FUNCTION__, $e, compact('key'));
            return null;
        }
    }

    public function del($keys): int
    {
        try {
            $keys = $this->prefixKey($keys);
            // phpredis 和 Predis 都支持 del 多个 key
            return $this->client->del(...(array)$keys);
        } catch (Exception $e) {
            $this->logError(__FUNCTION__, $e, compact('keys'));
            return 0;
        }
    }

    public function exists(string $key): bool
    {
        try {
            $key = $this->prefixKey($key);
            return (bool)$this->client->exists($key);
        } catch (Exception $e) {
            $this->logError(__FUNCTION__, $e, compact('key'));
            return false;
        }
    }

    public function expire(string $key, int $ttl): bool
    {
        try {
            $key = $this->prefixKey($key);
            return (bool)$this->client->expire($key, $ttl);
        } catch (Exception $e) {
            $this->logError(__FUNCTION__, $e, compact('key', 'ttl'));
            return false;
        }
    }

    public function ttl(string $key): int
    {
        try {
            $key = $this->prefixKey($key);
            return $this->client->ttl($key);
        } catch (Exception $e) {
            $this->logError(__FUNCTION__, $e, compact('key'));
            return -2;
        }
    }

    // ---------- 哈希表 ----------
    public function hSet(string $key, string $field, $value)
    {
        try {
            $key = $this->prefixKey($key);
            $encoded = $this->encode($value);
            return $this->client->hSet($key, $field, $encoded);
        } catch (Exception $e) {
            $this->logError(__FUNCTION__, $e, compact('key', 'field'));
            return false;
        }
    }

    public function hGet(string $key, string $field)
    {
        try {
            $key = $this->prefixKey($key);
            $value = $this->client->hGet($key, $field);
            return $this->decode($value);
        } catch (Exception $e) {
            $this->logError(__FUNCTION__, $e, compact('key', 'field'));
            return null;
        }
    }

    public function hGetAll(string $key): array
    {
        try {
            $key = $this->prefixKey($key);
            $data = $this->client->hGetAll($key);
            if ($this->driver === 'predis' && is_array($data)) {
                // Predis 返回的数组值都是字符串，需要统一反序列化
                foreach ($data as $k => $v) {
                    $data[$k] = $this->decode($v);
                }
            }
            return $data ?: [];
        } catch (Exception $e) {
            $this->logError(__FUNCTION__, $e, compact('key'));
            return [];
        }
    }

    public function hMSet(string $key, array $data): bool
    {
        try {
            $key = $this->prefixKey($key);
            $encoded = [];
            foreach ($data as $k => $v) {
                $encoded[$k] = $this->encode($v);
            }
            return (bool)$this->client->hMSet($key, $encoded);
        } catch (Exception $e) {
            $this->logError(__FUNCTION__, $e, compact('key'));
            return false;
        }
    }

    // ---------- 列表 ----------
    public function lPush(string $key, ...$values): int
    {
        try {
            $key = $this->prefixKey($key);
            $encoded = array_map([$this, 'encode'], $values);
            return $this->client->lPush($key, ...$encoded);
        } catch (Exception $e) {
            $this->logError(__FUNCTION__, $e, compact('key'));
            return 0;
        }
    }

    public function rPop(string $key)
    {
        try {
            $key = $this->prefixKey($key);
            $value = $this->client->rPop($key);
            return $this->decode($value);
        } catch (Exception $e) {
            $this->logError(__FUNCTION__, $e, compact('key'));
            return null;
        }
    }

    public function lRange(string $key, int $start, int $end): array
    {
        try {
            $key = $this->prefixKey($key);
            $data = $this->client->lRange($key, $start, $end);
            if ($this->driver === 'predis' && is_array($data)) {
                foreach ($data as $k => $v) {
                    $data[$k] = $this->decode($v);
                }
            }
            return $data ?: [];
        } catch (Exception $e) {
            $this->logError(__FUNCTION__, $e, compact('key'));
            return [];
        }
    }

    // ---------- 有序集合 ----------
    public function zAdd(string $key, float $score, $value): int
    {
        try {
            $key = $this->prefixKey($key);
            $encoded = $this->encode($value);
            return $this->client->zAdd($key, $score, $encoded);
        } catch (Exception $e) {
            $this->logError(__FUNCTION__, $e, compact('key'));
            return 0;
        }
    }

    public function zRange(string $key, int $start, int $end, bool $withScores = false): array
    {
        try {
            $key = $this->prefixKey($key);
            $data = $this->client->zRange($key, $start, $end, ['withscores' => $withScores]);
            if ($this->driver === 'predis' && is_array($data)) {
                if ($withScores) {
                    // Predis 返回 [member => score]，member 是序列化后的字符串，需解码
                    $decoded = [];
                    foreach ($data as $member => $score) {
                        $decoded[$this->decode($member)] = $score;
                    }
                    $data = $decoded;
                } else {
                    $data = array_map([$this, 'decode'], $data);
                }
            }
            return $data ?: [];
        } catch (Exception $e) {
            $this->logError(__FUNCTION__, $e, compact('key'));
            return [];
        }
    }

    // ---------- 其他 ----------
    public function incr(string $key, int $by = 1)
    {
        try {
            $key = $this->prefixKey($key);
            return $by === 1 ? $this->client->incr($key) : $this->client->incrBy($key, $by);
        } catch (Exception $e) {
            $this->logError(__FUNCTION__, $e, compact('key', 'by'));
            return false;
        }
    }

    /**
     * 统一错误日志记录
     *
     * @param string $method
     * @param Exception $e
     * @param array $context
     */
    private function logError(string $method, Exception $e, array $context = []): void
    {
        $driverInfo = $this->driver ?? 'unknown';
        ContextualLogger::error("RedisHelper [{$driverInfo}]::{$method} Error: " . $e->getMessage(), $context);
    }
}