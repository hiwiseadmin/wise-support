<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use think\Container;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\auth\AuthManager;
use wise\support\auth\GuardInterface;
use wise\support\context\RequestContext;
use wise\support\facade\Auth;
use wise\support\facade\Context;
use wise\support\facade\JWT;
use wise\support\jwt\JwtManager;

/**
 * 门面（Auth / Context / JWT）单元测试
 */
class FacadeTest extends TestCase
{
    protected function setUp(): void
    {
        ExtPoint::reset();
        RequestContext::clear();
    }

    protected function tearDown(): void
    {
        ExtPoint::reset();
        RequestContext::clear();
        Container::getInstance()->delete(AuthManager::class);
        Container::getInstance()->delete('auth');
        Container::getInstance()->delete('jwt');
    }

    private function facadeClass(string $facade): string
    {
        $method = new \ReflectionMethod($facade, 'getFacadeClass');
        $method->setAccessible(true);
        return $method->invoke(null);
    }

    // ============================================================
    // getFacadeClass 映射
    // ============================================================

    public function test_facade_class_mappings(): void
    {
        $this->assertSame(AuthManager::class, $this->facadeClass(Auth::class));
        $this->assertSame(RequestContext::class, $this->facadeClass(Context::class));
        $this->assertSame('jwt', $this->facadeClass(JWT::class));
    }

    // ============================================================
    // Auth 门面
    // ============================================================

    public function test_auth_facade_default_guard(): void
    {
        $this->assertSame('admin', Auth::getDefaultGuard());
    }

    public function test_auth_facade_set_default_guard_returns_manager(): void
    {
        $result = Auth::setDefaultGuard('api');

        $this->assertInstanceOf(AuthManager::class, $result);
        $this->assertSame('api', Auth::getDefaultGuard());
    }

    public function test_auth_facade_register_guard_and_list(): void
    {
        Auth::registerGuard('custom', new FacadeGuardStub('custom'));

        $this->assertSame(['custom'], Auth::getRegisteredGuards());
    }

    public function test_auth_facade_check_and_guest_with_registered_guard(): void
    {
        Auth::registerGuard('api', new FacadeGuardStub('api', (object) ['id' => 5]));

        $this->assertTrue(Auth::check('api'));
        $this->assertFalse(Auth::guest('api'));
    }

    public function test_auth_facade_authenticate_returns_user(): void
    {
        $user = (object) ['id' => 11];
        Auth::registerGuard('api', new FacadeGuardStub('api', $user));

        $this->assertSame($user, Auth::authenticate('api'));
    }

    // ============================================================
    // Context 门面
    // ============================================================

    public function test_context_facade_delegates_to_request_context(): void
    {
        Context::set('locale', 'zh-CN');

        $this->assertTrue(Context::has('locale'));
        $this->assertSame('zh-CN', Context::get('locale'));
        $this->assertSame('zh-CN', RequestContext::get('locale'));

        Context::remove('locale');
        $this->assertFalse(Context::has('locale'));
        $this->assertSame('fallback', Context::get('locale', 'fallback'));
    }

    public function test_context_facade_set_many_and_all(): void
    {
        Context::setMany(['a' => 1, 'b' => 2]);

        $all = Context::all();
        $this->assertSame(1, $all['a']);
        $this->assertSame(2, $all['b']);
    }

    public function test_context_facade_request_id_roundtrip(): void
    {
        Context::setRequestId('rid-9');

        $this->assertSame('rid-9', Context::getRequestId());
        $this->assertSame(['request_id' => 'rid-9', 'guard' => 'admin'], Context::logContext());
    }

    public function test_context_facade_user_and_guard(): void
    {
        Context::setUser((object) ['id' => 3]);
        Context::setGuard('member');

        $this->assertSame(3, Context::getUserId());
        $this->assertSame('member', Context::getGuard());
        $this->assertNotNull(Context::getUser());
    }

    public function test_context_facade_clear(): void
    {
        Context::set('x', 1);
        Context::clear();

        $this->assertSame([], Context::all());
    }

    // ============================================================
    // JWT 门面
    // ============================================================

    public function test_jwt_facade_proxies_to_registered_engine(): void
    {
        ExtPoint::definePoint(ExtPoints::JWT_PROVIDER, ExtPoint::POLICY_SINGLE);
        $engine = new FacadeJwtEngineStub();
        ExtPoint::register(ExtPoints::JWT_PROVIDER, 'engine', fn() => $engine);

        Container::getInstance()->bind('jwt', new JwtManager());

        $this->assertSame('token-42', JWT::issue(42));
        $this->assertTrue(JWT::revoke('token-42'));
        $this->assertSame([[42]], $engine->calls['issue']);
    }

    public function test_jwt_facade_surfaces_missing_engine(): void
    {
        Container::getInstance()->bind('jwt', new JwtManager());

        $this->expectException(\wise\support\exception\WiseException::class);
        JWT::issue(1);
    }
}

// ============================================================
// 测试替身
// ============================================================

class FacadeGuardStub implements GuardInterface
{
    public function __construct(private string $name, private ?object $user = null)
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

class FacadeJwtEngineStub
{
    /** @var array<string, array<int, array>> */
    public array $calls = [];

    public function issue(int|string $subject): string
    {
        $this->calls['issue'][] = [$subject];
        return 'token-' . $subject;
    }

    public function revoke(string $token): bool
    {
        $this->calls['revoke'][] = [$token];
        return true;
    }
}
