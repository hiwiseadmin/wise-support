<?php
declare(strict_types=1);

namespace wise\support\config;

/**
 * 配置检查结果值对象
 */
class CheckResult
{
    /**
     * @param bool   $passed  是否通过检查
     * @param string $message 检查结果描述
     * @param string|null $fixHint 修复建议（如 "请在 .env 中设置 JWT_SECRET"）
     */
    public function __construct(
        public readonly bool $passed,
        public readonly string $message,
        public readonly ?string $fixHint = null,
    ) {
    }

    public static function ok(string $message = ''): self
    {
        return new self(true, $message);
    }

    public static function fail(string $message, ?string $fixHint = null): self
    {
        return new self(false, $message, $fixHint);
    }
}
