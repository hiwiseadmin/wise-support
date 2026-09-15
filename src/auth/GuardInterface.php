<?php
declare(strict_types=1);

namespace wise\support\auth;

/**
 * 认证守卫接口
 *
 * 定义统一的认证守卫契约。每个守卫负责从特定来源
 * （Session / JWT Token / OAuth 等）解析当前用户。
 *
 * 各 wise-* 包通过 ExtPoint('auth.guard') 注册自己的守卫实现。
 *
 * @package wise\support\auth
 */
interface GuardInterface
{
    /**
     * 获取当前登录用户
     *
     * @return object|null 用户对象，未登录返回 null
     */
    public function user(): ?object;

    /**
     * 获取当前登录用户 ID
     *
     * @return string|int|null 用户标识，未登录返回 null
     */
    public function id(): string|int|null;

    /**
     * 检查是否已登录
     *
     * @return bool
     */
    public function check(): bool;

    /**
     * 是否已认证为访客（未登录）
     *
     * @return bool
     */
    public function guest(): bool;

    /**
     * 设置当前用户（用于登录成功后手动设置）
     *
     * @param object $user
     * @return void
     */
    public function setUser(object $user): void;

    /**
     * 获取守卫名称
     *
     * @return string 如 'admin'、'api'、'member'
     */
    public function getName(): string;

    /**
     * 重置用户缓存
     *
     * 用户信息变更后（如修改昵称、角色等），
     * 调用此方法使下次 user() 调用重新从数据源解析用户。
     */
    public function resetUser(): void;
}
