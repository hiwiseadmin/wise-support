<?php

declare(strict_types=1);

namespace wise\support\router;

use think\facade\Route;
use wise\support\ExtPoint;
use wise\support\ExtPoints;

/**
 * WiseAdmin 配置驱动的包路由注册器基类
 *
 * 所有 wise-* 包统一继承此基类，在 define() 中定义具体路由。
 * 路由前缀、中间件、限流等个性化配置全部收敛到 config/<pkg>.php 的 web 节：
 *
 * ```php
 * 'web' => [
 *     'enable'     => true,               // 总开关，false 时整包不注册任何 HTTP 路由
 *     'app'        => 'api',              // 归属应用（可选）；留空时按 prefix 首段嗅探
 *     'prefix'     => 'api/wx',           // 默认前缀（可被分组级 prefix 覆盖）
 *     'middleware' => [],                 // 默认中间件（所有分组生效）
 *     'groups' => [
 *         'auth' => [                     // 分组键，对应 define() 中 $this->group('auth', ...)
 *             'enable'     => true,       // 分组开关
 *             'prefix'     => '',         // 分组前缀（默认继承 web.prefix）
 *             'middleware' => [           // 分组中间件（与默认中间件合并），使用框架原生写法 [类名, [参数...]]
 *                 [\wise\jwt\middleware\JwtAuth::class, ['app', 'bearer']],
 *                 [\wise\api\middleware\RateLimitMiddleware::class, ['60', '1']],
 *             ],
 *         ],
 *     ],
 * ],
 * ```
 *
 * 使用方式：
 * ```php
 * class RouteRegistrar extends \wise\support\router\RouteRegistrar
 * {
 *     protected string $configKey = 'wise-wx';
 *
 *     protected function define(array $config): void
 *     {
 *         $this->group('auth', function () {
 *             Route::post('login', '\wise\wx\controller\LoginController@login');
 *         });
 *     }
 * }
 * ```
 *
 * 由各包 ServiceProvider::boot() 中调用：
 * ```php
 * \wise\wx\RouteRegistrar::register();
 * ```
 *
 * ## 应用归属与延迟注册
 *
 * boot() 阶段 think-multi-app 尚未执行（它在 HttpRun 事件后才作为中间件运行），
 * 此时无法确定当前请求属于哪个应用，也无法知道该应用的 route 目录。因此：
 *
 *  - 归属某个已知应用（api / admin / member / plugin）的分组 → 投递到该应用的
 *    路由扩展点 route.{app}，等待应用 route 文件调用 applyRoutes() 落地；
 *  - 不属于任何已知应用（如 'wise/captcha' 这类全局前缀）→ 保持原行为，立即注册。
 *
 * 判定顺序（与 ExtPoints::ROUTE_POINTS 对应）：
 *  1. web.app / 子类 $targetApp 显式声明，非空则只看它；
 *  2. 为空时按（分组级 prefixes 优先，否则 web.prefix 的）首段嗅探。
 *
 * 落地时会自动剥掉应用名的那一段前缀：'api/wx' 在 api 应用内实际挂载为 'wx'，
 * 因为 /api 这一段已由 think-multi-app 从 pathinfo 中剥离。
 *
 * ## 包路由必须走本基类（强制约定）
 *
 * 应用挂载由框架统一负责，包（含第三方扩展）不自行拼 '/api'、'/admin' 这类
 * 绝对前缀。绝对前缀在应用模式下必然失效：app/api 等已是 think-multi-app 应用，
 * 首段会被从 pathinfo 剥离，形如 'api/foo' 的规则永远匹配不到 'foo'，
 * 请求会落到应用兜底规则上（api 应用表现为「插件不存在」403）。
 *
 * 框架不为这种写法提供兼容层，也不做启动期扫描告警：不符合标准形式的路由
 * 就是不可用。需要挂到某应用下的路由，只有两条合规路径：
 *  1. 继承本基类，在 define() 中用 $this->group() 声明（推荐，可获得配置驱动、
 *     优先级、能力市场启停与清单展示）；
 *  2. 直接 ExtPoint::register(ExtPoints::ROUTE_API, ...) 投递闭包（包自管路由，
 *     如 wise-api 的加密接口）。
 *
 * 启用与改前缀一律通过宿主配置完成，包源码无需改动——各包默认配置由
 * ServiceTrait::mergeConfig() 合并，宿主在 config/<pkg>.php 的 web 节覆盖即可：
 *
 * ```php
 * // config/wise-wx.php（宿主侧，优先于包默认值）
 * 'web' => [
 *     'enable' => true,
 *     'app'    => 'api',      // 显式归属；留空则按 prefix 首段嗅探
 *     'prefix' => 'api/wx',   // 改挂载点时只改这里
 * ],
 * ```
 *
 * @package wise\support\router
 */
