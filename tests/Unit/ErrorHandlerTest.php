<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use think\Container;
use think\exception\HttpException;
use think\exception\ValidateException;
use think\facade\Config;
use think\response\Json;
use wise\support\exception\WiseException;
use wise\support\traits\ErrorHandler;
use wise\support\traits\JsonResponse;

/**
 * 错误处理 Trait 单元测试
 */
class ErrorHandlerTest extends TestCase
{
    private ErrorHandlerFixture $fixture;
    private ErrorHandlerJsonFixture $jsonFixture;
    private ErrorHandlerLogStub $log;

    protected function setUp(): void
    {
        new \think\App(dirname(__DIR__, 5));
        Config::set(['debug' => false, 'debug_allow_ips' => []], 'app');

        $this->log = new ErrorHandlerLogStub();
        Container::getInstance()->bind('log', $this->log);

        $this->fixture     = new ErrorHandlerFixture();
        $this->jsonFixture = new ErrorHandlerJsonFixture();
    }

    protected function tearDown(): void
    {
        Container::getInstance()->delete('log');
        Container::getInstance()->delete('request');
        \wise\support\context\RequestContext::clear();
    }

    /**
     * 构造并绑定一个可控的 Request 实例（用于 IP 白名单披露判定）
     */
    private function bindRequest(string $ip): void
    {
        $request = new \think\Request();
        $request->withServer([
            'REMOTE_ADDR'    => $ip,
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/api/demo',
            'HTTP_HOST'      => 'localhost',
            'SERVER_NAME'    => 'localhost',
        ]);

        Container::getInstance()->bind('request', $request);
    }

    // ============================================================
    // 错误收集
    // ============================================================

    public function test_add_error_without_field_appends(): void
    {
        $this->fixture->add('参数错误', 1001);

        $this->assertTrue($this->fixture->has());
        $this->assertSame([['code' => 1001, 'msg' => '参数错误']], $this->fixture->errors());
        $this->assertSame(['code' => 1001, 'msg' => '参数错误'], $this->fixture->last());
    }

    public function test_add_error_with_field_keys_by_field(): void
    {
        $this->fixture->add('名称必填', 200, 'name');

        $this->assertSame(['name' => ['code' => 200, 'msg' => '名称必填']], $this->fixture->errors());
    }

    public function test_add_error_is_chainable(): void
    {
        $result = $this->fixture->add('e1')->add('e2');

        $this->assertInstanceOf(ErrorHandlerFixture::class, $result);
        $this->assertCount(2, $this->fixture->errors());
    }

    public function test_has_error_and_clear_errors(): void
    {
        $this->assertFalse($this->fixture->has());
        $this->assertNull($this->fixture->last());

        $this->fixture->add('boom');
        $this->assertTrue($this->fixture->has());

        $this->assertInstanceOf(ErrorHandlerFixture::class, $this->fixture->clear());
        $this->assertFalse($this->fixture->has());
    }

    public function test_last_error_returns_last_registered(): void
    {
        $this->fixture->add('first');
        $this->fixture->add('second', 7, 'field');

        $this->assertSame(['code' => 7, 'msg' => 'second'], $this->fixture->last());
    }

    // ============================================================
    // handleException —— 返回数组模式
    // ============================================================

    public function test_generic_exception_hides_details_in_production(): void
    {
        $result = $this->fixture->handle(new \RuntimeException('internal detail'));

        $this->assertSame(500, $result['code']);
        $this->assertSame('服务器内部错误', $result['msg']);
        $this->assertNull($result['data']);
        $this->assertIsInt($result['timestamp']);
    }

    public function test_generic_exception_keeps_code(): void
    {
        $result = $this->fixture->handle(new \RuntimeException('x', 1234));

        $this->assertSame(1234, $result['code']);
    }

    public function test_generic_exception_hides_details_when_debug_without_allowlist(): void
    {
        // app.debug 为真但调用方 IP 不在白名单、且无子类授权 → 必须隐藏内部细节
        // （修复前这条路径只看 app.debug，会把绝对路径与堆栈回显给白名单外客户端）
        Config::set(['debug' => true, 'debug_allow_ips' => []], 'app');
        $this->bindRequest('9.9.9.9');

        $result = $this->fixture->handle(new \RuntimeException('internal detail'));

        $this->assertSame('服务器内部错误', $result['msg']);
        $this->assertNull($result['data']);
    }

    public function test_generic_exception_exposes_details_when_ip_allowlisted(): void
    {
        Config::set(['debug' => true, 'debug_allow_ips' => ['9.9.9.9']], 'app');
        $this->bindRequest('9.9.9.9');

        $result = $this->fixture->handle(new \RuntimeException('internal detail'));

        $this->assertSame('internal detail', $result['msg']);
        $this->assertArrayHasKey('file', $result['data']);
        $this->assertArrayHasKey('line', $result['data']);
        $this->assertArrayHasKey('trace', $result['data']);
        $this->assertNotEmpty($result['data']['trace']);
    }

    public function test_validate_exception_maps_to_400(): void
    {
        $exception = new ValidateException(['name' => ['名称必填']]);

        $result = $this->fixture->handle($exception);

        $this->assertSame(400, $result['code']);
        $this->assertSame(['name' => ['名称必填']], $result['data']);
        $this->assertSame(json_encode(['name' => ['名称必填']]), $result['msg']);
    }

    public function test_wise_exception_uses_error_code_and_context(): void
    {
        $exception = new WiseException(1001, '参数错误', 422, null, ['field' => 'amount']);

        $result = $this->fixture->handle($exception);

        $this->assertSame(1001, $result['code']);
        $this->assertSame('参数错误', $result['msg']);
        $this->assertSame(['field' => 'amount'], $result['data']);
    }

