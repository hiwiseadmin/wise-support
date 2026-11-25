<?php
declare(strict_types=1);

namespace wise\support\traits;

use wise\support\changelog\ChangeLogManager;

/**
 * 字段级变更采集＼�
 *
 * 用法：在需要审计的业务模型里 `use ChangeLoggable;`，并在模型内实现两个事件钩子。
 *
 * ```php
 * class Member extends Model
 * {
 *     use \wise\support\traits\ChangeLoggable;
 *
 *     protected static function onAfterInsert($model): void { $model->changeLogOnInsert(); }
 *     protected static function onAfterWrite($model): void  { $model->changeLogOnWrite(); }
 *
 *     protected function changeLogLabel(): string      { return '会员'; }
 *     protected function changeLogTitleField(): string { return 'nickname'; }
 *     protected function changeLogExcept(): array      { return ['last_login_ip']; }
 * }
 * ```
 *
 * 为什么配置用「方法」而不是「属性」：
 *   trait 与类同时声明同名属性时，PHP 要求定义完全一致（含默认值），
 *   否则报 "define the same property ... considered incompatible" 致命错误；
 *   方法覆写没有这个限制，各模型可在不改 trait 的前提下自由定制。
 *
 * 为什么按模型接入而不是塞进 BaseModel：
 *   - 包 / 插件模型（如 member 插件的 `Member`）直接 `extends think\Model`，
 *     插件模型 `extends plugin\BaseModel`，宿主 BaseModel 根本覆盖不到；
 *   - 只有敏感业务表需要留痕，常规流水表全量采集纯属噪音与开销；
 *   - 未接入的模型零成本 —— 批量导入（验收 #19）不受影响。
 *
 * 采集窗口（TP8 事实，见 think-orm Model.php:761-837 / 606-643）：
 *   - **新增**：`insertData()` 在触发 `AfterInsert` 前已 `origin = data`，
 *     所以必须在模型的 `onAfterInsert` 里调用 `changeLogOnInsert()`，且老值为 null；
 *   - **更新**：`updateData()` 不改 origin，模型的 `onAfterWrite` 里
 *     `getChangedData()` 才是真正的差异，老值取自 `getOrigin()`；
 *   - 新增时随后触发的 `onAfterWrite` 因 origin 已被重置而拿到空差异，天然不会重复记录。
 *
 * 铁律：本 trait 的任何异常都不得冒泡到业务写入路径。
 */
trait ChangeLoggable
{

    /** 新增采集实现（实例方法，可访问受保护数据） */
    protected function changeLogOnInsert(): void
    {
        if (!$this->changeLogRecordCreate()) {
            return;
        }

        $data    = $this->getData();
        $fields  = $this->changeLogFields() ?? array_keys($data);
        $changes = [];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $changes[$field] = ['old' => null, 'new' => $data[$field]];
        }

