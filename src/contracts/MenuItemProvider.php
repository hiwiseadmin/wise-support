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
 * ── 容器（containers）──────────────────────────────────────────────
 * 侧边栏模板只渲染三层（系统顶级 → 一级目录 → 叶子），因此「分组」必须表现为
 * **一级目录**。实现通过 {@see self::containers()} 声明容器，每个容器 = 一个目录
 * （或启动台入口）；{@see self::items()} 中的节点用 `container` 键归属容器
 * （缺省归第一个容器）。**节点不参与决定落点** —— 去向完全由所属容器的
 * `mount` / `anchor` 决定。
 *
 * 容器去向三态（`mount = LAUNCHER` **优先级最高**）：
 *   - `mount = LAUNCHER`（启动台入口）：落库顶层 `parent_id=0` + `is_plugin_root=1` +
 *     `mount=3` → 进「应用启动台」，可钉选到「常用插件区」。一个实现**只取第一个**
 *     声明者作入口，其余降级为普通分组并告警；
 *   - `anchor = 'console'`（默认）：创建在系统顶级「控制台」下，**归属本实现**
 *     （plugin = ident），prune / uninstall 可连带清理（卸载无痕）；
 *   - `anchor = 已存在目录名`（如「日志管理」「插件管理」）：节点直接挂到该框架目录下
 *     （**不创建、不拥有**；目录不存在时该容器整批跳过）。侧边栏只渲染三层，故该目录
 *     须是**一级目录**，否则其子节点会落到第 4 层渲染不出来 —— 同步只**告警不阻断**，
 *     运营可在「菜单管理」调整；
 *   - `anchor = 'launcher'`（或**入口容器的 key**）：挂到本实现的启动台入口下作
 *     **子分组**，层级固定「入口(顶级) → 子分组(一级) → 页面(二级)」= 三层。
 *     本实现没有 LAUNCHER 容器时：告警并回退「控制台」。
 *
 * 排序：容器 sort 越大越靠后；框架自身域占用 100-999，业务包域建议 1000 起
 * （见 menus 种子迁移里的排序约定）。自持目录（console / 入口 / launcher 子分组）
 * 参与排序：已存在容器默认**不改写** sort（保留运营拖动结果），需要按声明刷新时执行
 * `php think menu:sync --force`；挂靠既有目录时顺序由该目录的归属方决定。
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
     * 容器声明（一个或多个分组目录）
     *
     * 支持字段：
     *   - key         （必填）容器标识，节点用 `container` 键引用
     *   - name        （必填）目录名称（`anchor` 为既有目录名时可省略，取 anchor 值）
     *   - anchor      （可选）容器去向，三态：
     *                   · `'console'`（默认）→ 控制台根下的**自持**一级目录
     *                   · 已存在目录名（如「日志管理」）→ 挂靠该目录，不创建、不拥有
     *                   · `'launcher'` 或**入口容器的 key** → 挂到启动台入口下作子分组
     *                   （优先级低于 `mount = LAUNCHER`；详见类注释「容器去向三态」）
     *   - icon        （可选）Bootstrap Icons 类名，仅在自持容器创建时使用
     *   - sort        （可选）排序号，越大越靠后，默认 0
     *   - mount       （可选）挂载模式，默认 {@see MenuMount::MODULE}（0）。
     *                   常量定义在**契约层** {@see MenuMount}，包**不要**引用宿主模型
     *                   （`app\admin\model\Menu::MOUNT_*` 只是宿主 BC 别名）：
     *
     *                   | 常量 | 值 | 语义 |
     *                   |------|:--:|------|
     *                   | `MODULE` | 0 | 顶级模块（默认） |
     *                   | `SIDEBAR_GROUP` | 1 | 侧边栏独立可折叠分组 |
     *                   | `SIDEBAR_FIXED` | 2 | 归入固定分组 |
     *                   | `LAUNCHER` | 3 | **应用启动台条目 + 可钉选** |
     *
     *                   库包形态（`mount` + `anchor` 组合，用于分流"wise-* 包多 → 侧边栏拥挤"）：
     *
     *                   | 声明 | 落库 | 呈现 |
     *                   |------|------|------|
     *                   | 缺省 / `MODULE` | 控制台下一级目录 | 侧边栏分组（默认形态） |
     *                   | `LAUNCHER` | `parent_id=0` + `is_plugin_root=1` + `mount=3` | 启动台**单入口**，钉选后进「常用插件区」 |
     *
     *                   典型「一个包 = 一个启动台入口 + 同级业务分组」（层级 ≤ 3）：
     *
     *                   ```php
     *                   containers(): [
     *                       // ① 启动台入口：只作条目；名称必须与业务分组区分开（否则出现双层同名）
     *                       ['key'=>'entry',  'name'=>'应用市场', 'icon'=>'bi bi-shop', 'mount'=>MenuMount::LAUNCHER],
     *                       // ② 业务分组：anchor='launcher' → 挂到入口下，彼此**同级**
     *                       ['key'=>'market', 'name'=>'应用管理',   'anchor'=>'launcher', 'sort'=>1000],
     *                       ['key'=>'audit',  'name'=>'审核与许可', 'anchor'=>'launcher', 'sort'=>1010],
     *                   ]
     *                   // items()：container=market → 插件列表…；container=audit → 审核管理…
     *                   ```
     *
     *                   ⚠️ 不要让业务分组自己承担 `mount=LAUNCHER` —— 那样该分组的页面会与其它分组
     *                   变成同级（入口必须是**独立的**容器）。
     *
     *                   启动台图标：库包没有 manifest 可读 → 取该容器自身的 `icon`；
     *                   若同名插件声明了顶层 `icon`，则插件 icon 优先。
     *
     *                   插件**不受影响、也不需要声明**：插件归属由「顶级菜单数量」自动分流
     *                   （=1 → 插件扩展；≥2 → 启动台）。
     *   - description （可选）目录描述
     *
     * @return array<int, array{
     *     key:string, name?:string, anchor?:string, icon?:string,
     *     sort?:int, mount?:int, description?:string
     * }>
     */
    public function containers(): array;

    /**
     * 菜单树（节点数组，可跨容器混排）
     *
     * 节点字段与插件 manifest 完全一致，规范见 {@see \wise\support\menu\MenuSchema}：
     *   container / name / icon / path / component / sort / type(dir|menu|button) / status / children
     *
     * `container` 仅用于落库分组，**不写入 menus 表**；缺省归第一个容器。
     * 子节点键 `children` / `child` / `childlist` 均可（统一归一为 `children`），
     * 其余键（mount / pinned / is_plugin_root 等）原样保留并透传给落库层。
     *
     * @return array<int, array<string,mixed>>
     */
    public function items(): array;
}
