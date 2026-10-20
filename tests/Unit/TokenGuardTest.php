<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use think\Container;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\auth\TokenGuard;
use wise\support\exception\GeneralErrorCode;
use wise\support\exception\WiseException;

/**
 * Token（JWT）认证守卫 TokenGuard 单元测试
 */
class TokenGuardTest extends TestCase
{
    private TokenGuardRequestStub $request;

    protected function setUp(): void
    {
        ExtPoint::reset();

        $this->request = new TokenGuardRequestStub();
        Container::getInstance()->bind('request', $this->request);
        Container::getInstance()->bind('log', new TokenLogStub());
    }

    protected function tearDown(): void
    {
        ExtPoint::reset();
        Container::getInstance()->delete('request');
        Container::getInstance()->delete('log');
    }

    private function registerEngine(object $engine): void
    {
        ExtPoint::definePoint(ExtPoints::JWT_PROVIDER, ExtPoint::POLICY_SINGLE);
        ExtPoint::register(ExtPoints::JWT_PROVIDER, 'engine', fn() => $engine);
    }

    private function bearer(string $token = 'jwt-token'): void
    {
        $this->request->headers['Authorization'] = 'Bearer ' . $token;
    }

    // ============================================================
    // Token 提取
    // ============================================================

    public function test_user_is_null_without_authorization_header(): void
    {
        $this->registerEngine(new TokenJwtEngineStub());

        $guard = new TokenGuard('api');

        $this->assertNull($guard->user());
        $this->assertNull($guard->id());
        $this->assertFalse($guard->check());
        $this->assertTrue($guard->guest());
    }

    public function test_non_bearer_header_is_ignored(): void
    {
        $this->registerEngine(new TokenJwtEngineStub());
        $this->request->headers['Authorization'] = 'Basic abc';

        $this->assertNull((new TokenGuard('api'))->user());
    }

    public function test_bearer_token_is_parsed_and_user_resolved(): void
    {
        $engine = new TokenJwtEngineStub();
        $engine->payload = ['sub' => 42, 'jti' => 'j1'];
        $this->registerEngine($engine);
        $this->bearer('my-token');

        $guard = new TokenGuard('api');
        $seenPayload = null;
        $guard->setUserResolver(function (array $payload) use (&$seenPayload) {
            $seenPayload = $payload;
            return (object) ['id' => $payload['sub'], 'name' => 'tom'];
        });

        $user = $guard->user();

        $this->assertSame(42, $user->id);
        $this->assertSame('tom', $user->name);
        $this->assertSame(['sub' => 42, 'jti' => 'j1'], $seenPayload);
        $this->assertSame([['my-token', null]], $engine->parsedTokens);
        $this->assertTrue($guard->check());
        $this->assertSame(42, $guard->id());
    }

    // ============================================================
    // guard 绑定 + 引擎故障区分
    // ============================================================

    public function test_guard_name_is_passed_to_engine_provider(): void
    {
        $seen = [];
        ExtPoint::definePoint(ExtPoints::JWT_PROVIDER, ExtPoint::POLICY_SINGLE);
        ExtPoint::register(ExtPoints::JWT_PROVIDER, 'engine', function (?string $guard = null) use (&$seen) {
            $seen[] = $guard;
            $engine = new TokenJwtEngineStub();
            $engine->payload = ['sub' => 1];
            return $engine;
        });
        $this->bearer();

        $this->assertNotNull((new TokenGuard('member'))->user());

        // 守卫名必须到达 provider，使其能按 guard 选择密钥 / issuer / audience
        $this->assertSame(['member'], $seen);
    }

    public function test_engine_unavailable_is_not_latched_as_guest(): void
    {
        $this->bearer();

        $guard = new TokenGuard('api');

        // 引擎未注册：本次是「无法判定」，不是「确定未登录」
        $this->assertNull($guard->user());

        // 引擎恢复后同一实例必须重新解析，不得把结果锁存为访客
        $engine = new TokenJwtEngineStub();
        $engine->payload = ['sub' => 42];
        $this->registerEngine($engine);

        $this->assertSame(42, $guard->user()->id);
    }

