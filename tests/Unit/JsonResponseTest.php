<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use think\Container;
use think\facade\Config;
use think\facade\Request;
use think\response\Json;
use wise\support\circuit\CircuitBreakerExceptionInterface;
use wise\support\context\RequestContext;
use wise\support\exception\WiseException;
use wise\support\traits\JsonResponse;

/**
 * 统一 JSON 响应 Trait 单元测试
 *
 * 需要真实 App + Config：json() 辅助函数会经容器解析 think\response\Json，
 * 其依赖 think\Config / think\Cookie，纯替身会导致类型错误。
 */
class JsonResponseTest extends TestCase
{
    private JsonResponseFixture $fixture;
    private JsonResponseDebugFixture $debugFixture;

    protected function setUp(): void
    {
        new \think\App(dirname(__DIR__, 5));
        Config::set(['debug' => false, 'debug_allow_ips' => []], 'app');
        RequestContext::clear();

        $this->bindRequest('9.9.9.9', ['password' => 'secret', 'page' => 2]);

        $this->fixture      = new JsonResponseFixture();
        $this->debugFixture = new JsonResponseDebugFixture();
    }

    protected function tearDown(): void
    {
        RequestContext::clear();
        Container::getInstance()->delete('request');
    }

    /**
     * 构造并绑定一个可控的 Request 实例
     */
    private function bindRequest(string $ip, array $params = [], string $uri = '/api/demo'): void
    {
        $request = new \think\Request();
        $request->withServer([
            'REMOTE_ADDR'    => $ip,
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => $uri,
            'HTTP_HOST'      => 'localhost',
            'SERVER_NAME'    => 'localhost',
        ]);
        $request->withGet($params);
        $request->withPost([]);

        Container::getInstance()->bind('request', $request);
    }

    // ============================================================
    // success() / error()
    // ============================================================

    public function test_success_returns_json_payload(): void
    {
        RequestContext::setRequestId('rid-1');

        $response = $this->fixture->callSuccess('操作成功', ['id' => 1]);

        $this->assertInstanceOf(Json::class, $response);
        $this->assertSame(200, $response->getCode());

        $data = $response->getData();
        $this->assertSame(0, $data['code']);
        $this->assertSame('操作成功', $data['msg']);
        $this->assertSame(['id' => 1], $data['data']);
        $this->assertSame('rid-1', $data['request_id']);
        $this->assertIsInt($data['timestamp']);
        $this->assertArrayNotHasKey('debug', $data);
    }

    public function test_success_defaults(): void
    {
        $data = $this->fixture->callSuccess()->getData();

        $this->assertSame(0, $data['code']);
        $this->assertSame('ok', $data['msg']);
        $this->assertSame([], $data['data']);
    }

    public function test_error_uses_unknown_code_by_default(): void
    {
        $response = $this->fixture->callError('参数错误', ['field' => 'name'], 1001);

        $this->assertInstanceOf(Json::class, $response);
        $data = $response->getData();
        $this->assertSame(1001, $data['code']);
        $this->assertSame('参数错误', $data['msg']);
        $this->assertSame(['field' => 'name'], $data['data']);
    }

    public function test_error_default_code_is_unknown(): void
    {
        $data = $this->fixture->callError()->getData();

        $this->assertSame(WiseException::CODE_UNKNOWN, $data['code']);
        $this->assertSame('error', $data['msg']);
    }

    public function test_debug_output_is_skipped_when_not_allowed(): void
    {
        Config::set(['debug' => true, 'debug_allow_ips' => ['1.2.3.4']], 'app');

        $response = $this->fixture->callSuccess('ok');

        // 请求 IP 不在白名单且未授权 → 仍返回普通 Json
        $this->assertInstanceOf(Json::class, $response);
        $this->assertArrayNotHasKey('debug', $response->getData());
    }

    // ============================================================
    // 调试回显
    // ============================================================

    public function test_debug_output_when_ip_whitelisted(): void
    {
        Config::set(['debug' => true, 'debug_allow_ips' => ['9.9.9.9']], 'app');

        $result = $this->fixture->callSuccess('操作成功', ['id' => 1]);

        $this->assertIsString($result, '调试模式下返回 json_encode 字符串');

        $payload = json_decode($result, true);
        $this->assertArrayHasKey('debug', $payload);
        // debug 内的 request_id 被剔除，避免与外层重复
        $this->assertArrayNotHasKey('request_id', $payload['debug']);
        $this->assertSame('/api/demo', $payload['debug']['request_uri']);
        $this->assertSame('POST', $payload['debug']['request_method']);
        $this->assertSame('9.9.9.9', $payload['debug']['ip']);
        $this->assertSame(1, substr_count($result, '"request_id"'));
    }

