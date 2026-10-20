<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use think\Container;
use wise\support\Once;
use wise\support\Pipeline;
use wise\support\auth\AuthManager;
use wise\support\auth\GuardInterface;
use wise\support\context\RequestContext;
use wise\support\exception\ErrorCodeInterface;
use wise\support\exception\GeneralErrorCode;
use wise\support\exception\WiseException;
use wise\support\facade\Auth;
use wise\support\rate\RateLimiter;

/**
 * src/helper.php 全局辅助函数单元测试
 */
class HelperFunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        RequestContext::clear();
        Once::flush();
    }

    protected function tearDown(): void
    {
        RequestContext::clear();
        Once::flush();
        Container::getInstance()->delete(AuthManager::class);
    }

    // ============================================================
    // str_pipeline / rate_limiter
    // ============================================================

    public function test_str_pipeline_with_passable(): void
    {
        $result = str_pipeline([1, 2, 3])
            ->through([fn(array $data, callable $next) => $next(array_map(fn($n) => $n * 2, $data))])
            ->then(fn(array $data) => array_sum($data));

        $this->assertSame(12, $result);
    }

    public function test_str_pipeline_without_passable(): void
    {
        $pipeline = str_pipeline();

        $this->assertInstanceOf(Pipeline::class, $pipeline);
        $this->assertSame('x', $pipeline->send('x')->through([])->then(fn($v) => $v));
    }

    public function test_rate_limiter_helper(): void
    {
        $limiter = rate_limiter(10, 300);

        $this->assertInstanceOf(RateLimiter::class, $limiter);
    }

    // ============================================================
    // array_only_dot
    // ============================================================

    public function test_array_only_dot_extracts_nested_keys(): void
    {
        $source = [
            'user' => [
                'name'    => 'tom',
                'profile' => ['age' => 18],
            ],
            'top'  => 1,
        ];

        $result = array_only_dot($source, ['user.name', 'user.profile.age', 'top']);

        $this->assertSame(['name' => 'tom', 'age' => 18, 'top' => 1], $result);
    }

    public function test_array_only_dot_skips_missing_nested_keys(): void
    {
        $result = array_only_dot(['user' => ['name' => 'tom']], ['user.missing', 'nope.deep.key']);

        $this->assertSame([], $result);
    }

    public function test_array_only_dot_top_level_missing_returns_null(): void
    {
        $result = array_only_dot([], ['absent']);

        $this->assertSame(['absent' => null], $result);
    }

    public function test_array_only_dot_keeps_existing_null_value(): void
    {
        $result = array_only_dot(['a' => ['b' => null]], ['a.b']);

        $this->assertSame(['b' => null], $result);
    }

    // ============================================================
    // once()
    // ============================================================

    public function test_once_memoizes_by_explicit_key(): void
    {
        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;
            return 'value-' . $calls;
        };

        $this->assertSame('value-1', once($callback, 'helper.once.key'));
        $this->assertSame('value-1', once($callback, 'helper.once.key'));
        $this->assertSame(1, $calls);
    }

    public function test_once_forget_forces_recalculation(): void
    {
        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;
            return $calls;
        };

        once($callback, 'helper.once.forget');
        Once::forget($callback, 'helper.once.forget');

        $this->assertSame(2, once($callback, 'helper.once.forget'));
    }

    // ============================================================
    // request_id() / context()
    // ============================================================

    public function test_request_id_returns_unknown_by_default(): void
    {
        $this->assertSame('unknown', request_id());
    }

    public function test_request_id_returns_configured_value(): void
    {
        RequestContext::setRequestId('rid-1');

        $this->assertSame('rid-1', request_id());
    }

    public function test_context_helper_reads_and_writes(): void
    {
        context(); // 无参数时返回全部上下文（此处仅为覆盖分支）
        $all = context();
        $this->assertIsArray($all);

        RequestContext::set('locale', 'zh-CN');

        $this->assertSame('zh-CN', context('locale'));
        $this->assertSame('fallback', context('missing', 'fallback'));
    }

    // ============================================================
    // wex()
    // ============================================================

    public function test_wex_uses_default_message_from_error_code(): void
    {
        $exception = wex(HelperTestErrorCode::NOT_FOUND, '', 404, ['resource' => 'order']);

        $this->assertInstanceOf(WiseException::class, $exception);
        $this->assertSame(HelperTestErrorCode::NOT_FOUND->value, $exception->getErrorCode());
        $this->assertSame(HelperTestErrorCode::NOT_FOUND->defaultMessage(), $exception->getMessage());
        $this->assertSame(404, $exception->getHttpCode());
        $this->assertSame(['resource' => 'order'], $exception->getContext());
    }

    public function test_wex_prefers_explicit_message(): void
    {
        $exception = wex(HelperTestErrorCode::BAD_REQUEST, '参数不合法');

        $this->assertSame('参数不合法', $exception->getMessage());
        $this->assertSame(500, $exception->getHttpCode());
        $this->assertSame([], $exception->getContext());
    }

    /**
     * GeneralErrorCode 作为框架级错误码枚举，必须可直接被 wex() 消费
     */
    public function test_general_error_code_can_be_passed_to_wex(): void
    {
        $this->assertTrue(is_subclass_of(GeneralErrorCode::class, ErrorCodeInterface::class));

        $exception = wex(GeneralErrorCode::NOT_FOUND);

        $this->assertSame(GeneralErrorCode::NOT_FOUND->value, $exception->getErrorCode());
        $this->assertSame('资源不存在', $exception->getMessage());
        $this->assertSame([], $exception->getContext());
    }

    public function test_general_error_code_works_with_wise_exception_factory(): void
    {
        $exception = WiseException::of(GeneralErrorCode::BAD_REQUEST);

        $this->assertSame(GeneralErrorCode::BAD_REQUEST->value, $exception->getErrorCode());
        $this->assertSame('请求参数错误', $exception->getMessage());
        $this->assertSame(400, $exception->getHttpCode());
    }

    public function test_every_general_error_code_has_default_message(): void
    {
        foreach (GeneralErrorCode::cases() as $case) {
            $this->assertNotSame('', $case->defaultMessage(), "{$case->name} 缺少默认说明");
        }
    }

    // ============================================================
    // auth_user() / auth_id()
    // ============================================================

    public function test_auth_user_and_id_use_default_guard(): void
    {
        $user = (object) ['id' => 42];
        Auth::registerGuard('admin', new HelperGuardStub('admin', $user));

        $this->assertSame($user, auth_user());
        $this->assertSame(42, auth_id());
    }

    public function test_auth_helpers_return_null_for_guest(): void
    {
        Auth::registerGuard('admin', new HelperGuardStub('admin', null));

        $this->assertNull(auth_user());
        $this->assertNull(auth_id());
    }

    public function test_auth_helpers_accept_guard_name(): void
    {
        Auth::registerGuard('api', new HelperGuardStub('api', (object) ['id' => 7]));

        $this->assertSame(7, auth_id('api'));
    }
}

// ============================================================
// 测试替身
// ============================================================

/**
 * 测试用错误码枚举（实现 ErrorCodeInterface）
 */
enum HelperTestErrorCode: int implements ErrorCodeInterface
{
    case BAD_REQUEST = 9001;
    case NOT_FOUND   = 9002;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::BAD_REQUEST => '请求参数错误',
            self::NOT_FOUND   => '资源不存在',
        };
    }
}

class HelperGuardStub implements GuardInterface
{
    public function __construct(private string $name, private ?object $user)
    {
    }

    public function user(): ?object
    {
        return $this->user;
    }

    public function id(): string|int|null
    {
        return $this->user->id ?? null;
    }

    public function check(): bool
    {
        return $this->user !== null;
    }

    public function guest(): bool
    {
        return $this->user === null;
    }

    public function setUser(object $user): void
    {
        $this->user = $user;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function resetUser(): void
    {
    }
}