    public function test_engine_unavailable_fails_closed_when_opted_in(): void
    {
        $this->bearer();

        $guard = (new TokenGuard('api'))->failClosedOnEngineUnavailable();

        try {
            $guard->user();
            $this->fail('引擎不可用且启用 fail-closed 时应抛出异常');
        } catch (WiseException $e) {
            $this->assertSame(GeneralErrorCode::SERVICE_UNAVAILABLE->value, $e->getErrorCode());
            $this->assertSame(503, $e->getHttpCode());
        }
    }

    public function test_default_user_object_uses_sub_and_jti(): void
    {
        $engine = new TokenJwtEngineStub();
        $engine->payload = ['sub' => 'u-9', 'jti' => 'token-id'];
        $this->registerEngine($engine);
        $this->bearer();

        $user = (new TokenGuard('api'))->user();

        $this->assertSame('u-9', $user->id);
        $this->assertSame('token-id', $user->jti);
    }

    public function test_default_user_object_without_claims(): void
    {
        $engine = new TokenJwtEngineStub();
        $engine->payload = [];
        $this->registerEngine($engine);
        $this->bearer();

        $user = (new TokenGuard('api'))->user();

        $this->assertNull($user->id);
        $this->assertNull($user->jti);
    }

    public function test_resolver_returning_null_is_not_cached(): void
    {
        $engine = new TokenJwtEngineStub();
        $this->registerEngine($engine);
        $this->bearer();

        $calls = 0;
        $guard = new TokenGuard('api');
        $guard->setUserResolver(function () use (&$calls) {
            $calls++;
            return null;
        });

        $this->assertNull($guard->user());
        $guard->resetUser();
        $this->assertNull($guard->user());

        $this->assertSame(2, $calls);
    }

    public function test_get_payload_triggers_resolution_and_caches(): void
    {
        $engine = new TokenJwtEngineStub();
        $engine->payload = ['sub' => 1];
        $this->registerEngine($engine);
        $this->bearer();

        $guard = new TokenGuard('api');

        $this->assertSame(['sub' => 1], $guard->getPayload());
        // 已解析后再取 payload 不应重复解析
        $this->assertSame(['sub' => 1], $guard->getPayload());
        $this->assertCount(1, $engine->parsedTokens);
    }

    // ============================================================
    // query / cookie 通道（默认关闭）
    // ============================================================

    public function test_query_and_cookie_tokens_are_ignored_by_default(): void
    {
        $engine = new TokenJwtEngineStub();
        $this->registerEngine($engine);
        $this->request->params['token']  = 'query-token';
        $this->request->cookies['token'] = 'cookie-token';

        $this->assertNull((new TokenGuard('api'))->user());
        $this->assertSame([], $engine->parsedTokens);
    }

    public function test_query_token_is_used_when_explicitly_allowed(): void
    {
        $engine = new TokenJwtEngineStub();
        $this->registerEngine($engine);
        $this->request->params['token'] = 'query-token';

        $user = (new TokenGuard('api'))->allowParamToken()->user();

        $this->assertNotNull($user);
        $this->assertSame([['query-token', null]], $engine->parsedTokens);
    }

    public function test_cookie_token_is_used_when_explicitly_allowed(): void
    {
        $engine = new TokenJwtEngineStub();
        $this->registerEngine($engine);
        $this->request->cookies['token'] = 'cookie-token';

        $user = (new TokenGuard('api'))->allowParamToken(true)->user();

        $this->assertNotNull($user);
        $this->assertSame([['cookie-token', null]], $engine->parsedTokens);
    }

    public function test_authorization_header_wins_over_query_token(): void
    {
        $engine = new TokenJwtEngineStub();
        $this->registerEngine($engine);
        $this->request->params['token'] = 'query-token';
        $this->bearer('header-token');

        (new TokenGuard('api'))->allowParamToken()->user();

        $this->assertSame([['header-token', null]], $engine->parsedTokens);
    }

    public function test_allow_param_token_is_chainable(): void
    {
        $guard = new TokenGuard('api');

        $this->assertSame($guard, $guard->allowParamToken());
        $this->assertSame($guard, $guard->autoSetContext(false));
        $this->assertSame($guard, $guard->setUserResolver(fn() => null));
    }

    // ============================================================
    // JWT 引擎交互
    // ============================================================

