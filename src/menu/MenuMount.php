<?php
declare(strict_types=1);

namespace wise\support\menu;

/**
 * 菜单容器「挂载模式」常量 —— **菜单声明契约的一部分**（与 {@see MenuSchema} 同级）
 *
 * 为什么放在 wise-support 而不是宿主 `app\admin\model\Menu`
 * ------------------------------------------------------
 * 库包（wise-*）在 {@see \wise\support\contracts\MenuItemProvider::containers()} 里声明
 * `mount` 时，如果只能引用宿主的 `Menu::MOUNT_LAUNCHER`，包就**被迫依赖宿主 app 命名空间**：
 * 单独 composer 安装该包时 fatal，且会被「包内不得 `use app\...`」的护栏拦下。
 * 因此**定义下沉到契约层**，宿主 `Menu::MOUNT_*` 仅保留为 BC 别名（值同源，不会漂移）。
 *
 * 四种模式
 * --------
 * | 常量 | 值 | 语义 |
 * |------|:--:|------|
 * | {@see self::MODULE} | 0 | 顶级模块：占一个顶部 Tab，内部菜单树收进侧边栏（默认） |
 * | {@see self::SIDEBAR_GROUP} | 1 | 侧边栏独立可折叠分组，不占顶部 |
 * | {@see self::SIDEBAR_FIXED} | 2 | 归入固定「插件中心」分组 |
 * | {@see self::LAUNCHER} | 3 | **应用启动台**：容器挂到顶层并作为启动台条目，**可钉选**到常用插件区 |
 *
 * 库包接入启动台（解决 wise-* 包多、侧边栏拥挤）
 * ---------------------------------------------
 * ```php
 * public function containers(): array
 * {
 *     return [
 *         // ① 启动台入口：整包在启动台的**唯一**条目（可钉选）；只作条目，不承载 items
 *         ['key' => 'entry', 'name' => '应用市场', 'icon' => 'bi bi-shop',
 *          'mount' => MenuMount::LAUNCHER],
 *
 *         // ② 业务分组：anchor = 'launcher' → 挂到入口下作子分组（彼此同级）
 *         //    不要给分组也写 LAUNCHER，否则启动台会出现多个入口、就不是"一个包"了
 *         ['key' => 'market', 'name' => '应用管理',   'anchor' => 'launcher', 'sort' => 1000],
 *         ['key' => 'audit',  'name' => '审核与许可', 'anchor' => 'launcher', 'sort' => 1010],
 *     ];
 * }
 * ```
 * 落库效果（由 `Menu::ensureContainer()` 执行）：入口 `parent_id = 0`、`is_plugin_root = 1`、
 * `mount = 3`；子分组落在入口下（`mount = MODULE`、`is_plugin_root = 0`）。
 * 渲染效果（由 `MenuLauncherService::classifyMenuLayout()` 判定）：整包在「应用启动台」
 * **只出一条**且 `canPin = 1`；钉选后进入「常用插件区」；侧边栏层级固定
 * 「入口(顶级) → 子分组(一级) → 页面(二级)」= 三层（模板只渲染三层）。
 *
 * 另：`anchor` 也可以指向**已有的框架目录名**（如 `'日志管理'`、`'插件管理'`）——
 * 此时挂靠该目录、不创建不拥有；若该目录不在侧边栏一级，同步会告警（只告警不阻断）。
 *
 * 插件侧不受影响：插件的归属由「顶级菜单数量」自动分流（1 个 → 插件扩展；≥2 个 → 启动台），
 * 插件不需要也不应该声明本模式。
 *
 * @see \wise\support\contracts\MenuItemProvider::containers()
 * @see \app\admin\service\MenuItemSync::resolveContainer()
 * @see \app\common\service\MenuLauncherService::classifyMenuLayout()
 */
final class MenuMount
{
    /** 顶级模块：占一个顶部 Tab，内部菜单树收进侧边栏（默认） */
    public const MODULE = 0;

    /** 侧边栏独立可折叠分组，不占顶部 */
    public const SIDEBAR_GROUP = 1;

    /** 归入固定「插件中心」分组 */
    public const SIDEBAR_FIXED = 2;

    /** 应用启动台：顶层条目 + 可钉选到常用插件区 */
    public const LAUNCHER = 3;

    /**
     * 全部模式与中文名（后台布局页/文档复用，避免各处再写一份映射）
     *
     * @return array<int, string>
     */
    public static function labels(): array
    {
        return [
            self::MODULE        => '顶级模块',
            self::SIDEBAR_GROUP => '侧边栏分组',
            self::SIDEBAR_FIXED => '固定分组',
            self::LAUNCHER      => '应用启动台',
        ];
    }

    /**
     * 模式中文名（未知值原样回显，便于排障）
     */
    public static function label(int $mount): string
    {
        return self::labels()[$mount] ?? ('未知(' . $mount . ')');
    }

    /**
     * 是否为「启动台」模式
     */
    public static function isLauncher(int $mount): bool
    {
        return $mount === self::LAUNCHER;
    }
}
