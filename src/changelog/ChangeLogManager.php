<?php
declare(strict_types=1);

namespace wise\support\changelog;

use wise\support\contracts\ChangeLogSinkInterface;

/**
 * 字段级变更记录分发器（采集侧唯一入口）
 *
 * 职责边界：
 *   - wise-support 定义「怎么采」（{@see \wise\support\traits\ChangeLoggable}）与「往哪送」（本类）；
 *   - `operation_log_changes` 表结构、脱敏、截断、队列、查询、回滚全部由宿主的 sink 实现，
 *     避免基础设施包反向依赖宿主服务。
 *
 * Swoole / 常驻进程注意：sink 为静态注册，随应用 boot 每进程注册一次即可；
 * 未注册时 push() 静默返回，不抛异常、不写日志（避免污染业务链路）。
 */
final class ChangeLogManager
{
    /** @var ChangeLogSinkInterface|callable|null */
    private static $sink = null;

    /** 全局开关（默认开；关闭后所有 trait 采集点立即静默） */
    private static bool $enabled = true;

    /**
     * 注册 sink
     *
     * @param ChangeLogSinkInterface|callable(array):void|null $sink null 表示注销
     */
    public static function registerSink(ChangeLogSinkInterface|callable|null $sink): void
    {
        self::$sink = $sink;
    }

    /**
     * @return ChangeLogSinkInterface|callable|null
     */
    public static function sink()
    {
        return self::$sink;
    }

    public static function enabled(): bool
    {
        return self::$enabled && self::$sink !== null;
    }

    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    /**
     * 分发一条变更
     *
     * 已在业务写入路径上，任何异常都必须吞掉（审计失败不能影响业务写）。
     *
     * @param array<string, mixed> $entry
     */
    public static function push(array $entry): void
    {
        if (!self::enabled()) {
            return;
        }

        $sink = self::$sink;

        try {
            if ($sink instanceof ChangeLogSinkInterface) {
                $sink->push($entry);
            } elseif (is_callable($sink)) {
                $sink($entry);
            }
        } catch (\Throwable) {
        }
    }

    /** 复位（测试 / 请求级隔离用） */
    public static function reset(): void
    {
        self::$sink = null;
        self::$enabled = true;
    }
}
