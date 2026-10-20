<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\exception\GeneralErrorCode;
use wise\support\exception\WiseException;

/**
 * WiseException / GeneralErrorCode 单元测试
 *
 * 覆盖统一异常基类的构造、工厂方法、链式构建与输出，
 * 以及框架级通用错误码枚举。
 */
class ExceptionTest extends TestCase
{
    // ============================================================
    // 构造
    // ============================================================

    public function test_construct_with_defaults(): void
    {
        $e = new WiseException();

        $this->assertSame(0, $e->getErrorCode());
        $this->assertSame('', $e->getMessage());
        $this->assertSame(500, $e->getHttpCode());
        $this->assertSame([], $e->getContext());
    }

    public function test_construct_with_full_arguments(): void
    {
        $previous = new \RuntimeException('root cause');
        $e = new WiseException(20001, '支付失败', 400, $previous, ['order_id' => 42]);

        $this->assertSame(20001, $e->getErrorCode());
        $this->assertSame('支付失败', $e->getMessage());
        $this->assertSame(400, $e->getHttpCode());
        $this->assertSame(['order_id' => 42], $e->getContext());
        $this->assertSame($previous, $e->getPrevious());
    }

    public function test_extends_runtime_exception(): void
    {
        $e = new WiseException(1, 'x');

        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertInstanceOf(\Throwable::class, $e);
    }

    // ============================================================
    // 静态工厂
    // ============================================================

    public function test_create_returns_static_instance(): void
    {
        $e = WiseException::create(300, '配置错误', 500);

        $this->assertInstanceOf(WiseException::class, $e);
        $this->assertSame(300, $e->getErrorCode());
        $this->assertSame('配置错误', $e->getMessage());
        $this->assertSame(500, $e->getHttpCode());
    }

    public function test_create_with_previous(): void
    {
        $prev = new \Exception('inner');
        $e = WiseException::create(301, 'DB 错误', 500, $prev);

        $this->assertSame($prev, $e->getPrevious());
    }

    // ============================================================
    // of() — 从错误码枚举创建
    // ============================================================

    /**
     * `of()` 不传 httpCode 时**按错误码推导**：业务码 → 200
     *
     * 2026-09-27 定案：业务错误统一 HTTP 200 + `code != 0`（与 `JsonResponse::error()` 同一套方案）。
     */
    public function test_of_derives_http_code_from_error_code(): void
    {
        $e = WiseException::of(TestErrorCode::PAY_SIGN_INVALID);

        $this->assertSame(2201, $e->getErrorCode());
        $this->assertSame('支付签名验证失败', $e->getMessage());
        $this->assertSame(200, $e->getHttpCode());
    }

    public function test_of_with_custom_message(): void
    {
        $e = WiseException::of(TestErrorCode::PAY_SIGN_INVALID, '自定义提示', 422);

        $this->assertSame(2201, $e->getErrorCode());
        $this->assertSame('自定义提示', $e->getMessage());
        $this->assertSame(422, $e->getHttpCode());
    }

    public function test_of_with_context(): void
    {
        $e = WiseException::of(
            TestErrorCode::PAY_SIGN_INVALID,
            null,
            null,
            null,
            ['field' => 'email']
        );

        $this->assertSame(['field' => 'email'], $e->getContext());
    }

    public function test_of_with_previous(): void
    {
        $prev = new \RuntimeException('inner');
        $e = WiseException::of(TestErrorCode::PAY_SIGN_INVALID, null, null, $prev);

        $this->assertSame($prev, $e->getPrevious());
    }

    // ============================================================
    // 链式构建
    // ============================================================

    public function test_with_context_appends_single_value(): void
    {
        $e = (new WiseException(1, 'x'))->withContext('order_id', 42);

        $this->assertSame(['order_id' => 42], $e->getContext());
    }

    public function test_with_context_is_chainable(): void
    {
        $e = new WiseException(1, 'x');
        $result = $e->withContext('a', 1);

        $this->assertSame($e, $result);
    }

