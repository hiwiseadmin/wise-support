<?php
declare(strict_types=1);

namespace wise\support\traits;

use think\facade\Request;
use think\response\Json;
use wise\support\circuit\CircuitBreakerExceptionInterface;
use wise\support\context\RequestContext;
use wise\support\exception\DefaultExceptionRenderer;
use wise\support\exception\WiseException;

/**
 * JSON 响应处理 Trait
 *
 * 提供统一的 success() / error() / exceptionResponse() 响应格式，
 * 适用于所有 wise-* 包的控制器与中间件。
 *
 * 使用方式：
 * <pre>
 *   class MyController
 *   {
 *       use JsonResponse;
 *
 *       public function index()
 *       {
 *           return $this->success('操作成功', $data);
 *       }
 *
 *       // 同步快速拒绝（控制器层直接返回）
 *       public function quickFail()
 *       {
 *           return $this->error('参数错误', $errors, 1001);
 *       }
 *
 *       // 深层异常传播（Service/Repository 抛异常）
 *       public function deduct()
 *       {
 *           try {
 *               $this->walletService->deduct(...);
 *           } catch (WiseException $e) {
 *               return $this->exceptionResponse($e);
 *           }
 *       }
 *   }
 * </pre>
 *
 * error() 与 exceptionResponse() 的输出格式 **完全一致**：
 *   { "code": 2001, "msg": "...", "data": {...}, "request_id": "...", "timestamp": 1234567890 }
 *
 * 安全提示：
 *   - success()/error() 在 app.debug + IP 白名单命中时会把请求参数附加到 debug 输出，
 *     但会先经 sanitizeParams() 对 password/token/secret 等敏感键做掩码；
 *   - circuitResponse() 的 $msg 仅在披露判定（app.debug + IP 白名单/子类授权）通过时
 *     才回显 $e->getMessage()，否则返回固定文案；error() 的 $msg 始终对外输出，
 *     调用方必须传入面向用户的文案，切勿直接回显 $e->getMessage()
 *     （可能含 SQL/路径等内部细节）。
 *
 * @package wise\support\traits
 */
trait JsonResponse
{
    /**
     * 成功响应
     *
     * @param string $msg  提示信息
     * @param mixed  $data 响应数据
     * @param int    $code 业务状态码（默认 0=成功）
     */
    protected function success(string $msg = 'ok', $data = [], int $code = 0): Json|string
    {
        $response = [
            'code'       => $code,
            'msg'        => $msg,
            'data'       => $data,
            'request_id' => $this->getRequestId(),
            'timestamp'  => time(),
        ];

        if ($this->isDebugAllowed()) {
            $debugInfo = $this->getDebugInfo();
            unset($debugInfo['request_id']);
            $response['debug'] = $debugInfo;
            return json_encode($response, JSON_UNESCAPED_UNICODE);
        }

        return json($response);
    }

    /**
     * 失败响应（控制器层快速返回）
     *
     * @param string $msg  错误描述
     * @param mixed  $data 附加数据
     * @param int    $code 业务错误码（默认 999=未知错误，建议使用包内 ErrorCode 枚举值）
     */
    protected function error(string $msg = 'error', $data = [], int $code = WiseException::CODE_UNKNOWN): Json|string
    {
        $response = [
            'code'       => $code,
            'msg'        => $msg,
            'data'       => $data,
            'request_id' => $this->getRequestId(),
            'timestamp'  => time(),
        ];

        if ($this->isDebugAllowed()) {
            $debugInfo = $this->getDebugInfo();
            unset($debugInfo['request_id']);
            $response['debug'] = $debugInfo;
            return json_encode($response, JSON_UNESCAPED_UNICODE);
        }

        return json($response);
    }

    /**
     * 从 WiseException 生成统一 JSON 响应
     *
     * 委托 DefaultExceptionRenderer 提取 {code, msg, data}，
     * 自动补全 request_id + timestamp，并按 WiseException::getHttpCode() 设置 HTTP 状态码。
     *
     * 与 error() 输出格式一致，可互相替换。
     *
     * @param WiseException $e
     * @return Json
     */
    protected function exceptionResponse(WiseException $e): Json
    {
        $renderer = new DefaultExceptionRenderer();
        $payload  = $renderer->render($e);

        $payload['request_id'] = $this->getRequestId();
        $payload['timestamp']  = time();

        return json($payload, $e->getHttpCode());
    }

