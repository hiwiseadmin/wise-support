<?php
declare(strict_types=1);

namespace wise\support\context;

/**
 * 请求级上下文容器
 *
 * 在单次请求生命周期内存储和传递上下文信息。
 * 典型用法：全局中间件在请求入口设置 request_id，
 * AuthManager 解析后注入 user，各包通过 Context 门面读取。
 *
 * 协程隔离：底层存储按「协程 ID」分桶（Swoole 环境下），
 * 每个并发请求拥有独立上下文，互不串扰；非协程环境（FPM/CLI）
 * 回退为单桶，配合 clear() 在请求结束时清理，避免常驻进程泄漏。
 * 因此本类可直接在 Swoole 并发场景下安全使用，无需调用方额外处理。
 *
 * 使用示例：
 * <pre>
 * // 全局中间件
 * RequestContext::setRequestId(bin2hex(random_bytes(8)));
 * RequestContext::set('locale', 'zh-CN');
 *
 * // AuthManager 登录成功后
 * RequestContext::setUser($user);
 *
 * // 任意 wise-* 包
 * $userId = RequestContext::getUserId();
 * $requestId = RequestContext::getRequestId();
 *
 * // 请求结束
 * RequestContext::clear();
 * </pre>
 *
 * @package wise\support\context
 */
class RequestContext
{
    /**
     * 协程级上下文存储池。
     *
     * key 为协程 ID（Swoole 协程环境）或固定 'global'（非协程环境）。
     * 每个并发请求拥有独立桶，从根本上解决原 static 存储跨协程共享、
     * 在 Swoole 常驻下互相串扰/误清的问题。
     *
     * @var array<int|string, array<string, mixed>>
     */
    private static array $pool = [];

    /**
     * 已注册 defer 清理的协程 ID 集合，避免重复注册。
     *
     * @var array<int, true>
     */
    private static array $deferred = [];

    /**
     * 获取当前协程/请求对应的存储桶 key。
     *
     * @return int|string
     */
    private static function bucket(): int|string
    {
        if (class_exists(\Swoole\Coroutine::class) && \Swoole\Coroutine::getCid() > 0) {
            return \Swoole\Coroutine::getCid();
        }

        return 'global';
    }

    /**
     * 获取当前存储桶（引用），并在协程环境下注册一次性清理。
     *
     * 协程环境下通过 Swoole\Coroutine::defer 确保协程结束（含异常路径）
     * 时自动销毁当前桶，防止常驻进程内存泄漏；非协程环境依赖 clear() 清理。
     *
     * @return array<string, mixed>
     */
    private static function &storage(): array
    {
        $bucket = self::bucket();

        if (!isset(self::$pool[$bucket])) {
            self::$pool[$bucket] = [];

            // 协程环境：注册 defer，确保协程结束后自动回收当前桶，避免泄漏。
            if ($bucket !== 'global' && !isset(self::$deferred[$bucket])) {
                self::$deferred[$bucket] = true;
                $b = $bucket;
                \Swoole\Coroutine::defer(function () use ($b) {
                    unset(self::$pool[$b], self::$deferred[$b]);
                });
            }
        }

        return self::$pool[$bucket];
    }

    /**
     * 设置任意上下文值
     */
    public static function set(string $key, mixed $value): void
    {
        self::storage()[$key] = $value;
    }

    /**
     * 获取任意上下文值
     *
     * @param string $key     键名
     * @param mixed  $default 默认值
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $storage = self::storage();

        return $storage[$key] ?? $default;
    }

    /**
     * 检查上下文键是否存在
     */
    public static function has(string $key): bool
    {
        return array_key_exists($key, self::storage());
    }

    /**
     * 删除上下文键
     */
    public static function remove(string $key): void
    {
        unset(self::storage()[$key]);
    }

    /**
     * 批量设置上下文数据
     *
     * @param array<string, mixed> $data
     */
    public static function setMany(array $data): void
    {
        $storage = &self::storage();
        $storage = array_merge($storage, $data);
    }

    // ============================================================
    // 常用键的便捷方法
    // ============================================================

    /**
     * 设置请求唯一 ID
     */
    public static function setRequestId(string $requestId): void
    {
        self::storage()['request_id'] = $requestId;
    }

    /**
     * 获取请求唯一 ID
     *
     * @return string 未设置时返回 "unknown"
     */
    public static function getRequestId(): string
    {
        return self::storage()['request_id'] ?? 'unknown';
    }

    /**
     * 设置当前登录用户对象
     */
    public static function setUser(?object $user): void
    {
        self::storage()['user'] = $user;
    }

    /**
     * 获取当前登录用户对象
     */
    public static function getUser(): ?object
    {
        return self::storage()['user'] ?? null;
    }

    /**
     * 获取当前登录用户 ID
     */
    public static function getUserId(): string|int|null
    {
        $user = self::storage()['user'] ?? null;
        if ($user === null) {
            return null;
        }
        // 尝试获取 id 属性
        return $user->id ?? (isset($user->admin_id) ? $user->admin_id : null);
    }

    /**
     * 设置租户 ID（多租户场景）
     */
    public static function setTenantId(?string $tenantId): void
    {
        self::storage()['tenant_id'] = $tenantId;
    }

    /**
     * 获取租户 ID
     */
    public static function getTenantId(): ?string
    {
        return self::storage()['tenant_id'] ?? null;
    }

    /**
     * 设置当前 Guard 名称
     */
    public static function setGuard(string $guard): void
    {
        self::storage()['guard'] = $guard;
    }

    /**
     * 获取当前 Guard 名称
     */
    public static function getGuard(): string
    {
        return self::storage()['guard'] ?? 'admin';
    }

    /**
     * 设置域名/地区信息
     */
    public static function setLocale(string $locale): void
    {
        self::storage()['locale'] = $locale;
    }

    /**
     * 获取域名/地区信息
     */
    public static function getLocale(): string
    {
        return self::storage()['locale'] ?? 'zh-CN';
    }

    // ============================================================
    // 生命周期管理
    // ============================================================

    /**
     * 获取全部上下文数据（用于日志注入、调试等）
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        // 返回副本，防止外部意外修改
        return self::storage();
    }

    /**
     * 获取日志摘要（request_id + user_id + guard）
     *
     * 各包的日志记录器可调用此方法获取统一的上下文摘要。
     *
     * @return array{request_id: string, guard?: string, user_id?: string|int|null}
     */
    public static function logContext(): array
    {
        $context = [
            'request_id' => self::getRequestId(),
        ];

        $guard = self::getGuard();
        $context['guard'] = $guard;

        $userId = self::getUserId();
        if ($userId !== null) {
            $context['user_id'] = $userId;
        }

        return $context;
    }

    /**
     * 清除当前协程/请求的上下文
     *
     * 仅清空当前协程桶，不影响其它并发请求，
     * 因此可在 Swoole 常驻多协程并发下安全调用。
     * 协程环境下也可由 defer 自动回收，此处调用为显式兜底。
     */
    public static function clear(): void
    {
        unset(self::$pool[self::bucket()]);
    }
}
