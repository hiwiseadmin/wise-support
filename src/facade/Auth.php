<?php
declare(strict_types=1);

namespace wise\support\facade;

use think\Facade;

/**
 * Auth 门面 — 统一的用户认证入口
 *
 * 为全体 wise-* 包提供一致的认证 API：
 *
 * <pre>
 * use wise\support\facade\Auth;
 *
 * $user   = Auth::user();         // 当前默认 guard 的用户对象
 * $userId = Auth::id();           // 当前默认 guard 的用户 ID
 * $check  = Auth::check();        // 是否已登录
 * $guest  = Auth::guest();        // 是否未登录
 * $admin  = Auth::user('admin');  // admin guard 的用户
 * $api    = Auth::user('api');    // api guard 的用户（JWT）
 * Auth::authenticate();           // 强制认证，未登录抛异常
 * </pre>
 *
 * @method static object|null user(?string $guard = null)            获取当前登录用户
 * @method static string|int|null id(?string $guard = null)         获取当前登录用户 ID
 * @method static bool check(?string $guard = null)                  检查是否已登录
 * @method static bool guest(?string $guard = null)                  检查是否为访客
 * @method static object authenticate(?string $guard = null)         强制认证（未登录抛异常）
 * @method static void setDefaultGuard(string $guard)                设置默认守卫
 * @method static string getDefaultGuard()                           获取默认守卫名称
 * @method static void registerGuard(string $name, \wise\support\auth\GuardInterface $guard)  注册自定义守卫
 * @method static string[] getRegisteredGuards()                     列出所有已注册守卫
 *
 * @package wise\support\facade
 */
class Auth extends Facade
{
    protected static function getFacadeClass(): string
    {
        return \wise\support\auth\AuthManager::class;
    }
}
