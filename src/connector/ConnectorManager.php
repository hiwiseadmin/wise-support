<?php
declare(strict_types=1);

namespace wise\support\connector;

use Psr\Log\LoggerInterface;
use wise\support\ExtPoint;
use wise\support\ExtPoints;

/**
 * 连接器管理器
 *
 * 统一管理所有外部系统连接器，支持按名称精确查找和按类型查找活跃连接器。
 *
 * 使用示例：
 * <pre>
 * $manager = app(ConnectorManager::class);
 *
 * // 按名称获取
 * $wechat = $manager->driver('wechat_pay');
 * $result = $wechat->execute('charge', ['amount' => 100]);
 *
 * // 获取活跃的支付连接器
 * $payment = $manager->activeFor(ConnectorType::PAYMENT);
 *
 * // 检查健康状态
 * $health = $manager->healthCheck('wechat_pay');
 * </pre>
 *
 * @package wise\support
 */
class ConnectorManager
{
    /**
     * 已解析的连接器实例缓存
     *
     * @var array<string, ConnectorInterface>
     */
    protected array $instances = [];

    /**
     * @var LoggerInterface|null
     */
    protected ?LoggerInterface $logger = null;

    /**
     * 设置日志记录器
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * 记录日志（未配置 logger 时降级到 error_log）
     */
    protected function log(string $level, string $message): void
    {
        if ($this->logger) {
            $this->logger->{$level}($message);
        } else {
            error_log("[{$level}] {$message}");
        }
    }

    /**
     * 按名称获取连接器
     *
     * @param string $name 连接器标识
     * @return ConnectorInterface
     * @throws \RuntimeException 连接器未注册时抛出
     */
    public function driver(string $name): ConnectorInterface
    {
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        $class = ExtPoint::get(ExtPoints::EXTERNAL_CONNECTOR, $name);

        if ($class === null) {
            throw new \RuntimeException("连接器 [{$name}] 未注册");
        }

        $connector = $this->resolve($class, $name);
        $this->instances[$name] = $connector;

        return $connector;
    }

    /**
     * 获取指定类型的活跃连接器
     *
     * 优先返回标记为 active 的连接器，同类型多个时取 priority 值最小的（优先级最高）。
     *
     * @return ConnectorInterface|null 无匹配时返回 null
     */
    public function activeFor(ConnectorType $type): ?ConnectorInterface
    {
        $connectors = ExtPoint::all(ExtPoints::EXTERNAL_CONNECTOR);
        $candidates = [];

        foreach ($connectors as $name => $item) {
            if (!($item['active'] ?? true)) {
                continue;
            }

            try {
                $connector = $this->driver($name);
            } catch (\Throwable $e) {
                // 实例化失败应完整记录，避免静默隐藏故障连接器导致健康检查误判
                $this->log('warning', sprintf(
                    "ConnectorManager: 连接器 [%s] 实例化失败: %s (trace: %s:%d)",
                    $name,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));
                continue;
            }

            if ($connector->getType() === $type) {
                $candidates[] = [
                    'name'      => $name,
                    'connector' => $connector,
                    'priority'  => $item['priority'] ?? 100,
                ];
            }
        }

        if (empty($candidates)) {
            return null;
        }

        // 按 priority 升序（值越小优先级越高），取第一个
        usort($candidates, static fn(array $a, array $b): int =>
            $a['priority'] <=> $b['priority']
        );

        return $candidates[0]['connector'];
    }

    /**
     * 获取所有连接器（按类型分组）
     *
     * @return array<string, array<string, ConnectorInterface>>
     */
    public function all(): array
    {
        $connectors = ExtPoint::all(ExtPoints::EXTERNAL_CONNECTOR);
        $grouped = [];

        foreach ($connectors as $name => $item) {
            try {
                $connector = $this->driver($name);
                $type = $connector->getType()->value;
                $grouped[$type][$name] = $connector;
            } catch (\Throwable $e) {
                $this->log('warning', sprintf(
                    "ConnectorManager: 连接器 [%s] 实例化失败: %s (trace: %s:%d)",
                    $name,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));
                continue;
            }
        }

        return $grouped;
    }

    /**
     * 健康检查指定连接器
     */
    public function healthCheck(string $name): bool
    {
        try {
            return $this->driver($name)->healthCheck();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 健康检查所有连接器
     *
     * @return array<string, bool>
     */
    public function healthCheckAll(): array
    {
        $connectors = ExtPoint::all(ExtPoints::EXTERNAL_CONNECTOR);
        $results = [];

        foreach ($connectors as $name => $item) {
            $results[$name] = $this->healthCheck($name);
        }

        return $results;
    }

    /**
     * 获取连接器的配置 Schema（用于管理后台）
     */
    public function getConfigSchema(string $name): array
    {
        return $this->driver($name)->getConfigSchema();
    }

    /**
     * 设置连接器配置
     */
    public function setConfig(string $name, array $config): void
    {
        $this->driver($name)->setConfig($config);
    }

    /**
     * 清除实例缓存
     */
    public function clearCache(): void
    {
        $this->instances = [];
    }

    /**
     * 解析连接器实例
     */
    protected function resolve(string|callable $class, string $name): ConnectorInterface
    {
        if (is_callable($class)) {
            $instance = $class();
        } else {
            if (!class_exists($class)) {
                throw new \RuntimeException("连接器类 [{$class}] 不存在");
            }
            // 通过容器解析，支持构造函数依赖注入
            $instance = app($class);
        }

        if (!$instance instanceof ConnectorInterface) {
            throw new \RuntimeException("连接器 [{$name}] 的类 [{$class}] 必须实现 ConnectorInterface");
        }

        return $instance;
    }
}