    public function test_user_is_null_when_engine_not_registered(): void
    {
        $this->bearer();

        $this->assertNull((new TokenGuard('api'))->user());
    }

    public function test_user_is_null_when_try_parse_returns_null(): void
    {
        $engine = new TokenJwtEngineStub();
        $engine->returnNull = true;
        $this->registerEngine($engine);
        $this->bearer();

        $this->assertNull((new TokenGuard('api'))->user());
    }

    public function test_parse_method_is_used_when_try_parse_missing(): void
    {
        $engine = new TokenParseOnlyEngineStub();
        $this->registerEngine($engine);
        $this->bearer('legacy-token');

        $user = (new TokenGuard('api'))->user();

        $this->assertSame(7, $user->id);
        $this->assertSame(['legacy-token'], $engine->parsed);
    }

    public function test_user_is_null_when_engine_lacks_parse_methods(): void
    {
        $this->registerEngine(new TokenNoParseEngineStub());
        $this->bearer();

        $this->assertNull((new TokenGuard('api'))->user());
    }

    public function test_engine_exception_is_swallowed(): void
    {
        $engine = new TokenJwtEngineStub();
        $engine->throwOnParse = true;
        $this->registerEngine($engine);
        $this->bearer();

        $this->assertNull((new TokenGuard('api'))->user());
    }

    // ============================================================
    // 其他
    // ============================================================

    public function test_set_user_short_circuits_resolution(): void
    {
        $guard = new TokenGuard('api');
        $user  = (object) ['id' => 99];
        $guard->setUser($user);

        $this->assertSame($user, $guard->user());
        $this->assertSame(99, $guard->id());
        $this->assertSame('api', $guard->getName());
    }

    public function test_reset_user_clears_payload(): void
    {
        $engine = new TokenJwtEngineStub();
        $engine->payload = ['sub' => 5];
        $this->registerEngine($engine);
        $this->bearer();

        $guard = new TokenGuard('api');
        $guard->user();
        $this->assertSame(['sub' => 5], $guard->getPayload());

        $guard->resetUser();

        $this->assertCount(1, $engine->parsedTokens, 'reset 后 getPayload 会重新解析');
        $this->assertSame(['sub' => 5], $guard->getPayload());
        $this->assertCount(2, $engine->parsedTokens);
    }

    public function test_guard_implements_guard_interface(): void
    {
        $this->assertInstanceOf(\wise\support\auth\GuardInterface::class, new TokenGuard('api'));
    }
}

// ============================================================
// 测试替身
// ============================================================

class TokenGuardRequestStub
{
    /** @var array<string,string> */
    public array $headers = [];
    /** @var array<string,string> */
    public array $params = [];
    /** @var array<string,string> */
    public array $cookies = [];

    public function header(string $key, string $default = ''): string
    {
        return $this->headers[$key] ?? $default;
    }

    public function param(string $key, string $default = ''): string
    {
        return $this->params[$key] ?? $default;
    }

    public function cookie(string $key, string $default = ''): string
    {
        return $this->cookies[$key] ?? $default;
    }
}

class TokenJwtEngineStub
{
    public array $payload = ['sub' => 1];
    public bool $returnNull = false;
    public bool $throwOnParse = false;
    /** @var array<int, array{0:string,1:mixed}> */
    public array $parsedTokens = [];

    public function tryParse(string $token): ?array
    {
        if ($this->throwOnParse) {
            throw new \RuntimeException('invalid token');
        }
        $this->parsedTokens[] = [$token, null];

        return $this->returnNull ? null : $this->payload;
    }
}

class TokenParseOnlyEngineStub
{
    /** @var string[] */
    public array $parsed = [];

    public function parse(string $token): array
    {
        $this->parsed[] = $token;

        return ['sub' => 7];
    }
}

class TokenNoParseEngineStub
{
    public function issue(): string
    {
        return 'token';
    }
}

class TokenLogStub
{
    /** @var string[] */
    public array $records = [];

    public function warning(string $message): void
    {
        $this->records[] = $message;
    }

    public function log(string $level, string $message): void
    {
        $this->records[] = $message;
    }

    public function record(string $message, string $level = 'info'): void
    {
        $this->records[] = $message;
    }
}
