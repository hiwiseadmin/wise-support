<?php
declare(strict_types=1);

namespace wise\support\connector;

/**
 * 连接器类型枚举
 *
 * @package wise\support
 */
enum ConnectorType: string
{
    /** OAuth 授权登录 */
    case OAUTH = 'oauth';

    /** Webhook 回调 */
    case WEBHOOK = 'webhook';

    /** REST API 调用 */
    case API = 'api';

    /** 消息推送（邮件/短信/IM） */
    case MESSAGE = 'message';

    /** 支付网关 */
    case PAYMENT = 'payment';

    /** 云存储 */
    case STORAGE = 'storage';

    /** AI 服务 */
    case AI = 'ai';

    /** IoT 设备 */
    case IOT = 'iot';

    /**
     * 获取中文标签
     */
    public function label(): string
    {
        return match ($this) {
            self::OAUTH    => 'OAuth 登录',
            self::WEBHOOK  => 'Webhook 回调',
            self::API      => 'API 调用',
            self::MESSAGE  => '消息推送',
            self::PAYMENT  => '支付网关',
            self::STORAGE  => '云存储',
            self::AI       => 'AI 服务',
            self::IOT      => 'IoT 设备',
        };
    }
}
