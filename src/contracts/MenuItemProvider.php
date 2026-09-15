<?php
declare(strict_types=1);

namespace wise\support\contracts;

/**
 * 后台菜单声明契约（ExtPoints::MENU_ITEM）
 *
 * 包/插件把菜单「声明」注册到 `menu.item` 扩展点后，由宿主
 * app\admin\service\MenuItemSync 扫描并幂等落地到 menus 表（含菜单派生权限）。
 *
 * 与插件 manifest.json 的 `menus` 共用同一套节点字段与落库原语
 * （Menu::createMenusWithPermissions → MenuPermissionBridge::syncBatch），
 * 保证「插件菜单」与「库包菜单」行为一致。
 *
 * 声明是真相，数据库是运行时：同步幂等可重跑，默认保留运营在后台做的
 * 排序/改名/停用等改动，只有显式 force 才以声明覆盖。
 *
 * 注册示例（包 ServiceProvider::boot()）：
 * ```php
 * ExtPoint::register(ExtPoints::MENU_ITEM, 'wise-member', MenuProvider::class);
 * ```
 *
 * 刷新命令：`php think menu:sync [--package=xxx] [--force] [--prune]`
 */
interface MenuItemProvider
{
    /**
     * 实现标识：写入 menus.plugin，用于幂等匹配与作用域清理
     * （建议与包名一致，如 'wise-member'）
     */
    public function ident(): string;

    /**
     * 父节点声明：菜单树挂载到哪个「已存在」的目录节点下
     *
     * 支持字段：
     *   - name   （必填）父节点名称
     *   - type   （可选）节点类型，默认 'dir'
     *   - plugin （可选）宿主归属，默认 'system'
     *
     * 未匹配到父节点时本次同步**跳过**（不自动创建系统目录，避免污染系统菜单树）。
     *
     * @return array{name:string, type?:string, plugin?:string}
     */
    public function parent(): array;

    /**
     * 菜单树（顶级子项数组）
     *
     * 节点字段与插件 manifest 完全一致，规范见 {@see \wise\support\menu\MenuSchema}：
     *   name / icon / path / component / sort / type(dir|menu|button) / status / children
     *
     * 子节点键 `children` / `child` / `childlist` 均可（统一归一为 `children`），
     * 其余键（mount / pinned / is_plugin_root 等）原样保留并透传给落库层。
     *
     * @return array<int, array<string,mixed>>
     */
    public function items(): array;
}
