<?php
declare(strict_types=1);

namespace wise\support\contracts;

/**
 * 字段级变更记录落地方（sink）契约
 *
 * wise-support 只负责「采集」，不关心数据落到哪张表：
 *   - 宿主实现本接口，把变更写入 operation_log_changes（或任何自有存储）；
 *   - 未注册 sink 时 {@see \wise\support\changelog\ChangeLogManager::push()}
 *     为静默 no-op，保证 wise-support 被单独 composer 引用时不产生副作用。
 *
 * 实现方要求：
 *   - **必须自行吞掉所有异常**（采集发生在业务写入路径，不能因审计失败影响业务）；
 *   - 若走队列，投递失败需降级为直接落库（不允许静默丢数据）。
 */
interface ChangeLogSinkInterface
{
    /**
     * 接收一条模型变更
     *
     * 结构（由 {@see \wise\support\traits\ChangeLoggable} 组装）：
     * ```
     * [
     *   'model'        => 'plugin\member\model\Member',  // 模型全类名
     *   'label'        => '会员',                       // 展示名
     *   'pk_field'     => 'id',
     *   'pk_value'     => '12',
     *   'title'        => '张三',                       // 行标题（可空）
     *   'action'       => 'update',                     // update|create|rollback
     *   'changes'      => ['nickname' => ['old' => 'a', 'new' => 'b']],
     *   'field_labels' => ['nickname' => '昵称'],
     *   'time'         => '2026-11-05 14:00:00',
     * ]
     * ```
     *
     * @param array<string, mixed> $entry
     */
    public function push(array $entry): void;
}
