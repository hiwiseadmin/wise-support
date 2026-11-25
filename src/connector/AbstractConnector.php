<?php
declare(strict_types=1);

namespace wise\support\connector;

/**
 * 连接器抽象基类
 *
 * 为 {@see ConnectorInterface} 提供通用默认实现，子类只需实现
 * {@see ConnectorType} 相关的 {@see getType()} 与自身业务方法即可。
 *
 * 设计意图（对应「统一契约管共性、领域子接口管个性」的分层）：
 *   - 共性（发现 / 健康 / 配置 / 能力自描述 / 动态分发兜底）在此统一兜底；
 *   - 个性（如支付的下单/退款、短信的 send）由领域子接口声明、子类实现。
 *
 * 使用示例：
 * <pre>
 * class WechatPayConnector extends AbstractConnector implements PaymentConnectorInterface
 * {
 *     public function getId(): string { return 'wechat_pay'; }
 *     public function getType(): ConnectorType { return ConnectorType::PAYMENT; }
 *     public function getActions(): array { return ['charge' => ['description' => '下单']]; }
 *     public function charge(array $order): array { ... }
 *     // getActions/healthCheck/getConfigSchema/execute 等由基类兜底
 * }
 * </pre>
 *
 * @package wise\support\connector
 */
abstract class AbstractConnector implements ConnectorInterface
{
    /**
     * 连接器配置（由 setConfig 注入）
     *
     * @var array<string, mixed>
     */
    protected array $config = [];

    /**
     * 连接器唯一标识（子类可覆盖，默认取类名）
     */
    public function getId(): string
    {
        return static::class;
    }

    /**
     * 连接器显示名称（默认与标识一致，子类可覆盖为中文名）
     */
    public function getName(): string
    {
        return $this->getId();
    }

    /**
     * 连接器描述（默认空）
     */
    public function getDescription(): string
    {
        return '';
    }

    /**
     * 支持的操作列表（默认空，子类按需声明，供管理后台 / AI 自发现）
     *
     * @return array<string, array>
     */
    public function getActions(): array
    {
        return [];
    }

    /**
     * 动态分发兜底：默认视为不支持该操作
     *
     * 领域子接口通常覆盖此方法，用 match 把 action 路由到对应的强类型方法，
     * 从而让「强类型业务调用」与「弱类型动态调用」共享同一份实现。
     */
    public function execute(string $action, array $input, array $context = []): ConnectorResult
    {
        return ConnectorResult::fail(
            sprintf('连接器 [%s] 不支持操作 [%s]', $this->getId(), $action),
            'UNSUPPORTED_ACTION'
        );
    }

    /**
     * 健康检查（默认视为可用，需要探测外部系统时子类覆盖）
     */
    public function healthCheck(): bool
    {
        return true;
    }

    /**
     * 配置 Schema（默认空，供管理后台动态渲染配置表单）
     *
     * @return array<int, array>
     */
    public function getConfigSchema(): array
    {
        return [];
    }

    /**
     * 注入连接器配置
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * 获取当前配置
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}