    public function test_debug_output_masks_sensitive_params(): void
    {
        Config::set(['debug' => true, 'debug_allow_ips' => ['9.9.9.9']], 'app');
        $this->bindRequest('9.9.9.9', ['password' => 'secret', 'token' => 'abc', 'page' => 2]);

        $payload = json_decode($this->fixture->callError('boom'), true);

        $this->assertSame('***', $payload['debug']['params']['password']);
        $this->assertSame('***', $payload['debug']['params']['token']);
        $this->assertSame(2, $payload['debug']['params']['page']);
        $this->assertStringNotContainsString('secret', (string) json_encode($payload));
    }

    public function test_debug_output_allowed_by_subclass_authorization(): void
    {
        Config::set(['debug' => true, 'debug_allow_ips' => []], 'app');

        $result = $this->debugFixture->callSuccess('ok');

        $this->assertIsString($result);
        $this->assertArrayHasKey('debug', json_decode($result, true));
    }

    public function test_is_debug_allowed_directly(): void
    {
        // 披露判定收敛后：app.debug 为假时，即使子类已授权也必须拒绝
        $this->assertFalse($this->fixture->callIsDebugAllowed());
        $this->assertFalse($this->debugFixture->callIsDebugAllowed());

        Config::set(['debug' => true, 'debug_allow_ips' => []], 'app');
        $this->assertFalse($this->fixture->callIsDebugAllowed());
        $this->assertTrue($this->debugFixture->callIsDebugAllowed());

        Config::set(['debug' => true, 'debug_allow_ips' => ['9.9.9.9']], 'app');
        $this->assertTrue($this->fixture->callIsDebugAllowed());
    }

    public function test_get_debug_info_structure(): void
    {
        $info = $this->fixture->callGetDebugInfo();

        $this->assertSame(
            ['request_id', 'request_uri', 'request_method', 'ip', 'controller', 'action', 'params'],
            array_keys($info)
        );
        $this->assertSame('9.9.9.9', $info['ip']);
        $this->assertSame('***', $info['params']['password']);
    }

    // ============================================================
    // 敏感参数掩码
    // ============================================================

    public function test_sanitize_params_masks_nested_sensitive_keys(): void
    {
        $result = $this->fixture->callSanitize([
            'username'  => 'tom',
            'password'  => 'p',
            'API-KEY'   => 'k',
            'profile'   => ['refresh_token' => 'r', 'nickname' => 'n'],
            'list'      => [['session_id' => 's']],
            'int'       => 5,
        ]);

        $this->assertSame('tom', $result['username']);
        $this->assertSame('***', $result['password']);
        $this->assertSame('***', $result['API-KEY']);
        $this->assertSame('***', $result['profile']['refresh_token']);
        $this->assertSame('n', $result['profile']['nickname']);
        $this->assertSame('***', $result['list'][0]['session_id']);
        $this->assertSame(5, $result['int']);
    }

    public function test_sanitize_params_passes_through_non_array(): void
    {
        $this->assertSame('scalar', $this->fixture->callSanitize('scalar'));
        $this->assertSame(12, $this->fixture->callSanitize(12));
        $this->assertNull($this->fixture->callSanitize(null));
    }

    public function test_is_sensitive_key_normalizes_separators_and_case(): void
    {
        foreach (['password', 'PASSWORD', 'api-key', 'api key', 'access_token', 'authorization', 'code', 'session_id', 'ticket', 'old_password'] as $key) {
            $this->assertTrue($this->fixture->callIsSensitiveKey($key), "{$key} 应为敏感键");
        }

        foreach (['username', 'page', 'accesstoken', 'passwords', ''] as $key) {
            $this->assertFalse($this->fixture->callIsSensitiveKey($key), "{$key} 不应为敏感键");
        }
    }

    // ============================================================
    // request_id
    // ============================================================