abstract class RouteRegistrar
{
    /**
     * 包配置键名，如 'wise-wx'。子类必须定义。
     *
     * @var string
     */
    protected string $configKey = '';

    /**
     * 显式声明归属的应用（api / admin / member / plugin）
     *
     * 优先级低于 web.app 配置，高于前缀嗅探；留空表示交给前缀嗅探。
     *
     * @var string
     */
    protected string $targetApp = '';

    /**
     * 各包投递的路由清单，供能力市场展示
     *
     * 结构: [ point => [ implName => ['package' => string, 'group' => string, ...] ] ]
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    protected static array $descriptors = [];

    /**
     * 注册入口（由 ServiceProvider::boot() 调用）
     */
    public static function register(): void
    {
        (new static())->registerRoutes();
    }

    /**
     * 读取配置并调用 define()，总开关关闭时直接跳过
     */
    protected function registerRoutes(): void
    {
        $config = $this->webConfig();
        if (empty($config['enable'])) {
            return;
        }
        $this->define($config);
    }

    /**
     * 读取 web 配置节（包默认配置已通过 ServiceTrait::mergeConfig 与宿主配置合并）
     *
     * @return array
     */
    protected function webConfig(): array
    {
        $config = config($this->configKey . '.web', []);
        return is_array($config) ? $config : [];
    }

    /**
     * 子类实现：定义具体路由，通过 $this->group() 创建配置驱动的分组
     */
    abstract protected function define(array $config): void;

    /**
     * 创建配置驱动的路由分组
     *
     * - 分组未定义或 enable=false 时自动跳过
     * - 前缀取 group.prefix，未设置则继承 web.prefix
     * - 中间件 = web.middleware + group.middleware 合并
     * - 归属已知应用时延迟注册（投递到 route.{app}），否则立即注册
     *
     * @param string   $groupKey 分组键（对应 web.groups.{key}）
     * @param callable $definer  路由定义闭包
     * @return \think\route\RuleGroup|PendingGroup|null 立即注册时返回真实分组；延迟注册时返回句柄（可继续链式调用）；无前缀或分组禁用时返回 null
     */
    protected function group(string $groupKey, callable $definer)
    {
        $web    = $this->webConfig();
        $groups = $web['groups'] ?? [];
        $group  = $groups[$groupKey] ?? [];

        if (isset($group['enable']) && empty($group['enable'])) {
            return null;
        }

        $prefix     = (string) ($group['prefix'] ?? ($web['prefix'] ?? ''));
        $prefix     = trim($prefix, '/');
        $middleware = array_merge(
            (array) ($web['middleware'] ?? []),
            (array) ($group['middleware'] ?? [])
        );

        $app   = $this->resolveApp($web, $prefix);
        $point = ExtPoints::ROUTE_POINTS[$app] ?? '';

        // 不属于任何已知应用：维持原有行为，boot 阶段立即写入全局路由表
        if ($point === '') {
            return $this->mount($prefix, $middleware, $definer);
        }

        return $this->deferTo($point, $app, $prefix, $middleware, $definer, $groupKey, $web, $group);
    }

    /**
     * 真正创建路由分组
     *
     * 前缀为空**且无中间件**时才直接执行 definer（等价于挂在应用根）。
     * 前缀为空但有中间件时，仍须以「空名分组」承载中间件 —— 例：包路由挂在
     * 应用根（分组 prefix 为 `member`，剥离应用名后相对前缀为 ''，实际 URL 为
     * `/member/**`），若此时直接执行 definer()，中间件会被静默丢弃
     * （如会员强认证 RequireMemberAuth 失效，受保护页面变公开）。
     * TP 的 RuleGroup 对空名分组是安全的：`fullName` 为空，子规则即自身路径。
     *
     * @param array<int, mixed> $middleware
     * @return \think\route\RuleGroup|null 无分组时返回 null
     */
    protected function mount(string $prefix, array $middleware, callable $definer)
    {
        if ($prefix === '' && $middleware === []) {
            $definer();
            return null;
        }

        $route = Route::group($prefix, $definer);
        if (!empty($middleware)) {
            $route->middleware($middleware);
        }

        return $route;
    }

