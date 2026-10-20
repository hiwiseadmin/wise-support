<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use think\Container;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\router\PendingGroup;
use wise\support\router\RouteRegistrar;

/**
 * 配置驱动路由注册器 RouteRegistrar 单元测试
 */
class RouteRegistrarTest extends TestCase
{
    private RouterConfigStub $config;
    private RouterRouteFacadeStub $route;
    private ?object $previousConfig = null;
    private bool $hadConfig = false;

    protected function setUp(): void
    {
        ExtPoint::reset();
        $this->resetDescriptors();

        $this->config = new RouterConfigStub();
        $this->route  = new RouterRouteFacadeStub();

        // 记录并临时替换容器中的 config 实例，tearDown 时原样恢复，
        // 避免污染依赖真实 think\Config 的其它测试（如 json() 解析链路）。
        $this->previousConfig = $this->readBoundInstance('config');
        $this->hadConfig      = $this->previousConfig !== null;

        Container::getInstance()->bind('config', $this->config);
        Container::getInstance()->bind('route', $this->route);
    }

    protected function tearDown(): void
    {
        ExtPoint::reset();
        $this->resetDescriptors();

        if ($this->hadConfig) {
            Container::getInstance()->instance('config', $this->previousConfig);
        } else {
            Container::getInstance()->delete('config');
        }
        Container::getInstance()->delete('route');
    }

    /**
     * 读取容器中已绑定的对象实例（不存在返回 null）
     */
    private function readBoundInstance(string $abstract): ?object
    {
        $property = new \ReflectionProperty(Container::class, 'instances');
        $property->setAccessible(true);

        $instances = $property->getValue(Container::getInstance());
        $instance  = $instances[$abstract] ?? null;

        return is_object($instance) ? $instance : null;
    }

    private function resetDescriptors(): void
    {
        $property = new \ReflectionProperty(RouteRegistrar::class, 'descriptors');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }

    // ============================================================
    // register() / webConfig()
    // ============================================================

    public function test_register_skips_when_web_disabled(): void
    {
        $this->config->data = ['wise-test' => ['web' => ['enable' => false]]];

        RouterTestRegistrar::register();

        $this->assertSame([], RouterTestRegistrar::$defined);
    }

    public function test_register_skips_when_web_config_missing(): void
    {
        RouterTestRegistrar::register();

        $this->assertSame([], RouterTestRegistrar::$defined);
    }

    public function test_register_calls_define_when_enabled(): void
    {
        $this->config->data = ['wise-test' => ['web' => ['enable' => true, 'prefix' => 'wise/x']]];

        RouterTestRegistrar::register();

        $this->assertCount(1, RouterTestRegistrar::$defined);
        $this->assertSame('wise/x', RouterTestRegistrar::$defined[0]['prefix']);
    }

    public function test_web_config_returns_empty_for_non_array(): void
    {
        $this->config->data = ['wise-test' => ['web' => 'not-an-array']];

        $registrar = new RouterTestRegistrar();

        $this->assertSame([], $registrar->callWebConfig());
    }

    public function test_web_config_returns_configured_array(): void
    {
        $this->config->data = ['wise-test' => ['web' => ['enable' => true, 'prefix' => 'p']]];

        $registrar = new RouterTestRegistrar();

        $this->assertSame(['enable' => true, 'prefix' => 'p'], $registrar->callWebConfig());
    }

    // ============================================================
    // group() — 立即挂载分支
    // ============================================================

    public function test_group_returns_null_when_group_disabled(): void
    {
        $this->config->data = ['wise-test' => ['web' => [
            'enable' => true,
            'groups' => ['auth' => ['enable' => false]],
        ]]];

        $registrar = new RouterTestRegistrar();
        $called = false;

        $result = $registrar->callGroup('auth', function () use (&$called) {
            $called = true;
        });

        $this->assertNull($result);
        $this->assertFalse($called);
    }

