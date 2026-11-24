<?php
declare(strict_types=1);

namespace wise\support\context;

use wise\support\contracts\RequestStateResetterInterface;
use wise\support\runtime\Runtime;

/**
 * 请求级状态重置器注册表（框架 / 包 / 插件统一入口）
 *
 * 设计要点
 * --------
 * 1. **单一契约、单一调度**：实现 {@see RequestStateResetterInterface} 后注册到这里，
 *    由宿主按运行时选择执行点，实现方不再关心跑在 FPM 还是 Swoole。
 * 2. **注册是 worker 级的**：注册动作只应发生在 ServiceProvider::boot()（或插件 boot）阶段，
 *    注册表本身不随请求清空；`resetRequestState()` 只清实现方自己的请求级字段。
 * 3. **异常隔离**：任一实现抛错不影响其它实现，也不向外冒泡（重置失败不应把请求变 500），
 *    失败清单通过返回值暴露，便于诊断命令 / 单测断言。
 * 4. **优先级**：数值越小越先执行。约定档位：
 *      0-99   基础设施（上下文、追踪 ID）
 *      100    默认
 *      200+   业务（依赖上下文的权限、租户）
 *
 * 使用示例
 * --------
 * ```php
 * // 包 / 插件 ServiceProvider::boot()
 * RequestStateRegistry::register(
 *     'wise-attachment',
 *     \wise\attachment\RequestState\AttachmentStateResetter::class,
 *     200
 * );
 * ```
 *
 * @package wise\support\context
 */
final class RequestStateRegistry
{
    /**
     * 已注册的重置器
     *
     * @var array<string, array{resetter: string|RequestStateResetterInterface, priority: int}>
     */
    private static array $resetters = [];

    /** @var bool 是否已按优先级排序（注册后置脏，读取时惰性排序） */
    private static bool $sorted = true;

    /**
     * 注册一个重置器
     *
     * 同名重复注册时后者覆盖前者（便于宿主在测试中替换实现）。
     *
     * @param string                                        $name     唯一名称，建议用包名 / 插件标识
     * @param string|RequestStateResetterInterface          $resetter 类名（走容器解析，支持依赖注入）或实例
     * @param int                                           $priority 执行顺序，越小越先
     * @return void
     */
    public static function register(string $name, string|RequestStateResetterInterface $resetter, int $priority = 100): void
    {
        self::$resetters[$name] = ['resetter' => $resetter, 'priority' => $priority];
        self::$sorted           = false;
    }

    /**
     * 是否已注册
     *
     * @param string $name 名称
     * @return bool
     */
    public static function has(string $name): bool
    {
        return isset(self::$resetters[$name]);
    }

    /**
     * 全部重置器（按优先级升序）
     *
     * @return array<string, array{resetter: string|RequestStateResetterInterface, priority: int}>
     */
    public static function all(): array
    {
        if (!self::$sorted) {
            uasort(self::$resetters, static function (array $a, array $b): int {
                return $a['priority'] <=> $b['priority'];
            });
            self::$sorted = true;
        }

        return self::$resetters;
    }

    /**
     * 依次执行所有重置器
     *
     * @return array{ran: string[], failed: array<string, string>} 成功与失败清单
     */
    public static function runAll(): array
    {
        $ran    = [];
        $failed = [];

        foreach (self::all() as $name => $item) {
            try {
                $resetter = self::resolve($item['resetter']);

                if ($resetter === null) {
                    $failed[$name] = '重置器无法解析（类不存在或未实现契约）';
                    continue;
                }

                $resetter->resetRequestState();
                $ran[] = $name;
            } catch (\Throwable $e) {
                $failed[$name] = $e->getMessage();
                self::log("重置器 [{$name}] 执行失败: " . $e->getMessage());
            }
        }

        return ['ran' => $ran, 'failed' => $failed];
    }

    /**
     * 清空注册表（仅用于测试 / 彻底重载）
     *
     * @return void
     */
    public static function flush(): void
    {
        self::$resetters = [];
        self::$sorted    = true;
    }

    /**
     * 解析为实例：类名走容器（支持构造函数依赖注入），实例直接返回
     *
     * @param string|RequestStateResetterInterface $resetter 类名或实例
     * @return RequestStateResetterInterface|null
     */
    private static function resolve(string|RequestStateResetterInterface $resetter): ?RequestStateResetterInterface
    {
        if ($resetter instanceof RequestStateResetterInterface) {
            return $resetter;
        }

        if (!class_exists($resetter)) {
            return null;
        }

        $instance = null;

        if (function_exists('app')) {
            try {
                $instance = app($resetter);
            } catch (\Throwable) {
                $instance = null;
            }
        }

        if (!$instance instanceof RequestStateResetterInterface) {
            try {
                $instance = new $resetter();
            } catch (\Throwable) {
                return null;
            }
        }

        return $instance instanceof RequestStateResetterInterface ? $instance : null;
    }

    /**
     * 记录日志（日志系统不可用时静默，避免重置流程本身成为故障源）
     *
     * @param string $message 内容
     * @return void
     */
    private static function log(string $message): void
    {
        try {
            if (class_exists(\think\facade\Log::class)) {
                \think\facade\Log::warning('[request-state] ' . $message);
            }
        } catch (\Throwable) {
        }
    }
}