    public function test_http_exception_uses_status_code_and_text(): void
    {
        $result = $this->fixture->handle(new HttpException(404, ''));

        $this->assertSame(404, $result['code']);
        $this->assertSame('Not Found', $result['msg']);
        $this->assertNull($result['data']);
    }

    public function test_http_exception_keeps_custom_message(): void
    {
        $result = $this->fixture->handle(new HttpException(403, '无权访问'));

        $this->assertSame(403, $result['code']);
        $this->assertSame('无权访问', $result['msg']);
    }

    // ============================================================
    // handleException —— 自动 JSON
    // ============================================================

    public function test_handle_exception_returns_json_when_trait_composed(): void
    {
        $response = $this->jsonFixture->handle(new WiseException(1001, '参数错误', 422));

        $this->assertInstanceOf(Json::class, $response);
        // 走 JsonResponse::error() → HTTP 恒为 200，业务码在 payload.code
        $this->assertSame(200, $response->getCode());
        $this->assertSame(1001, $response->getData()['code']);
        $this->assertSame('参数错误', $response->getData()['msg']);
    }

    public function test_handle_exception_json_includes_exception_context(): void
    {
        $response = $this->jsonFixture->handle(
            new WiseException(1001, '参数错误', 422, null, ['field' => 'amount'])
        );

        $this->assertSame(['field' => 'amount'], $response->getData()['data']);
    }

    public function test_return_flag_forces_array_even_with_json_trait(): void
    {
        $result = $this->jsonFixture->handle(new \RuntimeException('x'), true);

        $this->assertIsArray($result);
        $this->assertSame(500, $result['code']);
    }

    // ============================================================
    // 日志
    // ============================================================

    public function test_client_error_is_logged_as_warning(): void
    {
        $this->fixture->handle(new HttpException(404, 'Not Found'));

        $this->assertCount(1, $this->log->records);
        $this->assertSame('warning', $this->log->records[0]['level']);
        $this->assertStringContainsString('Not Found', $this->log->records[0]['message']);
        $this->assertArrayHasKey('trace', json_decode($this->log->records[0]['message'], true));
    }

    public function test_server_error_is_logged_as_error(): void
    {
        $this->fixture->handle(new HttpException(503, 'down'));

        $this->assertSame('error', $this->log->records[0]['level']);
    }

    public function test_generic_exception_is_logged_as_error(): void
    {
        $this->fixture->handle(new \RuntimeException('boom'));

        $this->assertSame('error', $this->log->records[0]['level']);
    }

    public function test_wise_exception_diagnostic_context_is_logged_but_not_returned(): void
    {
        $e = (new \wise\support\exception\WiseException(150, '服务暂不可用', 503))
            ->withContext('public', 'ok')
            ->withDiagnosticContext('sql', 'select 1 from dual');

        $result = $this->fixture->handle($e);

        // 响应体只带公开上下文
        $this->assertSame(['public' => 'ok'], $result['data']);
        $this->assertStringNotContainsString('select 1 from dual', (string) json_encode($result));

        // 诊断上下文只出现在日志里
        $logged = json_decode($this->log->records[0]['message'], true);
        $this->assertSame(['sql' => 'select 1 from dual'], $logged['diagnostic']);
    }

    public function test_log_exception_accepts_custom_level(): void
    {
        $this->fixture->log(new \RuntimeException('custom'), 'notice');

        $this->assertSame('notice', $this->log->records[0]['level']);
    }

    // ============================================================
    // HTTP 状态文本
    // ============================================================

    public function test_http_status_text_mapping(): void
    {
        $this->assertSame('Bad Request', $this->fixture->statusText(400));
        $this->assertSame('Unauthorized', $this->fixture->statusText(401));
        $this->assertSame('Forbidden', $this->fixture->statusText(403));
        $this->assertSame('Method Not Allowed', $this->fixture->statusText(405));
        $this->assertSame('Request Timeout', $this->fixture->statusText(408));
        $this->assertSame('Bad Gateway', $this->fixture->statusText(502));
        $this->assertSame('Gateway Timeout', $this->fixture->statusText(504));
        $this->assertSame('Unknown Error', $this->fixture->statusText(418));
    }
}

// ============================================================
// 测试替身与夹具
// ============================================================

class ErrorHandlerFixture
{
    use ErrorHandler;

    public function add(string $message, int $code = 0, string $field = ''): self
    {
        return $this->addError($message, $code, $field);
    }

    public function errors(): array
    {
        return $this->getErrors();
    }

    public function has(): bool
    {
        return $this->hasError();
    }

    public function clear(): self
    {
        return $this->clearErrors();
    }

    public function last(): ?array
    {
        return $this->getLastError();
    }

    public function handle(\Throwable $e, bool $return = false)
    {
        return $this->handleException($e, $return);
    }

    public function log(\Throwable $e, string $level = 'error'): void
    {
        $this->logException($e, $level);
    }

    public function statusText(int $code): string
    {
        return $this->getHttpStatusText($code);
    }
}

class ErrorHandlerJsonFixture
{
    use ErrorHandler;
    use JsonResponse;

    public function handle(\Throwable $e, bool $return = false)
    {
        return $this->handleException($e, $return);
    }
}

class ErrorHandlerLogStub
{
    /** @var array<int, array{message:string,level:string}> */
    public array $records = [];

    public function record(string $message, string $level = 'info'): void
    {
        $this->records[] = ['message' => $message, 'level' => $level];
    }

    public function log(string $level, string $message): void
    {
        $this->records[] = ['message' => $message, 'level' => $level];
    }
}