    public function test_group_with_empty_prefix_executes_definer_inline(): void
    {
        $this->config->data = ['wise-test' => ['web' => [
            'enable' => true,
            'prefix' => '',
            'groups' => ['auth' => []],
        ]]];

        $registrar = new RouterTestRegistrar();
        $called = false;

        $result = $registrar->callGroup('auth', function () use (&$called) {
            $called = true;
        });

        $this->assertNull($result);
        $this->assertTrue($called);
        $this->assertSame([], $this->route->groups);
    }

    public function test_group_with_unknown_app_prefix_mounts_immediately(): void
    {
        $this->config->data = ['wise-test' => ['web' => [
            'enable' => true,
            'prefix' => 'wise/captcha',
            'groups' => ['auth' => []],
        ]]];

        $registrar = new RouterTestRegistrar();
        $called = false;

        $group = $registrar->callGroup('auth', function () use (&$called) {
            $called = true;
        });

        $this->assertInstanceOf(RouterRouteGroupStub::class, $group);
        $this->assertTrue($called);
        $this->assertSame(['wise/captcha'], $this->route->groupPrefixes);
    }

    public function test_group_applies_merged_middleware(): void
    {
        $this->config->data = ['wise-test' => ['web' => [
            'enable' => true,
            'prefix' => 'wise/captcha',
            'middleware' => [['WebMw']],
            'groups' => ['auth' => ['middleware' => [['AuthMw']]]],
        ]]];

        $registrar = new RouterTestRegistrar();
        $group = $registrar->callGroup('auth', function () {});

        // 默认中间件 + 分组中间件按顺序合并（元素为框架原生 [类名, [参数]] 写法）
        $this->assertSame([['WebMw'], ['AuthMw']], $group->middleware);
    }

    public function test_group_without_middleware_skips_middleware_call(): void
    {
        $this->config->data = ['wise-test' => ['web' => [
            'enable' => true,
            'prefix' => 'wise/captcha',
            'groups' => ['auth' => []],
        ]]];

        $registrar = new RouterTestRegistrar();
        $group = $registrar->callGroup('auth', function () {});

        $this->assertSame([], $group->middleware);
    }

    // ============================================================
    // group() — 延迟投递分支
    // ============================================================

    public function test_group_defers_to_app_point_and_records_descriptor(): void
    {
        ExtPoint::definePoint(ExtPoints::ROUTE_API, ExtPoint::POLICY_MULTI);
        $this->config->data = ['wise-test' => ['web' => [
            'enable' => true,
            'prefix' => 'api/wx',
            'groups' => ['auth' => []],
        ]]];

        $registrar = new RouterTestRegistrar();
        $pending = $registrar->callGroup('auth', function () {});

        $this->assertInstanceOf(PendingGroup::class, $pending);
        $this->assertTrue(ExtPoint::has(ExtPoints::ROUTE_API, 'wise-test:auth'));
        $this->assertSame([], $this->route->groupPrefixes, '延迟注册时不应立即创建分组');

        $descriptors = RouteRegistrar::describe(ExtPoints::ROUTE_API);
        $this->assertArrayHasKey('wise-test:auth', $descriptors);
        $this->assertSame('api', $descriptors['wise-test:auth']['app']);
        $this->assertSame('wx', $descriptors['wise-test:auth']['prefix']);
        $this->assertSame('/api/wx', $descriptors['wise-test:auth']['endpoint']);
    }