    /**
     * 把分组投递到应用路由扩展点，等待 applyRoutes() 落地
     *
     * @param array<int, mixed>  $middleware
     * @param array<string,mixed> $web
     * @param array<string,mixed> $group
     */
    protected function deferTo(
        string $point,
        string $app,
        string $prefix,
        array $middleware,
        callable $definer,
        string $groupKey,
        array $web,
        array $group
    ): PendingGroup {
        $pending = new PendingGroup();

        // 剥掉应用名那一段：'api/wx' 在 api 应用内挂载为 'wx'
        $relative = $this->stripAppPrefix($prefix, $app);
        $name     = $this->implName($groupKey);
        $priority = (int) ($group['priority'] ?? ($web['priority'] ?? 100));

        ExtPoint::register($point, $name, function () use ($relative, $middleware, $definer, $pending): void {
            $pending->replay($this->mount($relative, $middleware, $definer));
        }, $priority);

        static::$descriptors[$point][$name] = [
            'package'    => $this->packageName(),
            'group'      => $groupKey,
            'app'        => $app,
            'prefix'     => $relative,
            'endpoint'   => $relative === '' ? '/' . $app : '/' . $app . '/' . $relative,
            'middleware' => count($middleware),
            'source'     => static::class,
        ];

        return $pending;
    }

    /**
     * 判定本注册器归属的应用
     *
     * 优先级：web.app > 子类 $targetApp > prefix 首段嗅探。
     * 返回不在 ExtPoints::ROUTE_POINTS 中的值时，调用方按「全局路由」处理。
     *
     * @param array<string,mixed> $web
     */
    protected function resolveApp(array $web, string $prefix = ''): string
    {
        $explicit = trim((string) ($web['app'] ?? ''));
        if ($explicit === '') {
            $explicit = trim($this->targetApp);
        }
        if ($explicit !== '') {
            return strtolower($explicit);
        }

        if ($prefix === '') {
            return '';
        }

        return strtolower(explode('/', $prefix)[0]);
    }

    /**
     * 剥掉前缀中的应用名首段
     */
    protected function stripAppPrefix(string $prefix, string $app): string
    {
        if ($prefix === '') {
            return '';
        }

        $segments = explode('/', $prefix);
        if (strtolower($segments[0]) === strtolower($app)) {
            array_shift($segments);
        }

        return implode('/', $segments);
    }

    /**
     * 扩展点实现名：{包}:{分组}
     */
    protected function implName(string $groupKey): string
    {
        return $this->packageName() . ':' . $groupKey;
    }

    /**
     * 包名（优先配置键，未定义时回落到注册器类名）
     */
    protected function packageName(): string
    {
        return $this->configKey !== '' ? $this->configKey : static::class;
    }

    /**
     * 落地某个应用的所有包路由（由应用的 route 文件调用）
     *
     * 必须在对应应用的 route 文件内调用——此时 think-multi-app 已完成应用解析，
     * pathinfo 中的应用名首段已被剥离，相对前缀才能正确匹配。
     *
     * 停用的实现会被跳过，使能力市场的启停开关对该应用的路由生效。
     *
     * @param string $point 扩展点名，取 ExtPoints::ROUTE_* 之一
     * @return int 实际落地的分组数
     */
    public static function applyRoutes(string $point): int
    {
        $applied = 0;

        foreach (ExtPoint::all($point) as $item) {
            if (!($item['active'] ?? true)) {
                continue;
            }

            $concrete = $item['concrete'] ?? null;
            if (!is_callable($concrete)) {
                continue;
            }

            try {
                $concrete();
                $applied++;
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'RouteRegistrar: 应用路由 [%s] 注册失败: %s',
                    $point,
                    $e->getMessage()
                ));
            }
        }

        return $applied;
    }

    /**
     * 获取某应用路由扩展点的包路由清单（供能力市场展示）
     *
     * 清单主要由 boot 阶段投递 $this->group() 时写入；对于绕过 group()、直接把
     * 闭包 register() 到扩展点的包（如 wise-api 的加密接口），这里补一条最小信息，
     * 保证能力市场始终能看到全部实现。
     *
     * @return array<string, array<string, mixed>>
     */
    public static function describe(string $point): array
    {
        $result = static::$descriptors[$point] ?? [];

        foreach (array_keys(ExtPoint::all($point)) as $name) {
            if (isset($result[$name])) {
                continue;
            }
            $result[$name] = [
                'package'    => str_contains($name, ':') ? strstr($name, ':', true) : $name,
                'group'      => str_contains($name, ':') ? substr($name, strpos($name, ':') + 1) : '',
                'app'        => '',
                'prefix'     => '',
                'endpoint'   => '',
                'middleware' => 0,
                'source'     => '',
            ];
        }

        ksort($result);

        return $result;
    }
}
