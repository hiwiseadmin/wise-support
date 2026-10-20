<?php
declare(strict_types=1);

namespace wise\support\context;

use think\facade\Log;

/**
 * 上下文感知的日志记录器
 *
 * 自动将 RequestContext 中的 request_id / user_id / guard 等
 * 注入到每条日志的 context 参数中，无需各包手动拼接。
 *
 * 两种使用模式：
 *
 * ── 默认通道（静态方法）──
 * <pre>
 * use wise\support\context\ContextualLogger;
 *
 * ContextualLogger::info('支付成功', ['order_id' => 42]);
 * ContextualLogger::error('支付失败', ['order_id' => 42]);
 * </pre>
 *
 * ── 指定通道（channel() 返回实例）──
 * <pre>
 * ContextualLogger::channel('backup')->info('备份开始');
 * ContextualLogger::channel('ai')->error('AI 任务失败', ['task_id' => 42]);
 * </pre>
 *
 * 注意：info/error/warning/debug 等方法既支持静态调用也支持实例调用，
 * 二者均通过 __callStatic / __call 魔法方法路由，避免非静态方法被静态调用时
 * 触发 "Non-static method cannot be called statically" 致命错误。
 *
 * 以下 @method 注解仅为 IDE 提供静态/实例调用提示与跳转，
 * 实际执行仍由 __callStatic / __call 统一路由。
 *
 * @method static void info(string $message, array $context = []) 记录 info 级别日志
 * @method static void error(string $message, array $context = []) 记录 error 级别日志
 * @method static void warning(string $message, array $context = []) 记录 warning 级别日志
 * @method static void debug(string $message, array $context = []) 记录 debug 级别日志
 * @method static void notice(string $message, array $context = []) 记录 notice 级别日志
 * @method static void alert(string $message, array $context = []) 记录 alert 级别日志
 * @method static void critical(string $message, array $context = []) 记录 critical 级别日志
 * @method static void emergency(string $message, array $context = []) 记录 emergency 级别日志
 * @method static void sql(string $message, array $context = []) 记录 SQL 日志（按 debug 级别写入）
 *
 * @package wise\support\context
 */
class ContextualLogger
{
    /**
     * 支持日志级别方法名（静态与实例共用）
     */
    private const LEVELS = [
         'info', 'error', 'warning', 'debug', 'notice',
        'alert', 'critical', 'emergency', 'sql',
    ];

    /**
     * 日志通道名称，null 表示使用默认通道
     */
    private ?string $channel;

    /**
     * @param string|null $channel 日志通道名称，null 为默认通道
     */
    public function __construct(?string $channel = null)
    {
        $this->channel = $channel;
    }

    /**
     * 创建指定通道的上下文日志记录器
     *
     * 用于替换 Log::channel('name')->x() 的场景，
     * 在保持通道隔离的同时自动注入 request_id / user_id / guard。
     *
     * @param string $name 日志通道名称（如 backup、ai、alert、file）
     */
    public static function channel(string $name): self
    {
        return new self($name);
    }

    // ============================================================
    // 实例调用路由 —— $logger->info() / ContextualLogger::channel('x')->info()
    // ============================================================

    /**
     * 实例方法路由（info/error/warning/debug/.../sql）
     */
    public function __call(string $name, array $arguments): void
    {
        $this->dispatch($name, $arguments);
    }

    // ============================================================
    // 静态调用路由 —— ContextualLogger::info() / ::warning() / ...
    // ============================================================

    /**
     * 静态方法路由（向后兼容）
     *
     * 由于同名非静态实例方法会屏蔽 __callStatic，这里统一在 __callStatic 中
     * 创建默认通道实例并委托给实例分发逻辑。
     */
    public static function __callStatic(string $name, array $arguments): void
    {
        (new self())->dispatch($name, $arguments);
    }

    // ============================================================
    // 内部方法
    // ============================================================

    /**
     * 校验方法名并写入日志
     *
     * @param string $name 方法名（日志级别）
     * @param array<int, mixed> $arguments [0 => message, 1 => context]
     */
    private function dispatch(string $name, array $arguments): void
    {
        if (!in_array($name, self::LEVELS, true)) {
            throw new \BadMethodCallException("Method ContextualLogger::{$name}() does not exist.");
        }

        $message = (string) ($arguments[0] ?? '');
        $context = (array) ($arguments[1] ?? []);

        if ($name === 'sql') {
            $level = 'debug';
            $message = '[SQL] ' . $message;
        } else {
            $level = $name;
        }

        if ($this->channel === null) {
            Log::$level($message, self::mergeContext($context));
        } else {
            Log::channel($this->channel)->$level($message, self::mergeContext($context));
        }
    }

    /**
     * 合并 RequestContext 到日志上下文
     *
     * 用户提供的 context 键优先级高于自动注入的键。
     *
     * @param array<string, mixed> $custom 业务方提供的额外上下文
     * @return array<string, mixed>
     */
    private static function mergeContext(array $custom): array
    {
        return array_merge(
            RequestContext::logContext(),
            $custom,
        );
    }
}