        $this->dispatchChangeLog('create', $changes);
    }

    /** 更新采集实现（实例方法，可访问 origin / 差异数据） */
    protected function changeLogOnWrite(): void
    {
        $changed = $this->getChangedData();
        if (empty($changed)) {
            return;
        }

        $changes = [];
        foreach ($changed as $field => $new) {
            $changes[$field] = ['old' => $this->getOrigin($field), 'new' => $new];
        }

        $this->dispatchChangeLog('update', $changes);
    }


    /** 单模型总开关 */
    protected function changeLogEnabled(): bool
    {
        return true;
    }

    /** 记录展示名（如「文章」「会员」），缺省用模型短类名 */
    protected function changeLogLabel(): string
    {
        return '';
    }

    /** 标题字段（供时间线展示行标题），缺省取主键值 */
    protected function changeLogTitleField(): string
    {
        return '';
    }

    /**
     * 仅采集这些字段（null = 全部字段）
     *
     * @return array<int, string>|null
     */
    protected function changeLogFields(): ?array
    {
        return null;
    }

    /**
     * 该模型**额外**排除的字段（在基础设施字段之外追加，不是替换）
     *
     * 基础设施字段（create_time / update_time / delete_time）由
     * {@see filterChangeLogChanges()} 恒定排除，模型侧**不要重复声明** ——
     * 这里只写「本模型的领域噪音」（如登录轨迹、UI 偏好 JSON）。
     *
     * @return array<int, string>
     */
    protected function changeLogExcept(): array
    {
        return [];
    }

    /**
     * 基础设施字段（恒定排除；模型不要覆写）
     *
     * 为什么硬编码在 trait 而不是读 `config/change_log.php`：
     * `wise-support` 是内核包，设计边界是「不读宿主配置」（见 ChangeLogManager 头注释）。
     *
     * 与宿主 `config/change_log.php` 的 `ignore_fields` **故意重复**（纵深防御）：
     *   - 本方法只覆盖「模型事件」这条采集路径；
     *   - 宿主配置那道闸额外兜住手工 `push()`（如回滚留痕 recordRollback）。
     * 调整口径时两处都要改，新增基础设施字段同理。
     *
     * @return array<int, string>
     */
    protected function changeLogBaseExcept(): array
    {
        return ['create_time', 'update_time', 'delete_time'];
    }

    /**
     * 字段中文名映射：[field => 中文名]
     *
     * @return array<string, string>
     */
    protected function changeLogFieldLabels(): array
    {
        return [];
    }

    /** 是否记录「新增」快照（默认关闭：新增快照行数多、信息价值低于变更） */
    protected function changeLogRecordCreate(): bool
    {
        return false;
    }


    /**
     * 组装并分发（全部异常在此吞掉）
     *
     * @param array<string, array{old: mixed, new: mixed}> $changes
     */
    protected function dispatchChangeLog(string $action, array $changes): void
    {
        try {
            if (!$this->changeLogEnabled() || !ChangeLogManager::enabled()) {
                return;
            }

            $changes = $this->filterChangeLogChanges($changes);
            if (empty($changes)) {
                return;
            }

            $data    = $this->getData();
            $pkField = (string) $this->getPk();
            $pkValue = (string) ($data[$pkField] ?? '');

            if ($pkValue === '') {
                return;
            }

            $titleField = $this->changeLogTitleField();
            $title      = $titleField !== '' && isset($data[$titleField]) && is_scalar($data[$titleField])
                ? (string) $data[$titleField]
                : '';

            ChangeLogManager::push([
                'model'        => static::class,
                'label'        => $this->changeLogLabel() !== ''
                    ? $this->changeLogLabel()
                    : (new \ReflectionClass(static::class))->getShortName(),
                'pk_field'     => $pkField,
                'pk_value'     => $pkValue,
                'title'        => $title,
                'action'       => $action,
                'changes'      => $changes,
                'field_labels' => $this->changeLogFieldLabels(),
                'time'         => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
        }
    }

    /**
     * 字段级过滤（只做模型级白/黑名单；配置级黑名单与截断由 sink 负责）
     *
     * 黑名单语义是「基础排除 + 模型追加」：
     *   - 基础排除恒定生效（ORM 自己写的时间戳，任何模型都无审计价值）；
     *   - 模型侧 {@see changeLogExcept()} 只声明自己额外的噪音字段。
     * 之所以由 trait 固定基础集，是因为模型覆写 changeLogExcept() 会**整体替换**默认实现，
     * 若把时间戳留给模型自己重复声明，漏写一处就会冒出 update_time 噪音行。
     *
     * @param array<string, array{old: mixed, new: mixed}> $changes
     * @return array<string, array{old: mixed, new: mixed}>
     */
    protected function filterChangeLogChanges(array $changes): array
    {
        $fields = $this->changeLogFields();
        $allow  = is_array($fields) ? array_flip($fields) : null;
        $except = array_flip(array_merge($this->changeLogBaseExcept(), $this->changeLogExcept()));

        $out = [];
        foreach ($changes as $field => $pair) {
            $field = (string) $field;

            if ($allow !== null && !isset($allow[$field])) {
                continue;
            }
            if (isset($except[$field])) {
                continue;
            }

            $old = $pair['old'] ?? null;
            $new = $pair['new'] ?? null;

            if ($old === $new) {
                continue;
            }

            $out[$field] = ['old' => $old, 'new' => $new];
        }

        return $out;
    }
}
