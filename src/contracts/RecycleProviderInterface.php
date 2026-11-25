<?php
declare(strict_types=1);

namespace wise\support\contracts;

/**
 * 回收站模型提供者契约（ExtPoints::RECYCLE_PROVIDER）
 *
 * 各来源（宿主 config / wise-* 包 / 插件）把「纳入全局回收站的软删模型」声明给
 * 本扩展点，宿主 `app\common\service\RecycleRegistry` 汇总为一份清单：
 *   - 宿主   ：`AppService::boot()` 读 `config/recycle.php` 注册（name=`system`）
 *   - 包     ：各自 `ServiceProvider::boot()` 注册（可读自己的 config 段）
 *   - 插件   ：`WiseBootServiceProvider::registerRecycle()` 一行接入
 *
 * 三类注册形态（RecycleRegistry 统一归一）：
 *   1. 类名字符串（实现本接口）→ 容器解析后调 recycleModels()
 *   2. 闭包（返回模型清单数组）→ 直接调用
 *   3. 数组（模型清单）→ 原样使用
 *
 * 不注册 = 不出现在回收站（fail-closed，任务卡验收 #6）。
 *
 * 模型清单结构：
 * ```php
 * [
 *     \app\admin\model\Menu::class => [
 *         'title'  => 'name',          // 标题字段（缺省回退主键/首个字符串字段）
 *         'group'  => '系统',           // 分组名（缺省按模型名）
 *         'fields' => ['name', 'path'], // 列表额外展示字段（可选）
 *     ],
 * ]
 * ```
 */
interface RecycleProviderInterface
{
    /**
     * 回收站模型清单（模型 FQCN => 选项）
     *
     * @return array<string, array{title?: string, group?: string, fields?: array<int, string>}>
     */
    public function recycleModels(): array;
}