    public function test_get_request_id_generates_when_unknown(): void
    {
        $id = $this->fixture->callGetRequestId();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id);
        $this->assertSame($id, RequestContext::getRequestId());
    }

    public function test_get_request_id_returns_existing(): void
    {
        RequestContext::setRequestId('rid-existing');

        $this->assertSame('rid-existing', $this->fixture->callGetRequestId());
    }

    // ============================================================
    // exceptionResponse() / circuitResponse()
    // ============================================================

    public function test_exception_response_maps_http_code_and_payload(): void
    {
        RequestContext::setRequestId('rid-2');
        $exception = new WiseException(1001, '参数错误', 422, null, ['field' => 'amount']);

        $response = $this->fixture->callExceptionResponse($exception);

        $this->assertInstanceOf(Json::class, $response);
        $this->assertSame(422, $response->getCode());

        $data = $response->getData();
        $this->assertSame(1001, $data['code']);
        $this->assertSame('参数错误', $data['msg']);
        $this->assertSame(['field' => 'amount'], $data['data']);
        $this->assertSame('rid-2', $data['request_id']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function test_exception_response_without_context_omits_data(): void
    {
        $exception = new WiseException(1002, '无上下文', 400);

        $data = $this->fixture->callExceptionResponse($exception)->getData();

        $this->assertArrayNotHasKey('data', $data);
    }

    public function test_circuit_response_returns_503_with_retry_after(): void
    {
        $response = $this->fixture->callCircuitResponse(new CircuitStubException('服务暂不可用', 30));

        $this->assertSame(503, $response->getCode());
        $this->assertSame('30', $response->getHeader()['Retry-After']);

        $data = $response->getData();
        $this->assertSame(-1, $data['code']);
        // 未通过披露判定（setUp 里 debug=false）→ 固定文案，不回显异常文本
        $this->assertSame('服务暂不可用，请稍后重试', $data['msg']);
        $this->assertSame(30, $data['data']['retry_after']);
    }

    public function test_circuit_response_hides_exception_message_when_debug_not_allowed(): void
    {
        Config::set(['debug' => true, 'debug_allow_ips' => ['1.2.3.4']], 'app'); // 请求 IP 为 9.9.9.9

        $data = $this->fixture
            ->callCircuitResponse(new CircuitStubException('上游 pay-service 不可用 [family=pay]', 30))
            ->getData();

        $this->assertSame('服务暂不可用，请稍后重试', $data['msg']);
        $this->assertStringNotContainsString('pay-service', (string) json_encode($data, JSON_UNESCAPED_UNICODE));
        $this->assertSame(30, $data['data']['retry_after']);
    }

    public function test_circuit_response_shows_exception_message_when_ip_allowlisted(): void
    {
        Config::set(['debug' => true, 'debug_allow_ips' => ['9.9.9.9']], 'app');

        $data = $this->fixture
            ->callCircuitResponse(new CircuitStubException('上游 pay-service 不可用 [family=pay]', 30))
            ->getData();

        $this->assertSame('上游 pay-service 不可用 [family=pay]', $data['msg']);
    }
}

// ============================================================
// 测试替身与夹具
// ============================================================

class JsonResponseFixture
{
    use JsonResponse;

    public function callSuccess(string $msg = 'ok', mixed $data = [], int $code = 0): Json|string
    {
        return $this->success($msg, $data, $code);
    }

    public function callError(string $msg = 'error', mixed $data = [], int $code = WiseException::CODE_UNKNOWN): Json|string
    {
        return $this->error($msg, $data, $code);
    }

    public function callExceptionResponse(WiseException $e): Json
    {
        return $this->exceptionResponse($e);
    }

    public function callCircuitResponse(CircuitBreakerExceptionInterface $e): Json
    {
        return $this->circuitResponse($e);
    }

    public function callGetRequestId(): string
    {
        return $this->getRequestId();
    }

    public function callGetDebugInfo(): array
    {
        return $this->getDebugInfo();
    }

    public function callSanitize(mixed $params): mixed
    {
        return $this->sanitizeParams($params);
    }

    public function callIsSensitiveKey(string $key): bool
    {
        return $this->isSensitiveKey($key);
    }

    public function callIsDebugAllowed(): bool
    {
        return $this->isDebugAllowed();
    }
}

class JsonResponseDebugFixture extends JsonResponseFixture
{
    protected function isDebugAuthorized(): bool
    {
        return true;
    }
}

class CircuitStubException extends \RuntimeException implements CircuitBreakerExceptionInterface
{
    public function __construct(string $message, private int $retryAfter)
    {
        parent::__construct($message);
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