    public function test_deferred_group_lands_relative_prefix_and_replays_chain(): void
    {
        ExtPoint::definePoint(ExtPoints::ROUTE_API, ExtPoint::POLICY_MULTI);
        $this->config->data = ['wise-test' => ['web' => [
            'enable' => true,
            'app' => 'api',
            'prefix' => 'api/wx',
            'middleware' => [['WebMw']],
            'groups' => ['auth' => ['prefix' => 'api/wx/admin', 'middleware' => [['AuthMw']]]],
        ]]];

        $registrar = new RouterTestRegistrar();
        $definerCalled = false;

        /** @var PendingGroup $pending */
        $pending = $registrar->callGroup('auth', function () use (&$definerCalled) {
            $definerCalled = true;
        });
        $pending->allowCrossDomain(['*']);

        // 落地（模拟应用 route 文件调用）
        $applied = RouteRegistrar::applyRoutes(ExtPoints::ROUTE_API);

        $this->assertSame(1, $applied);
        $this->assertTrue($definerCalled);
        $this->assertSame(['wx/admin'], $this->route->groupPrefixes);

        $group = $this->route->groups[0];
        $this->assertSame([['WebMw'], ['AuthMw']], $group->middleware);
        $this->assertSame([['*']], $group->crossDomain);

        $descriptors = RouteRegistrar::describe(ExtPoints::ROUTE_API);
        $this->assertSame('wx/admin', $descriptors['wise-test:auth']['prefix']);
        $this->assertSame(2, $descriptors['wise-test:auth']['middleware']);
    }

    public function test_deferred_group_respects_group_priority(): void
    {
        ExtPoint::definePoint(ExtPoints::ROUTE_API, ExtPoint::POLICY_MULTI);
        $this->config->data = ['wise-test' => ['web' => [
            'enable' => true,
            'prefix' => 'api/wx',
            'priority' => 5,
            'groups' => ['auth' => []],
        ]]];

        (new RouterTestRegistrar())->callGroup('auth', function () {});

        $all = ExtPoint::all(ExtPoints::ROUTE_API);

        $this->assertSame(5, $all['wise-test:auth']['priority']);
    }

    // ── 相对前缀为空（包路由挂在应用根，如 /member/**）──

    /**
     * 回归：相对前缀为空但存在中间件时，必须仍以空名分组承载中间件。
     *
     * 历史缺陷：mount() 在 prefix 为空时直接执行 definer()，中间件被静默丢弃 ——
     * 表现为 /member/** 的强认证（RequireMemberAuth）失效、受保护页面变公开。
     */
    public function test_empty_relative_prefix_keeps_middleware(): void
    {
        ExtPoint::definePoint(ExtPoints::ROUTE_MEMBER, ExtPoint::POLICY_MULTI);
        $this->config->data = ['wise-target' => ['web' => [
            'enable'     => true,
            'app'        => 'member',
            'prefix'     => 'member',
            'middleware' => [['RequireMemberAuthMw']],
            'groups'     => ['web' => []],
        ]]];

        $registrar     = new RouterTestTargetAppRegistrar();
        $definerCalled = false;

        $registrar->callGroup('web', function () use (&$definerCalled) {
            $definerCalled = true;
        });

        $applied = RouteRegistrar::applyRoutes(ExtPoints::ROUTE_MEMBER);

        $this->assertSame(1, $applied);
        $this->assertTrue($definerCalled);
        $this->assertSame([''], $this->route->groupPrefixes, '空前缀仍须创建分组以承载中间件');
        $this->assertSame([['RequireMemberAuthMw']], $this->route->groups[0]->middleware);
    }

    /**
     * 空前缀且无中间件时，直接执行 definer，不创建多余分组。
     */
    public function test_empty_relative_prefix_without_middleware_runs_directly(): void
    {
        ExtPoint::definePoint(ExtPoints::ROUTE_MEMBER, ExtPoint::POLICY_MULTI);
        $this->config->data = ['wise-target' => ['web' => [
            'enable' => true,
            'app'    => 'member',
            'prefix' => 'member',
            'groups' => ['web' => []],
        ]]];

        $registrar     = new RouterTestTargetAppRegistrar();
        $definerCalled = false;

        $registrar->callGroup('web', function () use (&$definerCalled) {
            $definerCalled = true;
        });

        $applied = RouteRegistrar::applyRoutes(ExtPoints::ROUTE_MEMBER);

        $this->assertSame(1, $applied);
        $this->assertTrue($definerCalled);
        $this->assertSame([], $this->route->groupPrefixes, '无中间件时无需建分组');
    }

