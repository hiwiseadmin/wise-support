<?php
declare(strict_types=1);

namespace wise\support\facade;

use think\Facade;

/**
 * Context 门面 — 请求上下文快捷访问
 *
 * 为 RequestContext 提供静态 Facade 入口。
 *
 * 使用示例：
 * <pre>
 * use wise\support\facade\Context;
 *
 * $requestId = Context::getRequestId();
 * $userId = Context::getUserId();
 * Context::set('locale', 'zh-CN');
 * </pre>
 *
 * @method static void set(string $key, mixed $value)               设置上下文值
 * @method static mixed get(string $key, mixed $default = null)    获取上下文值
 * @method static bool has(string $key)                             检查是否存在
 * @method static void remove(string $key)                          删除上下文值
 * @method static void setMany(array $data)                         批量设置
 * @method static void setRequestId(string $requestId)              设置请求 ID
 * @method static string getRequestId()                             获取请求 ID
 * @method static void setUser(?object $user)                       设置当前用户
 * @method static object|null getUser()                             获取当前用户
 * @method static string|int|null getUserId()                       获取当前用户 ID
 * @method static void setGuard(string $guard)                      设置当前守卫
 * @method static string getGuard()                                 获取当前守卫
 * @method static array all()                                       获取所有上下文
 * @method static array logContext()                                获取日志用上下文
 * @method static void clear()                                      清除所有上下文
 *
 * @package wise\support\facade
 */
class Context extends Facade
{
    protected static function getFacadeClass(): string
    {
        return \wise\support\context\RequestContext::class;
    }
}
