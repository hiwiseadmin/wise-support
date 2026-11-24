<?php
declare(strict_types=1);

namespace wise\support\contracts;

/**
 * 请求级状态重置器契约
 *
 * 为什么需要它
 * ------------
 * FPM 下每个请求独占进程，`static` 属性、容器单例、连接句柄都随进程结束回收；
 * 常驻运行时（Swoole / Workerman）下进程不重启，这些状态会**跨请求存活**，
 * 导致三类事故：
 *   1. 身份/租户/权限等请求级值串号（安全级）；
 *   2. 连接句柄（Redis / FTP / cURL）被多个协程共享（数据级）；
 *   3. 缓存只增不减（内存级）。
 *
 * 统一机制
 * --------
 * 框架、包、插件**只实现本契约并注册到** {@see \wise\support\context\RequestStateRegistry}，
 * 由宿主在统一的时机调度，实现方不需要知道当前跑在 FPM 还是 Swoole：
 *   - Swoole 协程：宿主 ResetterInterface 桥接器 → 每个请求**开始前**执行；
 *   - FPM / 非协程常驻：`HttpEnd` 事件兜底执行（协程内自动去重）。
 *
 * 实现要求
 * --------
 * - **幂等**：同一请求内可能被执行多次（例如异常路径 + 事件路径），重复执行必须无副作用；
 * - **只清请求级状态**：配置（worker 级）、连接池、只读缓存不得清理；
 * - **不得抛异常**：无法清理时自行降级并记录日志（注册表也会兜住异常，不中断其它实现）。
 *
 * @package wise\support\contracts
 */
interface RequestStateResetterInterface
{
    /**
     * 重置请求级状态
     *
     * @return void
     */
    public function resetRequestState(): void;
}
