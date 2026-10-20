<?php
declare(strict_types=1);

use wise\support\connector\ConnectorInterface;
use wise\support\connector\ConnectorResult;
use wise\support\connector\ConnectorType;
use wise\support\bus\Message;

class TestPaymentHandler
{
    public function handle(Message $message): Message
    {
        return Message::reply($message, [
            'handler' => 'wechat',
            'amount'  => $message->get('amount'),
            'status'  => 'success',
        ]);
    }
}

class TestNotificationHandler
{
    public function handle(Message $message): Message
    {
        return Message::reply($message, [
            'handler' => 'notification',
            'order_id' => $message->get('order_id'),
        ]);
    }
}

class TestCacheHandler
{
    public function handle(Message $message): Message
    {
        return Message::reply($message, ['handler' => 'cache']);
    }
}

class TestPaymentConnector implements ConnectorInterface
{
    protected array $config = [];

    public function getId(): string { return 'wechat_pay'; }
    public function getName(): string { return '微信支付'; }
    public function getType(): ConnectorType { return ConnectorType::PAYMENT; }
    public function getDescription(): string { return '微信支付商户接口'; }

    public function getActions(): array
    {
        return [
            'charge' => ['description' => '扣款', 'params' => ['amount', 'currency']],
            'refund' => ['description' => '退款', 'params' => ['transaction_id', 'amount']],
        ];
    }

    public function execute(string $action, array $input, array $context = []): ConnectorResult
    {
        if (($input['amount'] ?? 0) <= 0) {
            return ConnectorResult::fail('无效金额', 'INVALID_AMOUNT');
        }
        return ConnectorResult::ok('charged', ['elapsed_ms' => 150]);
    }

    public function healthCheck(): bool { return true; }
    public function getConfigSchema(): array { return []; }
    public function setConfig(array $config): void { $this->config = $config; }
    public function getConfig(): array { return $this->config; }
}

class TestSmsConnector implements ConnectorInterface
{
    protected array $config = [];

    public function getId(): string { return 'aliyun_sms'; }
    public function getName(): string { return '阿里云短信'; }
    public function getType(): ConnectorType { return ConnectorType::MESSAGE; }
    public function getDescription(): string { return '阿里云短信服务'; }

    public function getActions(): array
    {
        return ['send' => ['description' => '发送短信', 'params' => ['phone', 'template', 'params']]];
    }

    public function execute(string $action, array $input, array $context = []): ConnectorResult
    {
        return ConnectorResult::ok('sent', ['message_id' => 'sms_456']);
    }

    public function healthCheck(): bool { return true; }
    public function getConfigSchema(): array { return []; }
    public function setConfig(array $config): void { $this->config = $config; }
    public function getConfig(): array { return $this->config; }
}

class TestOAuthConnector implements ConnectorInterface
{
    protected array $config = [];

    public function getId(): string { return 'github_oauth'; }
    public function getName(): string { return 'GitHub OAuth'; }
    public function getType(): ConnectorType { return ConnectorType::OAUTH; }
    public function getDescription(): string { return 'GitHub OAuth 登录'; }

    public function getActions(): array
    {
        return [
            'authorize' => ['description' => '获取授权 URL'],
            'callback'  => ['description' => '处理回调'],
        ];
    }

    public function execute(string $action, array $input, array $context = []): ConnectorResult
    {
        return ConnectorResult::ok(['redirect_url' => 'https://github.com/login/oauth/authorize?...']);
    }

    public function healthCheck(): bool { return true; }
    public function getConfigSchema(): array { return []; }
    public function setConfig(array $config): void { $this->config = $config; }
    public function getConfig(): array { return $this->config; }
}
