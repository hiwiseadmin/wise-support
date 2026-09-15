<?php
declare(strict_types=1);

namespace wise\support\event;

/**
 * WiseAdmin 框架标准事件常量
 *
 * 统一管理所有跨包事件的命名，替代各包自行硬编码事件名称字符串。
 * 使用常量引用可获得 IDE 自动补全、重构安全、命名一致性等收益。
 *
 * 命名规范：
 *  - {子系统}.{动作}.{状态}  如 auth.login.success / jwt.issued / config.changed
 *  - 所有事件名使用小写 + 点号分隔，与 ThinkPHP Event 系统兼容
 *
 * 使用示例：
 * <pre>
 * use wise\support\event\WiseEvents;
 * use think\facade\Event;
 *
 * // 触发事件
 * Event::trigger(WiseEvents::AUTH_LOGIN_SUCCESS, ['user' => $user, 'guard' => 'admin']);
 *
 * // 监听事件
 * Event::listen(WiseEvents::AUTH_LOGIN_SUCCESS, function ($payload) {
 *     // 处理登录成功后的逻辑（记录日志、同步数据等）
 * });
 * </pre>
 *
 * @package wise\support\event
 */
final class WiseEvents
{
    // ============================================================
    // 认证事件 (auth.*)
    // ============================================================

    /**
     * 用户登录成功
     * Payload: { user: object, guard: string, login_ip?: string, login_time?: int }
     */
    public const AUTH_LOGIN_SUCCESS = 'auth.login.success';

    /**
     * 用户登录失败
     * Payload: { credentials: array, guard: string, reason: string, ip?: string }
     */
    public const AUTH_LOGIN_FAIL = 'auth.login.fail';

    /**
     * 用户登出
     * Payload: { user: object, guard: string }
     */
    public const AUTH_LOGOUT = 'auth.logout';

    /**
     * Session / Token 过期
     * Payload: { user_id: string|int, guard: string, token_jti?: string, expired_at?: int }
     */
    public const AUTH_SESSION_EXPIRE = 'auth.session.expire';

    /**
     * 登录尝试被锁定
     * Payload: { credentials: array, guard: string, lock_until: int, ip?: string }
     */
    public const AUTH_LOCKED = 'auth.locked';

    // ============================================================
    // 用户事件 (user.*)
    // ============================================================

    /**
     * 用户创建
     * Payload: { user: object, guard?: string }
     */
    public const USER_CREATED = 'user.created';

    /**
     * 用户信息更新
     * Payload: { user: object, changed_fields: string[], guard?: string }
     */
    public const USER_UPDATED = 'user.updated';

    /**
     * 用户删除
     * Payload: { user_id: string|int, user_data?: array, guard?: string }
     */
    public const USER_DELETED = 'user.deleted';

    /**
     * 用户密码修改
     * Payload: { user_id: string|int, guard?: string }
     */
    public const USER_PASSWORD_CHANGED = 'user.password.changed';

    // ============================================================
    // JWT 事件 (jwt.*)
    // ============================================================

    /**
     * Token 签发前
     * Payload: { payload: array }
     */
    public const JWT_ISSUING = 'jwt.issuing';

    /**
     * Token 已签发
     * Payload: { token: string, payload: array }
     */
    public const JWT_ISSUED = 'jwt.issued';

    /**
     * 开始校验 Token
     * Payload: { token: string }
     */
    public const JWT_PARSING = 'jwt.parsing';

    /**
     * Token 校验通过
     * Payload: { token: string, payload: array }
     */
    public const JWT_PARSED = 'jwt.parsed';

    /**
     * Token 被吊销
     * Payload: { jti: string, revoked_at?: int }
     */
    public const JWT_REVOKED = 'jwt.revoked';

    /**
     * Token 无效（签名/格式/黑名单）
     * Payload: { token: string, reason: string }
     */
    public const JWT_INVALID = 'jwt.invalid';

    /**
     * 用户所有 Token 全局失效
     * Payload: { subject: string, new_version: int }
     */
    public const JWT_GLOBAL_REVOKE = 'jwt.global_revoke';

    // ============================================================
    // 系统事件 (system.*)
    // ============================================================

