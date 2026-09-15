<?php
declare(strict_types=1);

namespace wise\support\connector;

/**
 * 连接器执行结果
 *
 * 统一封装外部系统调用的返回结果，区分成功/失败状态。
 *
 * @package wise\support
 */
class ConnectorResult
{
    /**
     * 是否成功
     */
    protected bool $success;

    /**
     * 返回数据
     */
    protected mixed $data;

    /**
     * 错误信息
     */
    protected ?string $error;

    /**
     * 错误码
     */
    protected ?string $errorCode;

    /**
     * 原始响应（用于调试）
     */
    protected mixed $raw;

    /**
     * 元数据（请求耗时、请求ID等）
     */
    protected array $meta;

    public function __construct(
        bool $success,
        mixed $data = null,
        ?string $error = null,
        ?string $errorCode = null,
        mixed $raw = null,
        array $meta = []
    ) {
        $this->success   = $success;
        $this->data      = $data;
        $this->error     = $error;
        $this->errorCode = $errorCode;
        $this->raw       = $raw;
        $this->meta      = $meta;
    }

    /**
     * 创建成功结果
     */
    public static function ok(mixed $data = null, array $meta = []): self
    {
        return new self(true, $data, null, null, null, $meta);
    }

    /**
     * 创建失败结果
     */
    public static function fail(string $error, ?string $errorCode = null, mixed $raw = null): self
    {
        return new self(false, null, $error, $errorCode, $raw);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isFailure(): bool
    {
        return !$this->success;
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getRaw(): mixed
    {
        return $this->raw;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * 转为数组
     */
    public function toArray(): array
    {
        return [
            'success'    => $this->success,
            'data'       => $this->data,
            'error'      => $this->error,
            'error_code' => $this->errorCode,
            'meta'       => $this->meta,
        ];
    }
}
