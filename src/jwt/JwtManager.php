<?php
declare(strict_types=1);

namespace wise\support\jwt;

use think\facade\Log;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\exception\GeneralErrorCode;
use wise\support\exception\WiseException;

/**
 * JWT 引擎代理
 *
 * 不是 JWT 的实现者，而是 JWT 引擎的统一代理。
 * 内部通过 ExtPoint('jwt.provider') 发现当前活跃的 JWT 实现
 * （默认由 wise-jwt 包提供），并将所有调用透明转发给真正的引擎。
 *
 * 这个代理解决了 Facade 和 TokenGuard 直接使用 app('jwt')
 * 绕过 ExtPoint 注册中心的问题。
 *
 * 使用方式：
 * <pre>
 * // Facade
 * use wise\support\facade\JWT;
 * $token = JWT::issue(42);
 *
 * // 容器
 * $jwt = app('jwt'); // 返回 JwtManager 实例，透明代理到真正的引擎
 *
 * // ExtPoint 注册（wise-jwt 的 ServiceProvider 中）
 * ExtPoint::register('jwt.provider', 'wise-jwt', function() {
 *     return new \wise\jwt\Jwt(...);
 * });
 * </pre>
 *
 * @package wise\support\jwt
 */
class JwtManager
{
    /**
     * 默认引擎实例缓存（未指定 guard 时）
     */
    private ?object $engine = null;

    /**
     * 是否已尝试解析默认引擎
     */
    private bool $resolvedEngine = false;

    /**
     * 按 guard 名称缓存的引擎实例
     *
     * @var array<string, object>
     */
    private array $guardEngines = [];

    /**
     * 获取 JWT 引擎实例
     *
     * @param string|null $guard 守卫名（'api' / 'member' …）。凭证必须在其所属
     *                           guard 的身份范围内校验，因此 TokenGuard 会传入
     *                           自己的守卫名，由 provider 决定是否按 guard 选择
     *                           不同密钥 / issuer / audience。null 表示默认引擎。
     * @return object 实际的 JWT 引擎
     * @throws WiseException 未注册 JWT 引擎时抛出
     */
    public function getEngine(?string $guard = null): object
    {
        if ($guard === null) {
            if ($this->resolvedEngine && $this->engine !== null) {
                return $this->engine;
            }
        } elseif (isset($this->guardEngines[$guard])) {
            return $this->guardEngines[$guard];
        }

        $resolved = $this->resolveEngine($guard);

        if ($guard === null) {
            $this->resolvedEngine = true;
            $this->engine = $resolved;
        } else {
            $this->guardEngines[$guard] = $resolved;
        }

        return $resolved;
    }

    /**
     * 从扩展点解析引擎实例
     *
     * provider 可实现为 function (?string $guard = null)：指定 guard 时返回该
     * 守卫专属引擎（如 wise-jwt 的 JwtGuardManager::guard($guard)），忽略参数
     * 的实现则继续返回默认引擎，行为与之前完全一致。
     */
    private function resolveEngine(?string $guard): object
    {
        // 通过 ExtPoint('jwt.provider') 发现 JWT 引擎
        if (ExtPoint::defined(ExtPoints::JWT_PROVIDER)) {
            $active = ExtPoint::active(ExtPoints::JWT_PROVIDER);
            if ($active !== null) {
                $concrete = $active['concrete'];
                // 未指定 guard 时保持原样调用（零参数），避免改变既有 provider 契约
                $engine = is_callable($concrete)
                    ? ($guard === null ? $concrete() : $concrete($guard))
                    : app($concrete);

                if (is_object($engine)) {
                    return $engine;
                }
            }
        }

        throw new WiseException(
            GeneralErrorCode::EXT_POINT_NOT_DEFINED->value,
            'JWT 引擎未注册。请在 wise-jwt 的 ServiceProvider::boot() 中调用 ' .
            'ExtPoint::register(ExtPoints::JWT_PROVIDER, "wise-jwt", fn() => new \wise\jwt\Jwt(...))',
            500,
        );
    }

    /**
     * 检查 JWT 引擎是否已就绪
     *
     * @param string|null $guard 守卫名，null 检查默认引擎
     */
    public function isReady(?string $guard = null): bool
    {
        try {
            $this->getEngine($guard);
            return true;
        } catch (\Throwable $e) {
            // 引擎未就绪（未安装 wise-jwt / 配置缺失）时需可观测，
            // 否则 TokenGuard 会静默将认证降级为"未登录"，难以排查。
            Log::warning('JwtManager: JWT 引擎未就绪 - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 清除引擎缓存（JWT 引擎切换后调用）
     */
    public function clearCache(): void
    {
        $this->engine = null;
        $this->resolvedEngine = false;
        $this->guardEngines = [];
    }

    // ============================================================
    // 透明代理：将所有方法调用转发给真正的 JWT 引擎
    // ============================================================

    public function __call(string $method, array $args): mixed
    {
        $engine = $this->getEngine();
        if (!method_exists($engine, $method)) {
            throw new \BadMethodCallException(
                sprintf('JWT 引擎 [%s] 不支持方法 %s()', get_class($engine), $method)
            );
        }
        return $engine->{$method}(...$args);
    }
}
