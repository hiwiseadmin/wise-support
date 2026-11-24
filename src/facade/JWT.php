<?php
declare(strict_types=1);

namespace wise\support\facade;

use think\Facade;

/**
 * JWT 门面 — 统一的 JWT 操作入口
 *
 * 不重新实现 JWT 逻辑，而是通过容器绑定 'jwt'（→ JwtManager → ExtPoint('jwt.provider')）
 * 发现并代理当前活跃的 JWT 引擎（默认由 wise-jwt 提供）。
 *
 * 调用链：JWT::issue() → Facade → container('jwt') → JwtManager::__call() → ExtPoint → wise\jwt\Jwt
 *
 * 使用示例：
 * <pre>
 * use wise\support\facade\JWT;
 *
 * // 签发 Token
 * $token = JWT::issue(42, ['role' => 'admin']);
 *
 * // 签发 Token 对（Access + Refresh）
 * $pair = JWT::issuePair(42);
 *
 * // 解析和校验
 * $payload = JWT::parse($token);
 *
 * // 续签
 * $newToken = JWT::refresh($token);
 *
 * // 吊销
 * JWT::revoke($token);
 * </pre>
 *
 * @method static string issue(string|int $subject, array $claims = [], ?int $ttl = null)  签发 Token
 * @method static array parse(string $token)                                                 解析并校验 Token
 * @method static array|null tryParse(string $token)                                         尝试解析（失败返回 null）
 * @method static string refresh(string $token)                                              续签 Token
 * @method static bool revoke(string $token)                                                 吊销 Token
 * @method static bool shouldRefresh(array $payload)                                         判断是否需要续签
 * @method static int ttlOf(array $payload)                                                  获取剩余有效期
 *
 * @package wise\support\facade
 */
class JWT extends Facade
{
    protected static function getFacadeClass(): string
    {
        return 'jwt';
    }
}