    /**
     * 熔断响应：HTTP 503 + Retry-After 头
     *
     * 用于断路器 OPEN 时直接拒绝请求，引导客户端在冷却期后重试。
     * 由 wise\support\circuit\CircuitBreakerExceptionInterface 实现类触发。
     *
     * 与 error() 的区别：error() 返回 HTTP 200，本方法返回 503，
     * 用于网关/负载均衡器识别"下游不可用"语义。
     *
     * @param CircuitBreakerExceptionInterface $e 熔断异常实例（含 retryAfter）
     * @return Json
     */
    protected function circuitResponse(CircuitBreakerExceptionInterface $e): Json
    {
        // 熔断异常来自包外实现（如 wise-wx HasCircuitBreaker、wise-agent
        // ServiceUnavailableException），其 message 常含上游/服务名等内部标识；
        // 与 error() 一样只在披露判定通过时回显，否则给固定文案。
        // 状态码与 Retry-After 不受影响，客户端应以 code/-1 与 Retry-After 分支。
        $msg = $this->isDebugAllowed()
            ? $e->getMessage()
            : '服务暂不可用，请稍后重试';

        return json([
            'code'       => -1,
            'msg'        => $msg,
            'data'       => [
                'retry_after' => $e->getRetryAfter(),
            ],
            'request_id' => $this->getRequestId(),
            'timestamp'  => time(),
        ], 503)->header(['Retry-After' => (string) $e->getRetryAfter()]);
    }

    /**
     * 获取 request_id
     *
     * 从 RequestContext 读取（由 AppService::initRequestId() 设置）；
     * 不可用时自动生成一个备用 ID。
     */
    protected function getRequestId(): string
    {
        $id = RequestContext::getRequestId();
        if ($id === 'unknown') {
            $id = bin2hex(random_bytes(8));
            RequestContext::setRequestId($id);
        }
        return $id;
    }

    /**
     * 获取调试信息（可被子类覆盖）
     */
    protected function getDebugInfo(): array
    {
        return [
            'request_id'     => RequestContext::getRequestId(),
            'request_uri'    => Request::url(),
            'request_method' => Request::method(),
            'ip'             => Request::ip(),
            'controller'     => Request::controller(),
            'action'         => Request::action(),
            'params'         => $this->sanitizeParams(Request::param()),
        ];
    }

    /**
     * 敏感请求参数掩码白名单。
     *
     * 调试信息回显 Request::param() 时会遍历并掩码命中这些键的字段，
     * 防止口令 / token / 密钥等被写入响应日志或泄漏给前端。
     *
     * @var string[]
     */
    protected array $sensitiveParamKeys = [
        'password', 'pwd', 'passwd', 'old_password', 'new_password', 'confirm_password',
        'token', 'access_token', 'refresh_token', 'api_key', 'apikey', 'secret',
        'secret_key', 'client_secret', 'authorization', 'code', 'session_id', 'ticket',
    ];

    /**
     * 对请求参数做敏感键掩码（递归）。
     *
     * @param mixed $params 请求参数
     * @return mixed
     */
    protected function sanitizeParams(mixed $params): mixed
    {
        if (is_array($params)) {
            $out = [];
            foreach ($params as $k => $v) {
                $out[$k] = $this->isSensitiveKey((string) $k)
                    ? '***'
                    : $this->sanitizeParams($v);
            }
            return $out;
        }
        return $params;
    }

    /**
     * 判断键名是否为敏感参数（小写 + 去下划线后与白名单比对）
     */
    protected function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));
        return in_array($normalized, $this->sensitiveParamKeys, true);
    }

    /**
     * 判断是否允许输出调试信息
     *
     * 防止外部通过 ?debug=1 参数任意获取敏感信息。
     * 默认仅允许白名单 IP；可被子类覆盖以支持其他鉴权方式。
     *
     * 判定统一委托 DefaultExceptionRenderer::isDebugDisclosureAllowed()，
     * 使本 trait 与 ErrorHandler / DefaultExceptionRenderer 的披露门槛一致
     * （app.debug 为假时这里也必然返回 false）。
     */
    protected function isDebugAllowed(): bool
    {
        return DefaultExceptionRenderer::isDebugDisclosureAllowed($this->isDebugAuthorized());
    }

    /**
     * 自定义调试授权（可被子类覆盖）
     *
     * @return bool 默认拒绝，需子类显式放行
     */
    protected function isDebugAuthorized(): bool
    {
        return false;
    }
}
