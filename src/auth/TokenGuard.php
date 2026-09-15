<?php
declare(strict_types=1);

namespace wise\support\auth;

use think\facade\Request;
use wise\support\jwt\JwtManager;

/**
 * Token 认证守卫（JWT）
 *
 * 从 HTTP Authorization Header 中解析 Bearer Token，
 * 使用 JWT 引擎解析并获取当前用户。
 *
 * JWT 引擎通过 ExtPoint('jwt.provider') 获取，
 * 用户解析器通过 setUserResolver() 设置。
 *
 * 适用于：API 接口、小程序接口
 *
 * 使用示例：
 * <pre>
 * $guard = new TokenGuard('api');
 * $guard->setUserResolver(function ($payload) {
 *     return \app\api\model\User::find($payload['sub']);
 * });
 *
 * // 请求头: Authorization: Bearer eyJhbG...
 * $user = $guard->user();
 * </pre>
 *
 * @package wise\support\auth
 */
class TokenGuard implements GuardInterface
{
    /**
     * 守卫名称
     */
    protected string $name;

    /**
     * 用户解析器：接收 JWT payload，返回用户对象
     *
     * @var callable|null
     */
    protected $userResolver = null;

    /**
     * 当前用户缓存
     */
    protected ?object $cachedUser = null;

    /**
     * 是否已经尝试解析
     */
    protected bool $resolvedUser = false;

    /**
     * 已解析的 JWT payload
     */
    protected ?array $parsedPayload = null;

    /**
     * 是否要求在请求上下文中设置用户
     */
    protected bool $autoSetContext = true;

    /**
     * 是否允许从 query（?token=）与 Cookie 提取 Token。
     *
     * 默认关闭：仅信任 Authorization: Bearer 头。URL 参数中的 JWT 会被访问日志 /
     * 代理 / 浏览器历史长期留存，Cookie 通道则由浏览器自动携带，均属额外暴露面。
     * 仅当客户端确无法自定义请求头（如部分小程序）时，才由调用方显式开启。
     */
    protected bool $allowParamToken = false;

    /**
     * @param string $name 守卫名称，如 'admin', 'api'、'member'
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * 是否允许从 query / Cookie 通道提取 Token（默认关闭，见属性说明）
     *
     * @return $this
     */
    public function allowParamToken(bool $allow = true): self
    {
        $this->allowParamToken = $allow;
        return $this;
    }

    /**
     * 设置用户解析器
     *
     * @param callable $resolver fn(array $payload): ?object
     * @return $this
     */
    public function setUserResolver(callable $resolver): self
    {
        $this->userResolver = $resolver;
        return $this;
    }

    /**
     * 是否自动设置上下文
     */
    public function autoSetContext(bool $auto = true): self
    {
        $this->autoSetContext = $auto;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function user(): ?object
    {
        if ($this->resolvedUser) {
            return $this->cachedUser;
        }

        $this->resolvedUser = true;

        // 1. 从请求中提取 Token
        $token = $this->extractToken();
        if ($token === null) {
            return null;
        }

        // 2. 解析 JWT
        $payload = $this->parseToken($token);
        if ($payload === null) {
            return null;
        }

        $this->parsedPayload = $payload;

        // 3. 通过解析器获取用户
        if ($this->userResolver !== null) {
            $user = ($this->userResolver)($payload);
            if ($user instanceof \think\Model) {
                $user = $user->toArray();
                $user = (object) $user;
            }
            // 只有解析器返回了有效用户时才缓存，避免缓存 null
            if ($user !== null) {
                $this->cachedUser = $user;
            }
        } else {
            // 无解析器时返回 payload 中的基础信息
            $this->cachedUser = (object) [
                'id'   => $payload['sub'] ?? null,
                'jti'  => $payload['jti'] ?? null,
            ];
        }

        return $this->cachedUser;
    }

    /**
     * @inheritDoc
     */
    public function id(): string|int|null
    {
        $user = $this->user();
        if ($user === null) {
            return null;
        }
        return $user->id ?? null;
    }

    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @inheritDoc
     */
    public function guest(): bool
    {
        return !$this->check();
    }

    /**
     * @inheritDoc
     */
    public function setUser(object $user): void
    {
        $this->cachedUser = $user;
        $this->resolvedUser = true;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 获取已解析的 JWT Payload（供下游使用）
     */
    public function getPayload(): ?array
    {
        if (!$this->resolvedUser) {
            $this->user(); // 触发解析
        }
        return $this->parsedPayload;
    }

    // ============================================================
    // Protected
    // ============================================================

    /**
     * 从请求中提取 Bearer Token
     *
     * 框架适配：优先使用 ThinkPHP Request 门面，
     * 不可用时回退到 $_SERVER / $_GET / $_COOKIE 超全局变量。
     */
    protected function extractToken(): ?string
    {
        // 方案 A：ThinkPHP Request 门面
        if (class_exists(Request::class)) {
            $request = Request::instance();
            $header = $request->header('Authorization', '');
            if (str_starts_with($header, 'Bearer ')) {
                return substr($header, 7);
            }
            // query / cookie 通道默认关闭（见 allowParamToken 说明），仅显式开启后回退
            if ($this->allowParamToken) {
                $queryToken = $request->param('token', '');
                if (!empty($queryToken)) {
                    return $queryToken;
                }
                $cookieToken = $request->cookie('token', '');
                if (!empty($cookieToken)) {
                    return $cookieToken;
                }
            }
            return null;
        }

        // 方案 B：ThinkPHP request() 辅助函数
        // Swoole 兼容：request() 内部会处理协程隔离
        try {
            $request = request();
            if ($request) {
                $authHeader = $request->header('Authorization', '');
                if (str_starts_with($authHeader, 'Bearer ')) {
                    return substr($authHeader, 7);
                }
                if ($this->allowParamToken) {
                    $queryToken = $request->param('token', '');
                    if (!empty($queryToken)) {
                        return $queryToken;
                    }
                    $cookieToken = $request->cookie('token', '');
                    if (!empty($cookieToken)) {
                        return $cookieToken;
                    }
                }
                return null;
            }
        } catch (\Throwable) {
            // request() 不可用
        }

        // 方案 C：原生 PHP 超全局变量（仅 CLI / 未初始化场景）
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }
        if ($this->allowParamToken) {
            if (!empty($_GET['token'])) {
                return $_GET['token'];
            }
            if (!empty($_COOKIE['token'])) {
                return $_COOKIE['token'];
            }
        }

        return null;
    }

    /**
     * 解析 JWT Token
     *
     * 通过 JwtManager 代理统一获取 JWT 引擎（引擎由 wise-jwt 包注册）。
     * TokenGuard 需要宽容处理：引擎不可用时返回 null 而非抛异常。
     *
     * @param string $token
     * @return array|null JWT Payload，解析失败返回 null
     */
    protected function parseToken(string $token): ?array
    {
        try {
            $jwt = new JwtManager();
            if (!$jwt->isReady()) {
                return null;
            }
            $engine = $jwt->getEngine();

            if (method_exists($engine, 'tryParse')) {
                return $engine->tryParse($token);
            }
            if (method_exists($engine, 'parse')) {
                return $engine->parse($token);
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 重置用户缓存
     *
     * 用户信息变更后调用，下次 user() 将重新解析 JWT Token。
     */
    public function resetUser(): void
    {
        $this->cachedUser = null;
        $this->resolvedUser = false;
        $this->parsedPayload = null;
    }
}