    public function test_with_context_many_merges(): void
    {
        $e = (new WiseException(1, 'x'))
            ->withContext('a', 1)
            ->withContextMany(['b' => 2, 'c' => 3]);

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $e->getContext());
    }

    public function test_with_context_many_overrides_existing_key(): void
    {
        $e = (new WiseException(1, 'x'))
            ->withContext('a', 1)
            ->withContextMany(['a' => 100]);

        $this->assertSame(['a' => 100], $e->getContext());
    }

    // ============================================================
    // toArray
    // ============================================================

    public function test_to_array_without_context(): void
    {
        $e = new WiseException(101, '请先登录', 401);

        $this->assertSame([
            'code' => 101,
            'msg'  => '请先登录',
        ], $e->toArray());
    }

    public function test_to_array_with_context(): void
    {
        $e = (new WiseException(200, '校验失败', 400))
            ->withContext('errors', ['name' => 'required']);

        $this->assertSame([
            'code' => 200,
            'msg'  => '校验失败',
            'data' => ['errors' => ['name' => 'required']],
        ], $e->toArray());
    }

    public function test_diagnostic_context_is_not_exposed_by_to_array(): void
    {
        $e = (new WiseException(200, '校验失败', 400))
            ->withContext('field', 'name')
            ->withDiagnosticContext('sql', 'select * from users where token = ...')
            ->withDiagnosticContextMany(['upstream' => 'pay-service', 'trace_id' => 't-1']);

        // 诊断上下文只进日志，绝不进入响应体结构
        $this->assertSame([
            'code' => 200,
            'msg'  => '校验失败',
            'data' => ['field' => 'name'],
        ], $e->toArray());

        $this->assertSame([
            'sql'      => 'select * from users where token = ...',
            'upstream' => 'pay-service',
            'trace_id' => 't-1',
        ], $e->getDiagnosticContext());
    }

    public function test_get_context_returns_only_public_context(): void
    {
        $e = (new WiseException(200, 'x'))->withDiagnosticContext('secret', 's');

        $this->assertSame([], $e->getContext());
        $this->assertSame(['secret' => 's'], $e->getDiagnosticContext());
    }

    // ============================================================
    // 类常量
    // ============================================================

    public function test_class_constants(): void
    {
        $this->assertSame(0, WiseException::CODE_SUCCESS);
        $this->assertSame(1, WiseException::CODE_UNKNOWN);
    }

    // ============================================================
    // GeneralErrorCode 枚举
    // ============================================================

    public function test_general_error_code_values(): void
    {
        $this->assertSame(100, GeneralErrorCode::BAD_REQUEST->value);
        $this->assertSame(101, GeneralErrorCode::UNAUTHORIZED->value);
        $this->assertSame(102, GeneralErrorCode::FORBIDDEN->value);
        $this->assertSame(103, GeneralErrorCode::NOT_FOUND->value);
        $this->assertSame(104, GeneralErrorCode::METHOD_NOT_ALLOWED->value);
        $this->assertSame(105, GeneralErrorCode::TOO_MANY_REQUESTS->value);
        $this->assertSame(150, GeneralErrorCode::SERVER_ERROR->value);
        $this->assertSame(151, GeneralErrorCode::SERVICE_UNAVAILABLE->value);
    }

    public function test_general_error_code_validation_values(): void
    {
        $this->assertSame(200, GeneralErrorCode::VALIDATION_FAILED->value);
        $this->assertSame(201, GeneralErrorCode::REQUIRED_FIELD->value);
        $this->assertSame(202, GeneralErrorCode::INVALID_FORMAT->value);
    }

    public function test_general_error_code_infra_values(): void
    {
        $this->assertSame(300, GeneralErrorCode::CONFIG_ERROR->value);
        $this->assertSame(301, GeneralErrorCode::DB_ERROR->value);
        $this->assertSame(302, GeneralErrorCode::CACHE_ERROR->value);
        $this->assertSame(303, GeneralErrorCode::IO_ERROR->value);
        $this->assertSame(304, GeneralErrorCode::NETWORK_ERROR->value);
        $this->assertSame(305, GeneralErrorCode::THIRD_PARTY_ERROR->value);
    }

    public function test_general_error_code_is_backed_enum(): void
    {
        $this->assertInstanceOf(\BackedEnum::class, GeneralErrorCode::BAD_REQUEST);
    }

    public function test_general_error_code_from_value(): void
    {
        $this->assertSame(
            GeneralErrorCode::NOT_FOUND,
            GeneralErrorCode::from(103)
        );
    }
}

// ============================================================
// 测试辅助枚举（实现 ErrorCodeInterface）
// ============================================================

enum TestErrorCode: int implements \wise\support\exception\ErrorCodeInterface
{
    case PAY_SIGN_INVALID = 2201;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::PAY_SIGN_INVALID => '支付签名验证失败',
        };
    }
}
