<?php
declare(strict_types=1);

namespace wise\support\connector;

/**
 * 短信连接器契约（领域子接口）
 *
 * 描述「短信服务商」级能力（阿里云短信 / 腾讯云短信 / 云片等），
 * 参数为原始短信参数（手机号 + 模板 + 模板参数），不涉及通知业务对象。
 *
 * 分层说明（注意与 wise-notification 的 ChannelDriver 区分）：
 *   - 本接口 = 连接器层，面向「短信服务商」，方法 send(string $phone, string $templateCode, array $params)；
 *   - wise-notification 的 ChannelDriver::send(Notifiable, Message) = 通道层，面向业务消息对象。
 *   通道层应消费连接器层（SmsChannel 内部经 ConnectorManager 取活跃 SmsConnector），
 *   两者方法名相同但签名不同，故不可由同一个类同时实现。
 *
 * 注册方式：
 * <pre>
 * ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'aliyun_sms', AliyunSmsConnector::class);
 * // 业务侧：app(ConnectorManager::class)->sms()->send('13800000000', 'SMS_123', ['code' => '1234']);
 * </pre>
 *
 * @package wise\support\connector
 */
interface SmsConnectorInterface extends ConnectorInterface
{
    /**
     * 发送单条短信
     *
     * @param string $phone        接收手机号（实现方按需规范化国际区号）
     * @param string $templateCode 短信模板 Code / ID
     * @param array  $params       模板参数（键值对）
     * @param array  $options      可选参数（签名、发送时间、扩展码等）
     * @return ConnectorResult 成功时 data 建议至少包含 {'success' => bool, 'response' => string}
     * @throws \RuntimeException 发送失败时抛出
     */
    public function send(string $phone, string $templateCode, array $params = [], array $options = []): ConnectorResult;

    /**
     * 批量发送短信
     *
     * @param array  $phones       接收手机号数组
     * @param string $templateCode 短信模板 Code / ID
     * @param array  $params       模板参数（键值对）
     * @param array  $options      可选参数
     * @return ConnectorResult 成功时 data 建议包含 {'success' => int, 'failed' => int, 'failed_list' => array}
     * @throws \RuntimeException 发送失败时抛出
     */
    public function sendBatch(array $phones, string $templateCode, array $params = [], array $options = []): ConnectorResult;
}
