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

        // 根据名称自动设置 Session Key
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

        // 从 Session 中读取用户 ID
        $userId = $this->getSessionValue($this->sessionKey);
        if (empty($userId)) {
            // 未取到用户 ID：返回空，但**不**写入「已解析」状态。
            //
            // SessionInit 中间件晚于「服务注册 / 服务启动」阶段执行，而 wise-image
            // 等包会在注册阶段经
            //   ExtPoint::persistOverridesQuietly → ConfigManager::set
            //   → recordHistory → getCurrentUserId → auth_id()
            // 触发本方法。此时 Session 尚未加载，必定取不到用户 ID；
            // 若把这次失败连同 resolvedUser=true 一并缓存，本次请求内后续所有
            // auth_id() 都会命中 null 缓存 —— 典型后果是插件后台鉴权中间件
            // （app\plugin\middleware\PluginAdminAuth）在用户已登录时仍判定「未登录」，
            // 从而反复跳转 /admin/login。
            return null;
        }

        $this->resolvedUser = true;

        // 使用解析器获取用户对象
        if ($this->userResolver !== null) {
            $user = ($this->userResolver)($userId);
            if ($user instanceof \think\Model) {
                $user = $user->toArray();
                $user = (object) $user;
            }
            // 只有解析器返回了有效用户时才缓存，避免缓存 null
            if ($user !== null) {
                $this->cachedUser = $user;
            }
            return $this->cachedUser;
        }

        // 无解析器时返回虚拟用户对象（包含 id 和 session 中的基础信息）
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

    // ============================================================
    // Session 读取辅助（兼容 ThinkPHP 和原生 PHP）
    // ============================================================

    /**
     * 从 Session 中读取值
     *
     * 优先使用 ThinkPHP session() 函数，
     * 不可用时回退到 $_SESSION 超全局变量。
     */
    protected function getSessionValue(string $key, mixed $default = null): mixed
    {
        if (function_exists('session')) {
            $value = session($key);
            return $value === null ? $default : $value;
        }
        return $_SESSION[$key] ?? $default;
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
