<?php
declare(strict_types=1);

namespace wise\support\exception;

use think\facade\Config;

/**
 * WiseException 默认渲染器
 *
 * 将 WiseException（或任意 Throwable）转换为统一数组结构。
 * - WiseException       → 直接使用 toArray()
 * - 其他 Throwable       → 提取 message/code，生产环境下隐藏详情
 *
 * 通过 ExtPoint('exception.renderer', SINGLE) 注册，
 * 可在任意 wise-* 包的 ServiceProvider 中替换为自定义实现。
 *
 * @package wise\support\exception
 */
class DefaultExceptionRenderer implements ExceptionRendererInterface
{
    // ============================================================
    // 错误码 → HTTP 状态码映射（公共方法，供 ExceptionHandle 等调用）
    // ============================================================

    /**
     * 将业务错误码映射为 HTTP 状态码
     *
     * 覆盖 GeneralErrorCode(100~999) 和框架/app 号段(1000~9999)，
     * 供全局异常处理器在渲染时确定 HTTP 响应码。
     *
     * 注意：wise-* 包号段（10000 起）默认映射为 500，
     * 具体 HTTP 状态码应由各包异常在构造 WiseException 时通过
     * httpCode 参数显式指定（WiseException::of 的 $httpCode）。
     */
    public static function errorCodeToHttpCode(int $code): int
    {
        return match (true) {
            // 成功 / 未知 → 500（不应走到这里，安全兜底）
            $code === WiseException::CODE_SUCCESS,
            $code === WiseException::CODE_UNKNOWN => 500,

            // GeneralErrorCode: HTTP 语义 100~199
            $code === GeneralErrorCode::BAD_REQUEST->value          => 400,
            $code === GeneralErrorCode::UNAUTHORIZED->value         => 401,
            $code === GeneralErrorCode::FORBIDDEN->value            => 403,
            $code === GeneralErrorCode::NOT_FOUND->value            => 404,
            $code === GeneralErrorCode::METHOD_NOT_ALLOWED->value   => 405,
            $code === GeneralErrorCode::TOO_MANY_REQUESTS->value    => 429,
            $code === GeneralErrorCode::SERVER_ERROR->value         => 500,
            $code === GeneralErrorCode::SERVICE_UNAVAILABLE->value  => 503,

            // GeneralErrorCode: 校验 200~299 → 422
            $code >= 200 && $code < 300                            => 422,

            // GeneralErrorCode: 基础设施 300~399 → 500
            $code >= 300 && $code < 400                            => 500,

            // 框架 + app 层业务码 400~9999 → 按业务自行映射,默认 400
            $code >= 400 && $code < 10000                          => 400,

            // wise-* 包号段（10000 起）→ 默认 500，由异常显式指定 HTTP 状态码
            $code >= 10000                                         => 500,

            default                                                => 500,
        };
    }

    // ============================================================
    // 渲染
    // ============================================================
    /**
     * 渲染异常为统一结构数组
     *
     * @return array{code: int, msg: string, data?: array}
     */
    public function render(\Throwable $e): array
    {
        if ($e instanceof WiseException) {
            return $e->toArray();
        }

        return $this->renderGeneric($e);
    }

    /**
     * 渲染非 WiseException 的通用异常
     */
    protected function renderGeneric(\Throwable $e): array
    {
        $debug = Config::get('app.debug', false);

        $result = [
            'code' => $e->getCode() ?: WiseException::CODE_UNKNOWN,
            'msg'  => $debug
                ? $e->getMessage()
                : '服务器内部错误',
        ];

        if ($debug) {
            $result['data'] = [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ];
        }

        return $result;
    }
}
