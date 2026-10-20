<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\auth\AuthManager;
use wise\support\auth\GuardInterface;
use wise\support\auth\SessionGuard;
use wise\support\auth\TokenGuard;
use wise\support\context\RequestContext;

/**
 * auth 模块单元测试
 *
 * 覆盖 SessionGuard / TokenGuard 的基础行为，以及 AuthManager 的 guard 解析、用户访问、异常处理。
 */
class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        RequestContext::clear();
        $_SESSION = [];
        $_SERVER = [];
        $_GET = [];
        $_COOKIE = [];

        // 绑定 mock Request 到容器，避免 TokenGuard 触发真实 Request facade 解析
        $request = new class {
            public function header(string $key, string $default = ''): string
            {
                return $default;
            }
            public function param(string $key, string $default = ''): string
            {
                return $default;
            }
            public function cookie(string $key, string $default = ''): string
            {
                return $default;
            }
        };
        \think\Container::getInstance()->bind('request', $request);

        // 绑定 mock Session 到容器：SessionGuard 优先走 session() 辅助函数
        // （其内部解析容器 'session' 门面），无绑定时会抛 ClassNotFoundException。
        // 替身直接读写 $_SESSION，与各用例的 $_SESSION 断言保持一致。
        \think\Container::getInstance()->bind('session', new SessionArrayStub());
    }

    protected function tearDown(): void
    {
        RequestContext::clear();
        $_SESSION = [];
    }

    // ============================================================
    // SessionGuard
    // ============================================================

    public function test_session_guard_default_key_for_admin(): void
    {
        $guard = new SessionGuard('admin');

        $this->assertSame('admin_id', $guard->getSessionKey());
        $this->assertSame('admin', $guard->getName());
    }

    public function test_session_guard_auto_key_for_other_names(): void
    {
        $guard = new SessionGuard('member');

        $this->assertSame('member_id', $guard->getSessionKey());
    }

    public function test_session_guard_set_custom_key(): void
    {
        $guard = new SessionGuard('admin');
        $guard->setSessionKey('custom_id');

        $this->assertSame('custom_id', $guard->getSessionKey());
    }

    public function test_session_guard_user_from_session(): void
    {
        $_SESSION['admin_id'] = 42;
        $guard = new SessionGuard('admin');

        $user = $guard->user();

        $this->assertNotNull($user);
        $this->assertSame(42, $user->id);
    }

    public function test_session_guard_user_returns_null_when_no_session(): void
    {
        $guard = new SessionGuard('admin');

        $this->assertNull($guard->user());
    }

    public function test_session_guard_id_from_user(): void
    {
        $_SESSION['admin_id'] = 7;
        $guard = new SessionGuard('admin');

        $this->assertSame(7, $guard->id());
    }

    public function test_session_guard_id_null_when_guest(): void
    {
        $guard = new SessionGuard('admin');

        $this->assertNull($guard->id());
    }

    public function test_session_guard_check_and_guest(): void
    {
        $guard = new SessionGuard('admin');

        $this->assertFalse($guard->check());
        $this->assertTrue($guard->guest());

        $_SESSION['admin_id'] = 1;
        $guard2 = new SessionGuard('admin');
        $this->assertTrue($guard2->check());
        $this->assertFalse($guard2->guest());
    }

    public function test_session_guard_set_user(): void
    {
        $guard = new SessionGuard('admin');
        $user = (object) ['id' => 99];
        $guard->setUser($user);

        $this->assertSame($user, $guard->user());
        $this->assertSame(99, $guard->id());
    }

    public function test_session_guard_reset_user(): void
    {
        $_SESSION['admin_id'] = 1;
        $guard = new SessionGuard('admin');
        $guard->user();
        $guard->resetUser();

        // reset 后重新从 session 解析
        $this->assertSame(1, $guard->id());
    }

    public function test_session_guard_user_resolver_returns_object(): void
    {
        $guard = new SessionGuard('admin');
        $_SESSION['admin_id'] = 5;
        $guard->setUserResolver(fn($id) => (object) ['id' => $id, 'name' => 'John']);

        $user = $guard->user();

        $this->assertSame(5, $user->id);
        $this->assertSame('John', $user->name);
    }

    public function test_session_guard_user_resolver_null_not_cached(): void
    {
        $guard = new SessionGuard('admin');
        $_SESSION['admin_id'] = 5;
        $callCount = 0;
        $guard->setUserResolver(function ($id) use (&$callCount) {
            $callCount++;
            return null;
        });

        $guard->user();
        $guard->resetUser();
        $guard->user();

        $this->assertSame(2, $callCount);
    }

    // ============================================================
    // TokenGuard
    // ============================================================

    public function test_token_guard_name(): void
    {
        $guard = new TokenGuard('api');

        $this->assertSame('api', $guard->getName());
    }

    public function test_token_guard_set_user_and_id(): void
    {
        $guard = new TokenGuard('api');
        $user = (object) ['id' => 'u_123'];
        $guard->setUser($user);

        $this->assertSame($user, $guard->user());
        $this->assertSame('u_123', $guard->id());
    }

    public function test_token_guard_check_and_guest_when_no_token(): void
    {
        $guard = new TokenGuard('api');

        $this->assertFalse($guard->check());
        $this->assertTrue($guard->guest());
    }

    public function test_token_guard_user_null_when_no_token(): void
    {
        $guard = new TokenGuard('api');

        $this->assertNull($guard->user());
        $this->assertNull($guard->getPayload());
    }

    public function test_token_guard_reset_user(): void
    {
        $guard = new TokenGuard('api');
        $guard->setUser((object) ['id' => 1]);
        $guard->resetUser();

        // reset 后重新解析（无 token → null）
        $this->assertNull($guard->user());
    }

    // ============================================================
    // AuthManager
    // ============================================================

    public function test_auth_manager_default_guard(): void
    {
        $manager = new AuthManager();

        $this->assertSame('admin', $manager->getDefaultGuard());
    }

    public function test_auth_manager_set_default_guard(): void
    {
        $manager = new AuthManager();
        $manager->setDefaultGuard('api');

        $this->assertSame('api', $manager->getDefaultGuard());
    }

    public function test_auth_manager_register_guard_and_user(): void
    {
        $manager = new AuthManager();
        $guard = new StubGuard('admin', (object) ['id' => 10]);
        $manager->registerGuard('admin', $guard);

        $this->assertSame($guard, $manager->guard('admin'));
        $this->assertSame(10, $manager->id());
        $this->assertTrue($manager->check());
        $this->assertFalse($manager->guest());
    }

    public function test_auth_manager_user_sets_context_on_first_resolve(): void
    {
        $manager = new AuthManager();
        $user = (object) ['id' => 10];
        $manager->registerGuard('admin', new StubGuard('admin', $user));

        $result = $manager->user();

        $this->assertSame($user, $result);
        $this->assertSame($user, RequestContext::getUser());
        $this->assertSame('admin', RequestContext::getGuard());
    }

    public function test_auth_manager_user_null_does_not_set_context(): void
    {
        $manager = new AuthManager();
        $manager->registerGuard('admin', new StubGuard('admin', null));

        $result = $manager->user();

        $this->assertNull($result);
        $this->assertNull(RequestContext::getUser());
    }

    public function test_auth_manager_authenticate_returns_user(): void
    {
        $manager = new AuthManager();
        $user = (object) ['id' => 10];
        $manager->registerGuard('admin', new StubGuard('admin', $user));

        $this->assertSame($user, $manager->authenticate());
    }

    public function test_auth_manager_authenticate_throws_when_guest(): void
    {
        $manager = new AuthManager();
        $manager->registerGuard('admin', new StubGuard('admin', null));

        $this->expectException(\wise\support\exception\WiseException::class);
        $this->expectExceptionMessage('请先登录');

        $manager->authenticate();
    }

    public function test_auth_manager_guard_throws_for_unregistered_fallback(): void
    {
        $manager = new AuthManager();

        $this->expectException(\RuntimeException::class);

        $manager->guard('unknown_guard');
    }

    public function test_auth_manager_create_fallback_admin_guard(): void
    {
        $manager = new AuthManager();
        $guard = $manager->guard('admin');

        $this->assertInstanceOf(SessionGuard::class, $guard);
    }

    public function test_auth_manager_create_fallback_api_guard(): void
    {
        $manager = new AuthManager();
        $guard = $manager->guard('api');

        $this->assertInstanceOf(TokenGuard::class, $guard);
    }

    public function test_auth_manager_get_registered_guards(): void
    {
        $manager = new AuthManager();
        $manager->registerGuard('admin', new StubGuard('admin', null));

        $this->assertSame(['admin'], $manager->getRegisteredGuards());
    }

    public function test_auth_manager_clear_cache(): void
    {
        $manager = new AuthManager();
        $manager->registerGuard('admin', new StubGuard('admin', (object) ['id' => 1]));

        $manager->clearCache();

        $this->assertSame([], $manager->getRegisteredGuards());
    }

    public function test_auth_manager_refresh_resets_resolved_flag(): void
    {
        $manager = new AuthManager();
        $guard = new StubGuard('admin', (object) ['id' => 1]);
        $manager->registerGuard('admin', $guard);

        $manager->user(); // 触发解析
        $manager->refresh('admin');

        // refresh 应调用 guard 的 resetUser
        $this->assertTrue($guard->resetCalled);
    }

    public function test_guard_cache_is_dropped_when_request_generation_changes(): void
    {
        $manager = new AuthManager();
        $first   = new StubGuard('admin', (object) ['id' => 1]);
        $manager->registerGuard('admin', $first);

        RequestContext::setRequestId('req-1');
        $this->assertSame($first, $manager->guard('admin'), '同一请求内保留记忆化');

        // 下一个请求（新的 request_id）不得复用上一个请求的 Guard 实例与其 principal 缓存
        RequestContext::setRequestId('req-2');
        $this->assertNotSame($first, $manager->guard('admin'));
    }

    public function test_guard_cache_is_kept_within_same_request(): void
    {
        $manager = new AuthManager();
        $guard   = new StubGuard('admin', (object) ['id' => 1]);
        $manager->registerGuard('admin', $guard);

        RequestContext::setRequestId('req-1');

        $this->assertSame($guard, $manager->guard('admin'));
        $this->assertSame($guard, $manager->guard('admin'));
    }
}

// ============================================================
// 测试辅助类
// ============================================================

class StubGuard implements GuardInterface
{
    public bool $resetCalled = false;

    public function __construct(
        private string $name,
        private ?object $user,
    ) {
    }

    public function user(): ?object
    {
        return $this->user;
    }

    public function id(): string|int|null
    {
        return $this->user?->id ?? null;
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
        $this->resetCalled = true;
    }
}

/**
 * Session 门面替身：直接读写 $_SESSION 超全局变量
 */
class SessionArrayStub
{
    public function get(string $name, mixed $default = null): mixed
    {
        return $_SESSION[$name] ?? $default;
    }

    public function set(string $name, mixed $value): void
    {
        $_SESSION[$name] = $value;
    }

    public function has(string $name): bool
    {
        return isset($_SESSION[$name]);
    }

    public function delete(string $name): void
    {
        unset($_SESSION[$name]);
    }

    public function clear(): void
    {
        $_SESSION = [];
    }

    public function all(): array
    {
        return $_SESSION;
    }
}
