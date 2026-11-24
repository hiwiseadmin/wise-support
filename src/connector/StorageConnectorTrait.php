<?php
declare(strict_types=1);

namespace wise\support\connector;

/**
 * 存储连接器默认实现 Trait
 *
 * 为「已实现 {@see \wise\support\contracts\StorageDriverInterface} 的存储驱动」
 * 补齐 {@see ConnectorInterface} 的通用方法，使其可直接作为连接器接入
 * {@see ConnectorManager} / `ExtPoints::EXTERNAL_CONNECTOR`，而无需改动驱动本身的
 * 18 个存储方法。
 *
 * 使用方式：
 * <pre>
 * class Qiniu implements \wise\support\connector\StorageConnectorInterface
 * {
 *     use \wise\support\connector\StorageConnectorTrait;
 *     // 仅需继续实现 StorageDriverInterface 的存储方法
 * }
 * </pre>
 *
 * @package wise\support\connector
 */
trait StorageConnectorTrait
{
    /**
     * 以下抽象方法由使用本 trait 的类（同时实现 StorageDriverInterface）提供。
     * trait 无法声明「要求使用类实现某接口」，故显式声明以明确依赖，便于静态分析。
     */
    abstract public function getDriverName(): string;

    abstract public function upload(string $localPath, string $objectName): array;

    abstract public function delete(string $objectName): bool;

    abstract public function url(string $objectName, array $options = []): string;

    abstract public function exists(string $objectName): bool;

    abstract public function size(string $objectName): int;

    abstract public function getMetadata(string $objectName): array;

    /**
     * 连接器唯一标识：取驱动标识（如 local / qiniu / alioss）
     */
    public function getId(): string
    {
        return $this->getDriverName();
    }

    /**
     * 存储连接器类型恒为 STORAGE
     */
    public function getType(): ConnectorType
    {
        return ConnectorType::STORAGE;
    }

    /**
     * 显示名称默认取驱动标识
     */
    public function getName(): string
    {
        return $this->getDriverName();
    }

    public function getDescription(): string
    {
        return sprintf('对象存储连接器（%s）', $this->getDriverName());
    }

    /**
     * 能力自描述：列出支持的存储操作（供管理后台 / AI 发现）
     *
     * @return array<string, array>
     */
    public function getActions(): array
    {
        return [
            'upload'   => ['description' => '上传文件', 'params' => ['local_path', 'object_name']],
            'delete'   => ['description' => '删除文件', 'params' => ['object_name']],
            'url'      => ['description' => '获取访问 URL', 'params' => ['object_name', 'options']],
            'exists'   => ['description' => '检查文件是否存在', 'params' => ['object_name']],
            'size'     => ['description' => '获取文件大小', 'params' => ['object_name']],
            'metadata' => ['description' => '获取文件元数据', 'params' => ['object_name']],
        ];
    }

    /**
     * 动态分发：把 action 路由到强类型存储方法
     *
     * 业务代码应直接调用强类型方法；此入口服务于管理后台 / AI / 脚本等
     * 「不知道具体实现类型」的动态场景，两个入口共享同一份实现。
     */
    public function execute(string $action, array $input, array $context = []): ConnectorResult
    {
        try {
            $data = match ($action) {
                'upload'   => $this->upload(
                    (string) ($input['local_path'] ?? ''),
                    (string) ($input['object_name'] ?? '')
                ),
                'delete'   => $this->delete((string) ($input['object_name'] ?? '')),
                'url'      => $this->url(
                    (string) ($input['object_name'] ?? ''),
                    (array) ($input['options'] ?? [])
                ),
                'exists'   => $this->exists((string) ($input['object_name'] ?? '')),
                'size'     => $this->size((string) ($input['object_name'] ?? '')),
                'metadata' => $this->getMetadata((string) ($input['object_name'] ?? '')),
                default    => throw new \InvalidArgumentException(
                    sprintf('存储连接器不支持操作 [%s]', $action)
                ),
            };

            return ConnectorResult::ok($data);
        } catch (\Throwable $e) {
            return ConnectorResult::fail($e->getMessage(), 'STORAGE_ERROR');
        }
    }

    /**
     * 健康检查：默认视为可用（需要探测时可覆盖）
     */
    public function healthCheck(): bool
    {
        return true;
    }

    /**
     * 配置 Schema：默认空（存储驱动配置由 wise-attachment 配置文件提供）
     *
     * @return array<int, array>
     */
    public function getConfigSchema(): array
    {
        return [];
    }

    /**
     * 注入配置（存储驱动的配置由外部配置文件 / 构造函数提供，此处仅保存引用）
     */
    public function setConfig(array $config): void
    {
        $this->connectorConfig = $config;
    }

    public function getConfig(): array
    {
        return $this->connectorConfig ?? [];
    }

    /**
     * 连接器配置暂存（避免与驱动自身的 $config 属性冲突）
     *
     * @var array<string, mixed>
     */
    protected array $connectorConfig = [];
}
