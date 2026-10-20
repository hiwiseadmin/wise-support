<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use think\Container;
use think\facade\Config;
use wise\support\exception\DefaultExceptionRenderer;
use wise\support\exception\ExceptionRendererInterface;
use wise\support\exception\GeneralErrorCode;
use wise\support\exception\WiseException;

/**
 * 默认异常渲染器 DefaultExceptionRenderer 单元测试
 */
class DefaultExceptionRendererTest extends TestCase
{
    private DefaultExceptionRenderer $renderer;

    protected function setUp(): void
    {
        new \think\App(dirname(__DIR__, 5));
        Config::set(['debug' => false], 'app');

        $this->renderer = new DefaultExceptionRenderer();
    }

    protected function tearDown(): void
    {
        Container::getInstance()->delete('request');
    }

    /**
     * 构造并绑定一个可控的 Request 实例（用于 IP 白名单判定）
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

    public function test_implements_renderer_interface(): void
    {
        $this->assertInstanceOf(ExceptionRendererInterface::class, $this->renderer);
    }

    // ============================================================
    // errorCodeToHttpCode()
    // ============================================================

    public function test_success_and_unknown_codes_fall_back_to_500(): void
    {
        $this->assertSame(500, DefaultExceptionRenderer::errorCodeToHttpCode(WiseException::CODE_SUCCESS));
        $this->assertSame(500, DefaultExceptionRenderer::errorCodeToHttpCode(WiseException::CODE_UNKNOWN));
    }

    public function test_http_semantic_codes_are_mapped(): void
    {
        $cases = [
            GeneralErrorCode::BAD_REQUEST->value         => 400,
            GeneralErrorCode::UNAUTHORIZED->value        => 401,
            GeneralErrorCode::FORBIDDEN->value           => 403,
            GeneralErrorCode::NOT_FOUND->value           => 404,
            GeneralErrorCode::METHOD_NOT_ALLOWED->value  => 405,
            GeneralErrorCode::TOO_MANY_REQUESTS->value   => 429,
            GeneralErrorCode::SERVER_ERROR->value        => 500,
            GeneralErrorCode::SERVICE_UNAVAILABLE->value => 503,
        ];

        foreach ($cases as $code => $expected) {
            $this->assertSame($expected, DefaultExceptionRenderer::errorCodeToHttpCode($code), "code={$code}");
        }
    }

    /**
     * 校验类（200~299）是**业务错误** → 200
     *
     * 2026-09-27 定案：业务错误统一 HTTP 200 + `code != 0`（与 `JsonResponse::error()` 同一套方案），
     * 4xx/5xx 只留给传输/鉴权/限流/基础设施故障。
     */
    public function test_validation_codes_map_to_200(): void
    {
        $this->assertSame(200, DefaultExceptionRenderer::errorCodeToHttpCode(200));
        $this->assertSame(200, DefaultExceptionRenderer::errorCodeToHttpCode(250));
        $this->assertSame(200, DefaultExceptionRenderer::errorCodeToHttpCode(299));
    }

    public function test_infrastructure_codes_map_to_500(): void
    {
        $this->assertSame(500, DefaultExceptionRenderer::errorCodeToHttpCode(300));
        $this->assertSame(500, DefaultExceptionRenderer::errorCodeToHttpCode(399));
    }

    public function test_business_codes_map_to_200(): void
    {
        $this->assertSame(200, DefaultExceptionRenderer::errorCodeToHttpCode(400));
        $this->assertSame(200, DefaultExceptionRenderer::errorCodeToHttpCode(1000));
        $this->assertSame(200, DefaultExceptionRenderer::errorCodeToHttpCode(9999));
    }

    public function test_package_segment_codes_map_to_200(): void
    {
        // wise-* 包号段（10000 起）默认按业务处理；要 4xx/5xx 的由异常显式指定
        $this->assertSame(200, DefaultExceptionRenderer::errorCodeToHttpCode(10000));
        $this->assertSame(200, DefaultExceptionRenderer::errorCodeToHttpCode(50000));
    }

    // ============================================================
    // render()
    // ============================================================

    public function test_render_wise_exception_uses_to_array(): void
    {
        $exception = new WiseException(1001, '参数错误', 422, null, ['field' => 'amount']);

        $this->assertSame(
            ['code' => 1001, 'msg' => '参数错误', 'data' => ['field' => 'amount']],
            $this->renderer->render($exception)
        );
    }

    public function test_render_wise_exception_without_context(): void
    {
        $this->assertSame(
            ['code' => 1002, 'msg' => '无上下文'],
            $this->renderer->render(new WiseException(1002, '无上下文', 400))
        );
    }

    public function test_render_generic_hides_details_in_production(): void
    {
        $result = $this->renderer->render(new \RuntimeException('internal detail'));

        $this->assertSame(WiseException::CODE_UNKNOWN, $result['code']);
        $this->assertSame('服务器内部错误', $result['msg']);
        $this->assertArrayNotHasKey('data', $result);
    }

    public function test_render_generic_keeps_exception_code(): void
    {
        $result = $this->renderer->render(new \RuntimeException('x', 1234));

        $this->assertSame(1234, $result['code']);
    }

    public function test_render_generic_hides_details_when_debug_without_allowlist(): void
    {
        // app.debug 为真但调用方 IP 不在白名单 → 仍必须隐藏内部细节
        Config::set(['debug' => true, 'debug_allow_ips' => []], 'app');
        $this->bindRequest('9.9.9.9');

        $result = $this->renderer->render(new \RuntimeException('internal detail'));

        $this->assertSame('服务器内部错误', $result['msg']);
        $this->assertArrayNotHasKey('data', $result);
    }

    public function test_render_generic_exposes_details_when_ip_allowlisted(): void
    {
        Config::set(['debug' => true, 'debug_allow_ips' => ['9.9.9.9']], 'app');
        $this->bindRequest('9.9.9.9');

        $exception = new \RuntimeException('internal detail');
        $result    = $this->renderer->render($exception);

        $this->assertSame('internal detail', $result['msg']);
        $this->assertSame(\RuntimeException::class, $result['data']['exception']);
        $this->assertSame($exception->getFile(), $result['data']['file']);
        $this->assertSame($exception->getLine(), $result['data']['line']);
    }

    public function test_debug_disclosure_requires_app_debug_even_with_authorization(): void
    {
        // 唯一披露判定：app.debug 为假时，即使调用方声明已授权也必须拒绝
        Config::set(['debug' => false], 'app');

        $this->assertFalse(DefaultExceptionRenderer::isDebugDisclosureAllowed(true));
        Config::set(['debug' => true, 'debug_allow_ips' => []], 'app');
        $this->assertTrue(DefaultExceptionRenderer::isDebugDisclosureAllowed(true));
    }
}