    // ============================================================
    // applyRoutes()
    // ============================================================

    public function test_apply_routes_returns_zero_without_implementations(): void
    {
        $this->assertSame(0, RouteRegistrar::applyRoutes(ExtPoints::ROUTE_API));
    }

    public function test_apply_routes_runs_active_closures_only(): void
    {
        ExtPoint::definePoint(ExtPoints::ROUTE_API, ExtPoint::POLICY_MULTI);

        $runs = 0;
        ExtPoint::register(ExtPoints::ROUTE_API, 'a', function () use (&$runs) {
            $runs++;
        });
        ExtPoint::register(ExtPoints::ROUTE_API, 'b', function () use (&$runs) {
            $runs++;
        });
        ExtPoint::setActive(ExtPoints::ROUTE_API, 'b', false);

        $this->assertSame(1, RouteRegistrar::applyRoutes(ExtPoints::ROUTE_API));
        $this->assertSame(1, $runs);
    }

    public function test_apply_routes_skips_non_callable_concrete(): void
    {
        ExtPoint::definePoint(ExtPoints::ROUTE_API, ExtPoint::POLICY_MULTI);
        ExtPoint::register(ExtPoints::ROUTE_API, 'class', RouterNotCallableHandler::class);

        $this->assertSame(0, RouteRegistrar::applyRoutes(ExtPoints::ROUTE_API));
    }

    public function test_apply_routes_swallows_closure_exception(): void
    {
        ExtPoint::definePoint(ExtPoints::ROUTE_API, ExtPoint::POLICY_MULTI);
        $runs = 0;

        ExtPoint::register(ExtPoints::ROUTE_API, 'boom', function () {
            throw new \RuntimeException('route boom');
        });
        ExtPoint::register(ExtPoints::ROUTE_API, 'ok', function () use (&$runs) {
            $runs++;
        });

        $this->assertSame(1, RouteRegistrar::applyRoutes(ExtPoints::ROUTE_API));
        $this->assertSame(1, $runs);
    }

    // ============================================================
    // describe()
    // ============================================================

    public function test_describe_synthesizes_entries_for_unknown_implementations(): void
    {
        ExtPoint::definePoint(ExtPoints::ROUTE_API, ExtPoint::POLICY_MULTI);
        ExtPoint::register(ExtPoints::ROUTE_API, 'wise-api:encrypt', function () {});
        ExtPoint::register(ExtPoints::ROUTE_API, 'plain', function () {});

        $descriptors = RouteRegistrar::describe(ExtPoints::ROUTE_API);

        $this->assertSame('wise-api', $descriptors['wise-api:encrypt']['package']);
        $this->assertSame('encrypt', $descriptors['wise-api:encrypt']['group']);
        $this->assertSame('plain', $descriptors['plain']['package']);
        $this->assertSame('', $descriptors['plain']['group']);
        $this->assertSame('', $descriptors['plain']['endpoint']);
    }

    public function test_describe_returns_empty_for_unknown_point(): void
    {
        $this->assertSame([], RouteRegistrar::describe('route.nope'));
    }

    // ============================================================
    // 应用归属判定
    // ============================================================

    public function test_resolve_app_prefers_explicit_web_app(): void
    {
        $registrar = new RouterTestRegistrar();

        $this->assertSame('admin', $registrar->callResolveApp(['app' => 'Admin'], 'api/wx'));
    }

    public function test_resolve_app_falls_back_to_target_app(): void
    {
        $registrar = new RouterTestTargetAppRegistrar();

        $this->assertSame('member', $registrar->callResolveApp([], 'api/wx'));
    }

    public function test_resolve_app_sniffs_prefix_first_segment(): void
    {
        $registrar = new RouterTestRegistrar();

        $this->assertSame('api', $registrar->callResolveApp([], 'api/wx'));
        $this->assertSame('plugin', $registrar->callResolveApp([], 'Plugin/demo'));
    }

