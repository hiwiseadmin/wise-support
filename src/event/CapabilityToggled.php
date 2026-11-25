<?php

declare(strict_types=1);

namespace wise\support\event;

/**
 * 扩展点「活跃实现被切换」事件
 *
 * 由 {@see \wise\support\ExtPoint::setActive()} 在状态**实际变更成功后**派发
 * （授权被拒 / 实现不存在时不派发）。关注方（业务包）自行监听并响应，
 * 变更方（如能力市场控制器）无需感知任何业务包。
 *
 * 使用示例（在包 ServiceProvider 或宿主 event.php 中注册）：
 * <pre>
 * use think\facade\Event;
 * use wise\support\event\CapabilityToggled;
 *
 * Event::listen(CapabilityToggled::class, function (CapabilityToggled $e) {
 *     // 仅关心自己的点，其余直接返回
 *     if ($e->point !== MyRegistry::EXT_POINT) {
 *         return;
 *     }
 *     // 审计留痕 / 缓存失效 / 通知等
 * });
 * </pre>
 *
 * 说明：`before` 为**变更前**的真实状态（而非 `!$after`）——single 策略下切换
 * 一个实现会连带停用同点其他实现，简单取反会与实际不符。
 */
final class CapabilityToggled
{
    /**
     * @param string $point  扩展点名称（如 'member.feature'）
     * @param string $name   实现标识（如 'demo:achievements'）
     * @param bool   $before 变更前该实现是否活跃
     * @param bool   $after  变更后该实现是否活跃
     * @param string $source 变更来源（'admin' 运营操作 / 'system' 程序）
     */
    public function __construct(
        public readonly string $point,
        public readonly string $name,
        public readonly bool $before,
        public readonly bool $after,
        public readonly string $source = 'system',
    ) {}
}
