<?php
declare(strict_types=1);

namespace wise\support\auth;

use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\context\RequestContext;
use wise\support\exception\GeneralErrorCode;
use wise\support\exception\WiseException;

/**
 * 统一认证管理器
 *
 * 为全体 wise-* 包提供唯一的用户认证入口。
 * 通过 ExtPoint('auth.guard') 发现并管理多个 Guard（admin/api/member 等）。
 *
 * 使用示例：
 * <pre>
 * use wise\support\auth\AuthManager;
 *
 * $auth = app(AuthManager::class);
 *
 * // 获取当前 admin guard 的用户
 * $admin = $auth->user();          // 默认 guard (admin)
 * $admin = $auth->user('admin');   // 显式指定
 *
 * // 获取 api guard 的用户（JWT 解析）
 * $apiUser = $auth->user('api');
 *
 * // 检查登录状态
 * if ($auth->check('admin')) {  }
 *
 * // 获取用户 ID
 * $userId = $auth->id();
 * </pre>
 *
 * @package wise\support\auth
 */
class AuthManager
{
    /**
     * 已解析的 Guard 实例缓存
     *
     * @var array<string, GuardInterface>
     */
    protected array $guards = [];

    /**
     * 默认 Guard 名称
     */
    protected string $defaultGuard = 'admin';

    /**
     * 设置默认 Guard 名称
     */
    public function setDefaultGuard(string $guard): self
    {
        $this->defaultGuard = $guard;
        return $this;
    }

    /**
     * 获取默认 Guard 名称
     */
    public function getDefaultGuard(): string
    {
        return $this->defaultGuard;
    }

    /**
     * 获取指定 Guard 的当前登录用户
     *
     * @param string|null $guard 守卫名称，null 使用默认
     * @return object|null
     */
    public function user(?string $guard = null): ?object
    {
        $name = $guard ?? $this->defaultGuard;
        $user = $this->guard($name)->user();

        // 无论是否解析到用户都同步上下文：解析为 null 时同样清空 user，
        // 避免 guard 切换 / 用户失效后 RequestContext 残留上一个 guard 的身份
        // （getUserId()/getUser() 若被属主判断、日志归属误用将读到错误身份）。
        RequestContext::setUser($user);
        RequestContext::setGuard($name);

        return $user;
    }

    /**
     * 获取指定 Guard 的当前登录用户 ID
     *
     * @param string|null $guard
     * @return string|int|null
     */
    public function id(?string $guard = null): string|int|null
    {
        return $this->guard($guard ?? $this->defaultGuard)->id();
    }

    /**
     * 检查指定 Guard 是否已登录
     *
     * @param string|null $guard
     * @return bool
     */
    public function check(?string $guard = null): bool
    {
        return $this->guard($guard ?? $this->defaultGuard)->check();
    }

    /**
     * 检查指定 Guard 是否为访客（未登录）
     *
     * @param string|null $guard
     * @return bool
     */
    public function guest(?string $guard = null): bool
    {
        return $this->guard($guard ?? $this->defaultGuard)->guest();
    }

    /**
     * 强制认证（要求已登录，未登录抛出异常）
     *
     * @param string|null $guard
     * @return object 当前登录用户
     * @throws WiseException 未登录时抛出
     */
    public function authenticate(?string $guard = null): object
    {
        $user = $this->user($guard);
        if ($user === null) {
            throw new WiseException(
                GeneralErrorCode::UNAUTHORIZED->value,
                '请先登录',
                401,
            );
        }
        return $user;
    }

    /**
     * 获取 Guard 实例
     *
     * 按以下顺序查找：
     *  1. 已缓存的实例
     *  2. ExtPoint 注册的实现
     *  3. 回退到内置 SessionGuard
     *
     * @param string $name 守卫名称
     * @return GuardInterface
     * @throws \RuntimeException Guard 未注册时抛出
     */
    public function guard(string $name): GuardInterface
    {
        if (isset($this->guards[$name])) {
            return $this->guards[$name];
        }

        // 尝试通过 ExtPoint 发现
        $concrete = ExtPoint::get(ExtPoints::AUTH_GUARD, $name);

        if ($concrete !== null) {
            $guard = is_callable($concrete) ? $concrete() : app($concrete);

            if (!$guard instanceof GuardInterface) {
                throw new \RuntimeException(
                    sprintf('Auth Guard [%s] 必须实现 GuardInterface，当前: %s', $name, get_debug_type($guard))
                );
            }

            $this->guards[$name] = $guard;
            return $guard;
        }

        // 回退：尝试自动创建内置 Guard
        $guard = $this->createFallbackGuard($name);
        $this->guards[$name] = $guard;
        return $guard;
    }

    /**
     * 手动注册 Guard（用于不通过 ExtPoint 的场景）
     *
     * @param string $name
     * @param GuardInterface $guard
     * @return $this
     */
    public function registerGuard(string $name, GuardInterface $guard): self
    {
        $this->guards[$name] = $guard;
        return $this;
    }

    /**
     * 列出所有已注册的 Guard 名称
     *
     * @return string[]
     */
    public function getRegisteredGuards(): array
    {
        $guards = array_keys($this->guards);

        // 也从 ExtPoint 获取
        if (ExtPoint::defined(ExtPoints::AUTH_GUARD)) {
            $registered = ExtPoint::all(ExtPoints::AUTH_GUARD);
            foreach ($registered as $name => $item) {
                if (!in_array($name, $guards, true)) {
                    $guards[] = $name;
                }
            }
        }

        return $guards;
    }

    /**
     * 清除 Guard 实例缓存（用于测试或 Guard 实现变更后）
     *
     * 清除后下次访问 guard() 会重新从 ExtPoint 创建实例。
     */
    public function clearCache(): void
    {
        $this->guards = [];
    }

    /**
     * 刷新指定 Guard 的用户缓存
     *
     * 用户信息变更后（如修改昵称、角色、权限等）调用此方法，
     * 下次 user() 将重新从数据源解析用户对象。
     * 与 clearCache() 不同，此方法保留 Guard 实例。
     *
     * 使用场景：
     *   - Profile 控制器更新个人资料后：app('auth')->refresh('admin')
     *   - 管理员修改某个角色的权限后：app('auth')->refresh('admin')
     *
     * @param string|null $guard Guard 名称，null 使用默认
     * @return $this
     */
    public function refresh(?string $guard = null): self
    {
        $name = $guard ?? $this->defaultGuard;

        // 重置 Guard 内部的用户缓存，下次 user() 将重新解析用户
        if (isset($this->guards[$name])) {
            $this->guards[$name]->resetUser();
        }

        return $this;
    }

    /**
     * 创建回退 Guard
     *
     * 当 ExtPoint 中未注册指定 Guard 时，根据名称创建内置实现：
     *  - 'admin' → SessionGuard（从 Session 读取 admin_id）
     *  - 'api'   → TokenGuard（从 Authorization Header 解析 JWT）
     *
     * @param string $name
     * @return GuardInterface
     */
    protected function createFallbackGuard(string $name): GuardInterface
    {
        return match ($name) {
            'admin'  => new SessionGuard('admin'),
            'api'    => new TokenGuard('api'),
            'member' => new TokenGuard('member'),
            default  => throw new \RuntimeException(
                sprintf('Auth Guard [%s] 未注册。请通过 ExtPoint("%s", "%s", $handler) 注册。', $name, ExtPoints::AUTH_GUARD, $name)
            ),
        };
    }
}
