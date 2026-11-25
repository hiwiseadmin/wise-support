<?php
declare(strict_types=1);

namespace wise\support\exception;

/**
 * 错误码枚举契约
 *
 * 各 wise-* 包的错误码枚举（如 wise\wx\exception\ErrorCode）应实现此接口，
 * 为每个错误码提供默认中文说明。这样抛异常时通过 WiseException::of() 即可
 * 自动携带错误说明，无需在每次 throw 处重复手写 message。
 *
 * 实现示例：
 * <pre>
 * enum ErrorCode: int implements ErrorCodeInterface
 * {
 *     case PAY_SIGN_INVALID = 2201;
 *
 *     public function defaultMessage(): string
 *     {
 *         return match ($this) {
 *             self::PAY_SIGN_INVALID => '支付签名验证失败',
 *             // ...
 *         };
 *     }
 * }
 * </pre>
 *
 * @package wise\support\exception
 */
interface ErrorCodeInterface extends \BackedEnum
{
    /**
     * 错误码对应的默认中文说明
     *
     * 作为 WiseException 构造时未显式提供 message 时的兜底描述。
     */
    public function defaultMessage(): string;
}