    /**
     * 应用启动完成
     * Payload: { version: string, env: string, boot_time_ms: float }
     */
    public const APP_BOOTED = 'system.booted';

    /**
     * 应用即将关闭
     * Payload: { reason?: string }
     */
    public const APP_SHUTDOWN = 'system.shutdown';

    // ============================================================
    // 配置事件 (config.*)
    // ============================================================

    /**
     * 配置项变更
     * Payload: { config_key: string, old_value: mixed, new_value: mixed, operator_id?: string|int }
     */
    public const CONFIG_CHANGED = 'config.changed';

    /**
     * 配置项即将保存
     * Payload: { config_key: string, value: mixed }
     */
    public const CONFIG_SAVING = 'config.saving';

    /**
     * 配置项已保存
     * Payload: { config_key: string, value: mixed }
     */
    public const CONFIG_SAVED = 'config.saved';

    /**
     * 配置项即将删除
     * Payload: { config_key: string }
     */
    public const CONFIG_DELETING = 'config.deleting';

    /**
     * 配置已回滚
     * Payload: { config_key: string, reverted_to_version: int }
     */
    public const CONFIG_REVERTED = 'config.reverted';

    // ============================================================
    // 健康检查事件 (health.*)
    // ============================================================

    /**
     * 健康检查失败
     * Payload: { check_name: string, error: string, timestamp: int }
     */
    public const HEALTH_CHECK_FAIL = 'health.check.fail';

    /**
     * 健康检查全部通过
     * Payload: { checks_count: int, duration_ms: float }
     */
    public const HEALTH_CHECK_ALL_PASS = 'health.check.all_pass';

    // ============================================================
    // 缓存事件 (cache.*)
    // ============================================================

    /**
     * 缓存被清除（批量失效）
     * Payload: { tags: string[], cleared_keys_count?: int }
     */
    public const CACHE_FLUSHED = 'cache.flushed';

    /**
     * 缓存键被删除
     * Payload: { key: string }
     */
    public const CACHE_KEY_DELETED = 'cache.key_deleted';

    // ============================================================
    // WebSocket 事件 (ws.*)
    // ============================================================

    /**
     * WebSocket 连接建立
     * Payload: { fd: int, user_id?: string|int, remote_addr?: string }
     */
    public const WS_CONNECTION_OPEN = 'ws.connection.open';

    /**
     * WebSocket 连接关闭
     * Payload: { fd: int, user_id?: string|int, reason?: string }
     */
    public const WS_CONNECTION_CLOSE = 'ws.connection.close';

    /**
     * WebSocket 心跳超时
     * Payload: { fd: int, user_id?: string|int, last_heartbeat: int }
     */
    public const WS_HEARTBEAT_TIMEOUT = 'ws.heartbeat.timeout';

    // ============================================================
    // 通知事件 (notification.*)
    // ============================================================

    /**
     * 通知发送前
     * Payload: { channel: string, template: string, recipient: mixed }
     */
    public const NOTIFICATION_SENDING = 'notification.sending';

    /**
     * 通知发送成功
     * Payload: { channel: string, template: string, recipient: mixed, response?: array }
     */
    public const NOTIFICATION_SENT = 'notification.sent';

    /**
     * 通知发送失败
     * Payload: { channel: string, template: string, recipient: mixed, error: string }
     */
    public const NOTIFICATION_FAILED = 'notification.failed';

    // ============================================================
    // 扩展点事件 (extpoint.*)
    // ============================================================

    /**
     * 扩展实现被注册
     * Payload: { point: string, name: string, concrete: string|callable }
     */
    public const EXT_REGISTERED = 'ext.registered';

    /**
     * 扩展实现被注销
     * Payload: { point: string, name: string }
     */
    public const EXT_UNREGISTERED = 'ext.unregistered';

    /**
     * 扩展点活跃实现被切换
     * Payload: { point: string, from: string, to: string }
     */
    public const EXT_ACTIVE_SWITCHED = 'ext.active_switched';

    /**
     * 返回所有事件名称列表（用于配置/文档生成）
     *
     * @return string[]
     */
    public static function all(): array
    {
        $ref = new \ReflectionClass(self::class);
        return array_values($ref->getConstants());
    }
}
