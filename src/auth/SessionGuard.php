<?php
declare(strict_types=1);

namespace wise\support\auth;

/**
 * Session 认证守卫
 *
 * 从 ThinkPHP Session 中读取登录用户信息。
 * 默认读取 'admin_id' 键，可通过 setSessionKey() 自定义。
 *
 * 适用于：后台 Web 管理端（admin）、前台 Web 会员端（member）
 *
 * 使用示例：
 * <pre>
 * $guard = new SessionGuard('admin');
 * $guard->setSessionKey('admin_id');
 * $guard->setUserResolver(function ($id) {
 *     return \app\admin\model\Admin::find($id);
 * });
 *
 * // 在 Guard 中使用
 * $user = $guard->user();   // Admin 模型实例或 null
 * $id = $guard->id();       // admin_id 或 null
 * </pre>
 *
 * @package wise\support\auth
 */
class SessionGuard implements GuardInterface
{
    /**
     * 守卫名称
     */
    protected string $name;

    /**
     * Session 中存储用户 ID 的键名
     */
    protected string $sessionKey = 'admin_id';

    /**
     * 用户解析器：接收用户 ID，返回用户对象
     *
     * @var callable|null
     */
    protected $userResolver = null;

    /**
     * 当前用户缓存
     *
     * @var object|null
     */
    protected ?object $cachedUser = null;

    /**
     * 是否已经尝试解析
     */
    protected bool $resolvedUser = false;

    /**
     * @param string $name 守卫名称，如 'admin'、'member'
     */
    public function __construct(string $name)
    {
        $this->name = $name;

        if ($name !== 'admin') {
            $this->sessionKey = $name . '_id';
        }
    }

    /**
     * 设置 Session 键名
     *
     * @param string $key 如 'admin_id'、'member_id'
     * @return $this
     */
    public function setSessionKey(string $key): self
    {
        $this->sessionKey = $key;
        return $this;
    }

    /**
     * 获取 Session 键名
     */
    public function getSessionKey(): string
    {
        return $this->sessionKey;
    }

    /**
     * 设置用户解析器
     *
     * @param callable $resolver fn(int|string $id): ?object
     * @return $this
     */
    public function setUserResolver(callable $resolver): self
    {
        $this->userResolver = $resolver;
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

        $userId = $this->getSessionValue($this->sessionKey);
        if (empty($userId)) {
            return null;
        }

        $this->resolvedUser = true;

        if ($this->userResolver !== null) {
            $user = ($this->userResolver)($userId);
            if ($user instanceof \think\Model) {
                $user = $user->toArray();
                $user = (object) $user;
            }
            if ($user !== null) {
                $this->cachedUser = $user;
            }
            return $this->cachedUser;
        }

        $this->cachedUser = (object) [
            'id'       => $userId,
            'username' => $this->getSessionValue($this->name . '_username', ''),
            'nickname' => $this->getSessionValue($this->name . '_nickname', ''),
        ];

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
     * 从 Session 中读取值
     *
     * 优先使用 ThinkPHP session() 函数；函数不可用时退回 Session 门面。
     *
     * 【为什么不回退 $_SESSION】
     * 超全局 $_SESSION 在常驻运行时（Swoole / Workerman / 队列 worker）是
     * 跨请求共享的，读到的是上一个请求的脏数据。对认证而言，「读到别人的
     * 登录态」比「读不到」危险得多，因此这里宁可返回 default 也不读超全局。
     * 两者都不可用时说明容器未就绪（如纯 CLI 脚本），按未登录处理。
     */
    protected function getSessionValue(string $key, mixed $default = null): mixed
    {
        if (function_exists('session')) {
            $value = session($key);
            return $value === null ? $default : $value;
        }

        if (class_exists(\think\facade\Session::class)) {
            try {
                $value = \think\facade\Session::get($key);
                return $value === null ? $default : $value;
            } catch (\Throwable $e) {
            }
        }

        return $default;
    }

    /**
     * 重置用户缓存
     *
     * 用户信息变更（如修改昵称）后调用，
     * 下次 user() 将重新从 Session 读取用户 ID 并解析用户对象。
     */
    public function resetUser(): void
    {
        $this->cachedUser = null;
        $this->resolvedUser = false;
    }
}
