<?php
declare(strict_types=1);

namespace wise\support\menu;

/**
 * 后台菜单节点规范 —— 插件 manifest 与库包 MenuItemProvider 共用的唯一 schema
 *
 * 字段（规范化输出）：
 *   name       string  必填，节点名（父菜单定位也以 name 为准）
 *   icon       string  可选，Bootstrap Icons 类名，默认 ''
 *   path       string  可选，路由地址（同时作为派生权限的依据），默认 ''
 *   component  string  可选，前端组件标识（预留），默认 ''
 *   sort       int     可选，同级排序，默认 0
 *   type       string  可选，dir|menu|button，默认 dir；非法值回落 dir
 *   status     int     可选，1=显示 0=隐藏，默认 1
 *   children   array   可选，子节点数组
 *
 * 兼容别名：子节点可写 `children` / `child` / `childlist`，统一收敛为 `children`。
 * 其余键（如 `mount` / `pinned` / `is_plugin_root` / `group` / `description` /
 * `permission_id`）**原样保留**，不做裁剪——本规范只做「归一 + 补默认」，不做破坏性过滤。
 *
 * 使用方：
 *   - 插件 manifest 菜单：\wise\plugin\adapter\MenuRegistrar
 *   - 库包声明菜单：app\admin\service\MenuItemSync
 * 两条链路都经 normalizeTree() 后再落库，避免「两套写法各自漂移」。
 */
final class MenuSchema
{
    public const TYPE_DIR    = 'dir';
    public const TYPE_MENU   = 'menu';
    public const TYPE_BUTTON = 'button';

    /** 合法节点类型 */
    public const TYPES = [self::TYPE_DIR, self::TYPE_MENU, self::TYPE_BUTTON];

    public const DEFAULT_TYPE   = self::TYPE_DIR;
    public const DEFAULT_STATUS = 1;

    /** 子节点键名别名（按优先级） */
    public const CHILD_KEYS = ['children', 'child', 'childlist'];

    /**
     * 归一菜单树（递归）
     *
     * 非数组或缺 name 的节点视为非法，直接丢弃（避免脏数据落库）。
     *
     * @param  array<int|string, mixed> $items
     * @return array<int, array<string,mixed>>
     */
    public static function normalizeTree(array $items): array
    {
        $nodes = [];

        foreach ($items as $item) {
            $node = self::normalizeNode($item);
            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * 归一单个节点
     *
     * @param  mixed $item
     * @return array<string,mixed>|null 缺 name 时返回 null
     */
    public static function normalizeNode($item): ?array
    {
        if (!is_array($item)) {
            return null;
        }

        $name = trim((string) ($item['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $type = strtolower(trim((string) ($item['type'] ?? self::DEFAULT_TYPE)));
        if (!in_array($type, self::TYPES, true)) {
            $type = self::DEFAULT_TYPE;
        }

        // 保留原键（mount/pinned/is_plugin_root/description 等由落库层消费）
        $node = $item;

        $node['name']      = $name;
        $node['icon']      = (string) ($item['icon'] ?? '');
        $node['path']      = (string) ($item['path'] ?? '');
        $node['component'] = (string) ($item['component'] ?? '');
        $node['sort']      = (int) ($item['sort'] ?? 0);
        $node['type']      = $type;
        $node['status']    = (int) ($item['status'] ?? self::DEFAULT_STATUS);

        // 子节点：收敛别名
        $children = self::childrenOf($item);
        unset($node['child'], $node['childlist']);

        if (!empty($children)) {
            $node['children'] = self::normalizeTree($children);
        } else {
            unset($node['children']);
        }

        return $node;
    }

    /**
     * 取出子节点（兼容 children / child / childlist）
     *
     * 单节点写法（非列表）自动包一层，调用方拿到的一定是列表。
     *
     * @param  array<string,mixed> $item
     * @return array<int, mixed>
     */
    public static function childrenOf(array $item): array
    {
        foreach (self::CHILD_KEYS as $key) {
            if (empty($item[$key]) || !is_array($item[$key])) {
                continue;
            }

            $children = $item[$key];

            return isset($children[0]) ? $children : [$children];
        }

        return [];
    }

    /**
     * 统计节点总数（含子节点）
     *
     * @param array<int, array<string,mixed>> $items
     */
    public static function countNodes(array $items): int
    {
        $count = 0;

        foreach ($items as $item) {
            $count++;
            $count += self::countNodes(self::childrenOf($item));
        }

        return $count;
    }
}
