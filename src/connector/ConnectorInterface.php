<?php
declare(strict_types=1);

namespace wise\support\connector;

/**
 * 外部系统连接器接口
 *
 * 统一 OAuth、Webhook、API 调用、消息推送、支付等
 * 所有"外部系统交互"的抽象契约。
 *
 * 实现类通过 ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, $id, $class) 注册。
 *
 * 使用示例：
 * <pre>
 * // 注册连接器
 * ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'wechat_pay', WechatPayConnector::class);
 *
 * // 使用连接器
 * $manager = app(ConnectorManager::class);
 * $connector = $manager->driver('wechat_pay');
 * $result = $connector->execute('charge', ['amount' => 100, 'currency' => 'CNY']);
 * </pre>
 *
 * @package wise\support
 */
interface ConnectorInterface
{
    /**
     * 连接器唯一标识
     *
     * @return string 如 'wechat_pay', 'aliyun_sms', 'github_oauth'
     */
    public function getId(): string;

    /**
     * 连接器显示名称
     *
     * @return string 如 '微信支付', '阿里云短信'
     */
    public function getName(): string;

    /**
     * 连接器类型
     */
    public function getType(): ConnectorType;

    /**
     * 连接器描述
     */
    public function getDescription(): string;

    /**
     * 获取支持的操作列表
     *
     * @return array<string, array> 如 ['charge' => ['description' => '扣款', 'params' => ['amount', 'currency']]]
     */
    public function getActions(): array;

    /**
     * 执行一个操作
     *
     * @param string $action  操作名称，如 'charge', 'send'
     * @param array  $input   输入参数
     * @param array  $context 上下文（用户、请求等）
     * @return ConnectorResult
     * @throws \RuntimeException 连接器异常时抛出
     */
    public function execute(string $action, array $input, array $context = []): ConnectorResult;

    /**
     * 健康检查
     *
     * @return bool true 表示连接器可用
     */
    public function healthCheck(): bool;

    /**
     * 获取配置 Schema
     *
     * 用于管理后台动态渲染配置表单。
     *
     * @return array 如 [['key' => 'api_key', 'type' => 'text', 'label' => 'API 密钥', 'required' => true]]
     */
    public function getConfigSchema(): array;

    /**
     * 设置连接器配置
     *
     * @param array $config 键值对配置
     */
    public function setConfig(array $config): void;

    /**
     * 获取当前配置
     */
    public function getConfig(): array;
}
