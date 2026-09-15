<?php
declare(strict_types=1);

namespace wise\support\exception;

/**
 * WiseAdmin 框架统一异常基类
 *
 * 为全体 wise-* 包提供统一的异常格式：
 *   - 整数错误码（各包在自有 src/exception/ErrorCode.php 中定义）
 *   - HTTP 状态码（用于全局异常渲染）
 *   - 上下文数据（附加调试信息）
 *
 * 号段分配（在 wise-support 号段登记文档中维护）：
 *   每个 wise-* 包独立一个号段，各 10000 个，互不重叠；框架 + app 层合占一块。
 *   ------------------------------------------------------------------
 *     0~99        → 本类常量（绝对通用：SUCCESS=0, UNKNOWN=1）
 *   100~999       → GeneralErrorCode（框架级通用码：HTTP 语义/校验/基础设施）
 *  1000~9999      → 框架 + app 层业务码（app\* 共享）
 *  ------------------------------------------------------------------
 *  10000~19999   → wise-api
 *  20000~29999   → wise-wx
 *  30000~39999   → wise-auth
 *  40000~49999   → wise-oauth
 *  50000~59999   → wise-jwt
 *  60000~69999   → wise-login-security
 *  70000~79999   → wise-workflow
 *  80000~89999   → wise-agent
 *  90000~99999   → wise-notification
 * 100000~109999  → wise-webhook
 * 110000~119999  → wise-attachment
 * 120000~129999  → wise-image
 * 130000~139999  → wise-qrcode
 * 140000~149999  → wise-backup
 * 150000~159999  → wise-config
 * 160000~169999  → wise-crontab
 * 170000~179999  → wise-health-check
 * 180000~189999  → wise-operation-log
 * 190000~199999  → wise-form-schema
 * 200000~209999  → wise-demo-sandbox
 * 210000~219999  → wise-game-core
 * 220000~229999  → wise-game-energy
 * 230000~239999  → wise-game-ops
 * 240000~249999  → wise-game-social
 * 250000~259999  → wise-game-stage
 * 260000~269999  → 预留
 * 270000~279999  → 预留
 * 280000~289999  → wise-game-recipe
 *
 * 使用示例：
 * <pre>
 * // 各包定义自己的 ErrorCode 枚举（如 wise\wx\exception\ErrorCode）
 * throw WiseException::of(ErrorCode::PAY_SIGN_INVALID)
 *     ->withContext('order_id', $orderId);
 * // OR 直接 new（需显式传码）
 * throw new WiseException(20001, '支付签名校验失败', 400, null, ['order_id' => $orderId]);
 * </pre>
 *
 * @package wise\support\exception
 */
class WiseException extends \RuntimeException
{
    // ============================================================
    // 通用全局错误码（0~99，WiseException 类常量）
    //
    // 号段全景：
    //     0~99       → 本类常量              — 绝对通用
    //   100~999      → GeneralErrorCode      — 框架级通用码
    //  1000~9999     → 框架 + app 层业务码   — app\* 共享
    //  10000 起      → 每个 wise-* 包各占 10000 个（见类头号段表）
    // ============================================================

    /** 成功 */
    public const CODE_SUCCESS = 0;

    /** 未分类/未知错误 */
    public const CODE_UNKNOWN = 1;

    // ============================================================
    // 属性
    // ============================================================

    /**
     * 附加的上下文数据（用于日志记录和调试面板）
     *
     * @var array<string, mixed>
     */
    protected array $context = [];

    /**
     * HTTP 状态码
     */
    protected readonly int $httpCode;

    // ============================================================
    // 构造
    // ============================================================

    /**
     * @param int              $code     业务错误码（各包 ErrorCode 枚举值）
     * @param string           $message  人类可读的错误描述
     * @param int              $httpCode HTTP 状态码，默认 500
     * @param \Throwable|null  $previous 原始异常（链式异常）
     * @param array<string, mixed> $context 附加上下文数据
     */
    public function __construct(
        int $code = 0,
        string $message = '',
        int $httpCode = 500,
        ?\Throwable $previous = null,
        array $context = [],
    ) {
        $this->httpCode = $httpCode;
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * 静态工厂：创建异常实例（链式友好）
     */
    public static function create(int $code, string $message, int $httpCode = 500, ?\Throwable $previous = null): static
    {
        return new static($code, $message, $httpCode, $previous);
    }

    /**
     * 静态工厂：从错误码枚举创建异常（推荐）
     *
     * 自动从枚举提取错误码数值（value）与默认说明（defaultMessage()），
     * 避免每次 throw 处重复手写 message，保证说明文案统一。
     *
     * 用法：
     * <pre>
     * // 说明取自 ErrorCode::PAY_SIGN_INVALID->defaultMessage()
     * throw WiseException::of(ErrorCode::PAY_SIGN_INVALID)
     *     ->withContext('raw_data', $raw);
     *
     * // 需覆盖默认说明 / 指定 HTTP 状态码时
     * throw WiseException::of(ErrorCode::PAY_SIGN_INVALID, '自定义提示', 400);
     * </pre>
     *
     * @param ErrorCodeInterface     $code     包内错误码枚举（实现 ErrorCodeInterface）
     * @param string|null            $message  自定义错误说明（null 时取枚举 defaultMessage()）
     * @param int|null               $httpCode HTTP 状态码（null 时默认 400 业务错误）
     * @param \Throwable|null        $previous 原始异常（链式异常）
     * @param array<string, mixed>   $context  附加上下文数据
     */
    public static function of(
        ErrorCodeInterface $code,
        ?string $message = null,
        ?int $httpCode = null,
        ?\Throwable $previous = null,
        array $context = [],
    ): static {
        return new static(
            (int) $code->value,
            $message ?? $code->defaultMessage(),
            $httpCode ?? 400,
            $previous,
            $context,
        );
    }

    // ============================================================
    // 读取方法
    // ============================================================

    /**
     * 获取业务错误码
     */
    public function getErrorCode(): int
    {
        return $this->getCode();
    }

    /**
     * 获取 HTTP 状态码
     */
    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    /**
     * 获取上下文数据
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    // ============================================================
    // 链式构建方法
    // ============================================================

    /**
     * 追加上下文数据
     *
     * @return $this
     */
    public function withContext(string $key, mixed $value): static
    {
        $this->context[$key] = $value;
        return $this;
    }

    /**
     * 批量追加上下文数据
     *
     * @param array<string, mixed> $context
     * @return $this
     */
    public function withContextMany(array $context): static
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }

    // ============================================================
    // 输出
    // ============================================================

    /**
     * 转为数组（基础结构，不含 request_id/timestamp，由渲染层补充）
     *
     * @return array{code: int, msg: string, data?: array}
     */
    public function toArray(): array
    {
        $result = [
            'code' => $this->getErrorCode(),
            'msg'  => $this->getMessage(),
        ];

        if (!empty($this->context)) {
            $result['data'] = $this->context;
        }

        return $result;
    }
}
