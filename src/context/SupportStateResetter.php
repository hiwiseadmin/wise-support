<?php
declare(strict_types=1);

namespace wise\support\context;

use wise\support\contracts\RequestStateResetterInterface;
use wise\support\ExtPoint;
use wise\support\Once;

/**
 * wise-support 请求级状态重置器
 *
 * 约定：**一个包 / 插件一个重置器类**。重置器的粒度是「包」，不是「状态项」——
 * 同类内部按固定顺序清完即可，无需为每个静态字段各建一个类（否则注册表会膨胀成
 * 一张清单，反而看不清每个包到底持有什么状态）。
 *
 * 本类清理 wise-support 自己持有的三处请求级静态状态，顺序不可乱：
 *
 *  1. `Once`（memoize 静态缓存）
 *     `once()` 用静态缓存实现「同一请求内只执行一次」。FPM 下静态属性随进程回收，
 *     常驻运行时下会活到 worker 结束，语义退化为「同一 worker 只执行一次」，
 *     身份 / 租户 / 权限等值会跨请求串号，且 `$closureRefs` 无界增长。
 *
 *  2. `ExtPoint`（活跃实现 + 覆盖表缓存）
 *     **注册数据**是 worker 级资产（boot 阶段注册，重启才变），不重置；
 *     但「活跃实现」「覆盖表缓存」由单个请求写入（能力市场切换、测试内注册），
 *     必须回退，否则上一个请求的切换会污染下一个请求。
 *     传 `false`：请求边界不重读持久化覆盖表，只回退到注册默认；
 *     下一请求开始后由 `HttpRun` 钩子调用 `applyPersistedOverrides()` 重新加载。
 *     必须在 `Once::flush()` 之后：活跃态回退过程中若走 `once()`，落到的是新缓存。
 *
 *  3. `RequestContext`（请求上下文桶）
 *     协程运行时按协程隔离，天然随协程销毁；非协程常驻运行时（Swoole 同步 worker /
 *     Workerman / 队列 worker）所有请求共用 `global` 桶，不清理会把上一位用户的
 *     身份 / 租户带进下一个请求。
 *
 * @package wise\support\context
 */
class SupportStateResetter implements RequestStateResetterInterface
{
    /**
     * {@inheritDoc}
     */
    public function resetRequestState(): void
    {
        Once::flush();

        ExtPoint::resetRequestState(false);

        RequestContext::clear();
    }
}
