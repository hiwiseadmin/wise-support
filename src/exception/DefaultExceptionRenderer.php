<?php
declare(strict_types=1);

namespace wise\support\exception;

use think\facade\Config;
use think\facade\Request;

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
     * 【2026-09-27 定案的口径】**业务错误统一 HTTP 200 + `code != 0`**
     * （与 `JsonResponse::error()` 同一套方案，wise-wx 一直这么用）：
     *   · 业务/校验类错误 → **200**（错误信息由响应体的 `code`/`msg` 承载，客户端按 `code` 分支）
     *   · 只有**传输 / 鉴权 / 限流 / 基础设施**故障才用 4xx/5xx，
     *     且这些码在本函数里都是**显式列举**的（401 / 403 / 404 / 405 / 429 / 500 / 503）
     *
     * 【为什么要改】此前"业务 → 400、包号段 → 500"是各写一套的**兜底**，导致
     *   同一个业务码在不同入口拿到不同 HTTP 码（`ExceptionHandle` 400 vs `ApiDispatch` 500），
     *   客户端与网关都无法稳定分类（详见 `13-cocos-bootstrap.md` §四十）。
     */
    public static function errorCodeToHttpCode(int $code): int
    {
        return match (true) {
            // 成功 / 未知 → 500（不应走到这里，安全兜底）
            $code === WiseException::CODE_SUCCESS,
            $code === WiseException::CODE_UNKNOWN => 500,

            // GeneralErrorCode: 传输/协议语义（保持原样，客户端/网关据此分流）
            $code === GeneralErrorCode::BAD_REQUEST->value          => 400,
            $code === GeneralErrorCode::UNAUTHORIZED->value         => 401,
            $code === GeneralErrorCode::FORBIDDEN->value            => 403,
            $code === GeneralErrorCode::NOT_FOUND->value            => 404,
            $code === GeneralErrorCode::METHOD_NOT_ALLOWED->value   => 405,
            $code === GeneralErrorCode::TOO_MANY_REQUESTS->value    => 429,
            $code === GeneralErrorCode::SERVER_ERROR->value         => 500,
            $code === GeneralErrorCode::SERVICE_UNAVAILABLE->value  => 503,

            // 校验类 200~299 → **200**（业务错误：原来 422）
            $code >= 200 && $code < 300                            => 200,

            // 基础设施 300~399 → 500（真·服务故障，保留给监控/网关）
            $code >= 300 && $code < 400                            => 500,

            // 框架 + app 层业务码 400~9999 → **200**（原来 400）
            $code >= 400 && $code < 10000                          => 200,

            // wise-* 包号段（10000 起）→ **200**（业务；原来 500）
            // 需要 401/403/429/5xx 的，走上面的显式码，或由异常显式传 `$httpCode`
            $code >= 10000                                         => 200,

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
     * 是否允许向调用方披露异常内部细节（唯一判定点）
     *
     * 【为什么收敛到一处】包内此前有三条各自为政的披露路径：JsonResponse::error()
     * 的 debug 块走「app.debug + IP 白名单/子类授权」，而 ErrorHandler::handleException()
     * 与 renderGeneric() 只看 app.debug。同一份响应里两套门槛意味着只要 app.debug
     * 为真，未列入白名单的普通客户端也会收到绝对路径、异常文本与堆栈。
     *
     * 因此全部披露路径必须调用本方法，且默认拒绝：
     *   - app.debug 为假 → 一律不披露；
     *   - 请求 IP 命中 app.debug_allow_ips → 披露；
     *   - 否则由调用方传入的 $authorized（如子类覆写的 isDebugAuthorized()、
     *     管理员登录态）决定，默认 false。
     *
     * @param bool $authorized 调用方的自定义授权结果（默认拒绝）
     */
    public static function isDebugDisclosureAllowed(bool $authorized = false): bool
    {
        if (!Config::get('app.debug', false)) {
            return false;
        }

        // Request 未绑定（CLI / 队列 / 测试）时不能视为白名单命中
        try {
            $ip = Request::ip();
        } catch (\Throwable) {
            return false;
        }

        $allowedIps = Config::get('app.debug_allow_ips', []);
        if (!empty($allowedIps) && in_array($ip, $allowedIps, true)) {
            return true;
        }

        return $authorized;
    }

    /**
     * 渲染非 WiseException 的通用异常
     */
    protected function renderGeneric(\Throwable $e): array
    {
        $debug = self::isDebugDisclosureAllowed();

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
