<?php
declare(strict_types=1);

namespace wise\support\bus;

/**
 * 服务总线消息
 *
 * 封装跨插件通信的消息载荷，支持请求/响应和事件两种模式。
 *
 * 使用示例：
 * <pre>
 * // 请求消息
 * $request = Message::request('payment.charge', ['amount' => 100]);
 *
 * // 事件消息
 * $event = Message::event('order.created', ['order_id' => 42]);
 *
 * // 回复消息
 * $reply = Message::reply($request, ['transaction_id' => 'tx_123']);
 * </pre>
 *
 * @package wise\support
 */
class Message
{
    /**
     * 请求消息类型
     */
    public const TYPE_REQUEST = 'request';

    /**
     * 事件消息类型
     */
    public const TYPE_EVENT = 'event';

    /**
     * 回复消息类型
     */
    public const TYPE_REPLY = 'reply';

    /**
     * 消息唯一标识
     */
    protected string $id;

    /**
     * 消息类型：request | event | reply
     */
    protected string $type;

    /**
     * 操作名称（如 'payment.charge', 'order.created'）
     */
    protected string $action;

    /**
     * 消息载荷
     */
    protected array $payload;

    /**
     * 上下文元数据（来源插件、追踪ID等）
     */
    protected array $context;

    /**
     * 回复关联的消息ID（仅 TYPE_REPLY）
     */
    protected ?string $correlationId = null;

    /**
     * 创建时间戳
     */
    protected int $timestamp = 0;

    /**
     * 创建请求消息
     */
    public static function request(string $action, array $payload = [], array $context = []): self
    {
        $message = new self();
        $message->type = self::TYPE_REQUEST;
        $message->action = $action;
        $message->payload = $payload;
        $message->context = $context;
        $message->id = uniqid('msg_req_', true);
        $message->timestamp = time();
        return $message;
    }

    /**
     * 创建事件消息
     */
    public static function event(string $action, array $payload = [], array $context = []): self
    {
        $message = new self();
        $message->type = self::TYPE_EVENT;
        $message->action = $action;
        $message->payload = $payload;
        $message->context = $context;
        $message->id = uniqid('msg_evt_', true);
        $message->timestamp = time();
        return $message;
    }

    /**
     * 创建回复消息
     */
    public static function reply(self $original, array $payload = [], array $context = []): self
    {
        $message = new self();
        $message->type = self::TYPE_REPLY;
        $message->action = ($original->getAction() !== '' ? $original->getAction() : 'unknown') . '.reply';
        $message->payload = $payload;
        $message->context = array_merge($original->getContext(), $context);
        $message->correlationId = $original->getId();
        $message->id = uniqid('msg_rpl_', true);
        $message->timestamp = time();
        return $message;
    }

    /**
     * 创建错误回复
     */
    public static function error(self $original, string $error, int $code = 500): self
    {
        return self::reply($original, [
            '__error' => $error,
            '__code'  => $code,
        ]);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * 获取载荷中的指定键值
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    /**
     * 是否为请求消息
     */
    public function isRequest(): bool
    {
        return $this->type === self::TYPE_REQUEST;
    }

    /**
     * 是否为事件消息
     */
    public function isEvent(): bool
    {
        return $this->type === self::TYPE_EVENT;
    }

    /**
     * 是否为回复消息
     */
    public function isReply(): bool
    {
        return $this->type === self::TYPE_REPLY;
    }

    /**
     * 回复是否为错误
     */
    public function isError(): bool
    {
        return isset($this->payload['__error']);
    }

    /**
     * 获取错误消息
     */
    public function getError(): ?string
    {
        return $this->payload['__error'] ?? null;
    }

    /**
     * 获取错误码
     */
    public function getErrorCode(): int
    {
        return (int) ($this->payload['__code'] ?? 0);
    }

    /**
     * 获取回复关联的消息ID（仅 TYPE_REPLY / TYPE_ERROR）
     */
    public function getReplyTo(): ?string
    {
        return $this->correlationId;
    }

    /**
     * 序列化为数组（用于队列传输）
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'type'          => $this->type,
            'action'        => $this->action,
            'payload'       => $this->payload,
            'context'       => $this->context,
            'correlation_id' => $this->correlationId,
            'timestamp'     => $this->timestamp,
        ];
    }

    private const VALID_TYPES = [self::TYPE_REQUEST, self::TYPE_EVENT, self::TYPE_REPLY];

    /**
     * 从数组反序列化
     */
    public static function fromArray(array $data): self
    {
        $message = new self();
        $message->id            = $data['id'] ?? uniqid('msg_', true);
        $message->type          = in_array($data['type'] ?? '', self::VALID_TYPES, true)
            ? $data['type']
            : self::TYPE_EVENT;
        $message->action        = $data['action'] ?? '';
        $message->payload       = $data['payload'] ?? [];
        $message->context       = $data['context'] ?? [];
        $message->correlationId = $data['correlation_id'] ?? null;
        $message->timestamp     = $data['timestamp'] ?? time();
        return $message;
    }
}
