<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use think\Container;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\exception\GeneralErrorCode;
use wise\support\exception\WiseException;
use wise\support\jwt\JwtManager;

/**
 * JWT 引擎代理 JwtManager 单元测试
 */
class JwtManagerTest extends TestCase
{
    private JwtLogStub $log;

    protected function setUp(): void
    {
        ExtPoint::reset();
        $this->log = new JwtLogStub();
        Container::getInstance()->bind('log', $this->log);
    }

    protected function tearDown(): void
    {
        ExtPoint::reset();
        Container::getInstance()->delete('log');
    }

    private function definePoint(): void
    {
        ExtPoint::definePoint(ExtPoints::JWT_PROVIDER, ExtPoint::POLICY_SINGLE);
    }

    // ============================================================
    // getEngine() 解析
    // ============================================================

    public function test_get_engine_throws_when_point_not_defined(): void
    {
        try {
            (new JwtManager())->getEngine();
            $this->fail('未注册 JWT 引擎时应抛出 WiseException');
        } catch (WiseException $e) {
            $this->assertSame(GeneralErrorCode::EXT_POINT_NOT_DEFINED->value, $e->getErrorCode());
            $this->assertSame(500, $e->getHttpCode());
            $this->assertStringContainsString('JWT 引擎未注册', $e->getMessage());
            $this->assertStringContainsString('ExtPoints::JWT_PROVIDER', $e->getMessage());
        }
    }

    public function test_get_engine_throws_when_no_active_implementation(): void
    {
        $this->definePoint();

        $this->expectException(WiseException::class);
        (new JwtManager())->getEngine();
    }

    public function test_get_engine_returns_closure_result_and_caches(): void
    {
        $this->definePoint();
        $calls = 0;
        ExtPoint::register(ExtPoints::JWT_PROVIDER, 'engine', function () use (&$calls) {
            $calls++;
            return new JwtEngineStub();
        });

        $manager = new JwtManager();
        $first   = $manager->getEngine();
        $second  = $manager->getEngine();

        $this->assertInstanceOf(JwtEngineStub::class, $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $calls, '引擎应被缓存，工厂只调用一次');
    }

    public function test_get_engine_resolves_string_class_via_container(): void
    {
        $this->definePoint();
        ExtPoint::register(ExtPoints::JWT_PROVIDER, 'engine', JwtEngineStub::class);

        $this->assertInstanceOf(JwtEngineStub::class, (new JwtManager())->getEngine());
    }

    public function test_get_engine_rejects_non_object_factory_result(): void
    {
        $this->definePoint();
        ExtPoint::register(ExtPoints::JWT_PROVIDER, 'bad', fn() => 'not-an-object');

        $this->expectException(WiseException::class);
        (new JwtManager())->getEngine();
    }

    public function test_clear_cache_forces_re_resolution(): void
    {
        $this->definePoint();
        ExtPoint::register(ExtPoints::JWT_PROVIDER, 'engine', fn() => new JwtEngineStub());

        $manager = new JwtManager();
        $first   = $manager->getEngine();
        $manager->clearCache();
        $second  = $manager->getEngine();

        $this->assertNotSame($first, $second);
    }

    public function test_engine_follows_active_implementation_after_switch(): void
    {
        $this->definePoint();
        ExtPoint::register(ExtPoints::JWT_PROVIDER, 'a', fn() => new JwtEngineStub('A'));
        ExtPoint::register(ExtPoints::JWT_PROVIDER, 'b', fn() => new JwtEngineStub('B'));

        $manager = new JwtManager();
        $this->assertSame('A', $manager->getEngine()->name());

        // jwt.provider 属安全相关扩展点：活跃变更需要宿主授权
        ExtPoint::bindOverrideAuthorizer(fn() => true);
        $this->assertTrue(ExtPoint::setActive(ExtPoints::JWT_PROVIDER, 'b', true));
        $manager->clearCache();

        $this->assertSame('B', $manager->getEngine()->name());
    }

    public function test_engine_selector_is_passed_to_provider(): void
    {
        $this->definePoint();

        $seen = [];
        ExtPoint::register(ExtPoints::JWT_PROVIDER, 'selector', function (?string $guard = null) use (&$seen) {
            $seen[] = $guard;

            return new JwtEngineStub($guard ?? 'default');
        });

        $manager = new JwtManager();

        // 未指定 guard：保持零参数调用（旧 provider 契约不变）
        $this->assertSame('default', $manager->getEngine()->name());
        $this->assertSame([null], $seen);

        // 指定 guard：provider 收到守卫名，可据此返回守卫专属引擎
        $this->assertSame('api', $manager->getEngine('api')->name());
        $this->assertSame([null, 'api'], $seen);

        // 同一 guard 复用缓存，不重复求值
        $this->assertSame('api', $manager->getEngine('api')->name());
        $this->assertSame([null, 'api'], $seen);
    }

    // ============================================================
    // isReady()
    // ============================================================

    public function test_is_ready_true_when_engine_available(): void
    {
        $this->definePoint();
        ExtPoint::register(ExtPoints::JWT_PROVIDER, 'engine', fn() => new JwtEngineStub());

        $this->assertTrue((new JwtManager())->isReady());
        $this->assertSame([], $this->log->records);
    }

    public function test_is_ready_false_and_logs_warning_when_unavailable(): void
    {
        $manager = new JwtManager();

        $this->assertFalse($manager->isReady());
        $this->assertCount(1, $this->log->records);
        $this->assertStringContainsString('JWT 引擎未就绪', $this->log->records[0]);
    }

    // ============================================================
    // __call 透明代理
    // ============================================================

    public function test_call_forwards_arguments_and_returns_result(): void
    {
        $this->definePoint();
        $engine = new JwtEngineStub();
        ExtPoint::register(ExtPoints::JWT_PROVIDER, 'engine', fn() => $engine);

        $token = (new JwtManager())->issue(42, ['role' => 'admin'], 600);

        $this->assertSame('token-42', $token);
        $this->assertSame([42, ['role' => 'admin'], 600], $engine->lastArgs);
    }

    public function test_call_throws_bad_method_call_for_unknown_method(): void
    {
        $this->definePoint();
        ExtPoint::register(ExtPoints::JWT_PROVIDER, 'engine', fn() => new JwtEngineStub());

        try {
            (new JwtManager())->notExistingMethod();
            $this->fail('未知方法应抛出 BadMethodCallException');
        } catch (\BadMethodCallException $e) {
            $this->assertStringContainsString('notExistingMethod()', $e->getMessage());
            $this->assertStringContainsString(JwtEngineStub::class, $e->getMessage());
        }
    }
}

// ============================================================
// 测试替身
// ============================================================

class JwtEngineStub
{
    public array $lastArgs = [];

    public function __construct(private string $name = 'stub')
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function issue(int|string $subject, array $claims = [], ?int $ttl = null): string
    {
        $this->lastArgs = [$subject, $claims, $ttl];
        return 'token-' . $subject;
    }
}

class JwtLogStub
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