    public function test_resolve_app_returns_empty_for_empty_prefix(): void
    {
        $registrar = new RouterTestRegistrar();

        $this->assertSame('', $registrar->callResolveApp([]));
    }

    public function test_strip_app_prefix(): void
    {
        $registrar = new RouterTestRegistrar();

        $this->assertSame('wx', $registrar->callStripAppPrefix('api/wx', 'api'));
        $this->assertSame('', $registrar->callStripAppPrefix('api', 'api'));
        $this->assertSame('wx', $registrar->callStripAppPrefix('wx', 'api'));
        $this->assertSame('', $registrar->callStripAppPrefix('', 'api'));
        $this->assertSame('admin/list', $registrar->callStripAppPrefix('API/admin/list', 'api'));
    }

    public function test_impl_name_and_package_name(): void
    {
        $registrar = new RouterTestRegistrar();

        $this->assertSame('wise-test:auth', $registrar->callImplName('auth'));
        $this->assertSame('wise-test', $registrar->callPackageName());
    }

    public function test_package_name_falls_back_to_class_name(): void
    {
        $registrar = new RouterTestNoConfigKeyRegistrar();

        $this->assertSame(RouterTestNoConfigKeyRegistrar::class, $registrar->callPackageName());
    }
}

// ============================================================
// 测试替身与夹具
// ============================================================

class RouterConfigStub
{
    public array $data = [];

    /**
     * 支持 "pkg.web.groups.auth" 形式的点号路径读取
     */
    public function get(string $name = '', mixed $default = null): mixed
    {
        if ($name === '') {
            return $this->data;
        }

        $value = $this->data;
        foreach (explode('.', $name) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function set(array $config, string $name = ''): void
    {
        $this->data[$name] = $config;
    }

    public function has(string $name): bool
    {
        return isset($this->data[$name]);
    }
}

class RouterRouteGroupStub
{
    /** @var array<int, mixed> */
    public array $middleware = [];
    /** @var array<int, mixed> */
    public array $crossDomain = [];

    public function __construct(public string $prefix)
    {
    }

    public function middleware(array $middleware): self
    {
        $this->middleware = $middleware;
        return $this;
    }

    public function allowCrossDomain(array $domains): self
    {
        $this->crossDomain[] = $domains;
        return $this;
    }
}

class RouterRouteFacadeStub
{
    /** @var string[] */
    public array $groupPrefixes = [];
    /** @var RouterRouteGroupStub[] */
    public array $groups = [];

    public function group(string $prefix, callable $definer): RouterRouteGroupStub
    {
        $group = new RouterRouteGroupStub($prefix);
        $this->groupPrefixes[] = $prefix;
        $this->groups[]        = $group;
        $definer();

        return $group;
    }
}

class RouterTestRegistrar extends RouteRegistrar
{
    /** @var array<int, array> */
    public static array $defined = [];

    protected string $configKey = 'wise-test';

    protected function define(array $config): void
    {
        self::$defined[] = $config;
    }

    public function callWebConfig(): array
    {
        return $this->webConfig();
    }

    public function callGroup(string $groupKey, callable $definer)
    {
        return $this->group($groupKey, $definer);
    }

    public function callResolveApp(array $web, string $prefix = ''): string
    {
        return $this->resolveApp($web, $prefix);
    }

    public function callStripAppPrefix(string $prefix, string $app): string
    {
        return $this->stripAppPrefix($prefix, $app);
    }

    public function callImplName(string $groupKey): string
    {
        return $this->implName($groupKey);
    }

    public function callPackageName(): string
    {
        return $this->packageName();
    }
}

class RouterTestTargetAppRegistrar extends RouterTestRegistrar
{
    protected string $configKey = 'wise-target';
    protected string $targetApp = 'member';
}

class RouterTestNoConfigKeyRegistrar extends RouteRegistrar
{
    protected string $configKey = '';

    protected function define(array $config): void
    {
    }

    public function callPackageName(): string
    {
        return $this->packageName();
    }
}

class RouterNotCallableHandler
{
    public function handle(): void
    {
    }
}
