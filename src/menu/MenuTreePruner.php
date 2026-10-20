<?php

declare(strict_types=1);

namespace wise\support\menu;

/**
 * 菜单树差集解析 —— 判定「声明中已不存在、可安全清理」的节点（**纯函数，无 IO**）。
 *
 * 为什么要单独抽一层：**声明 → 数据库** 的差集口径只能有一份。两条链路都要用它：
 *
 * | 链路 | 入口 |
 * |:---|:---|
 * | 库包 / 扩展点实现 | `MenuItemSync::prune()`（`php think menu:sync --prune`） |
 * | 插件 manifest | `MenuRegistrar::prune()`（插件升级、`php think plugin:menu-migrate --prune`） |
 *
 * 若各自实现，必然出现「同一份声明在两条链路上清出不同结果」的口径漂移。
 *
 * 身份口径与落库原语（`Menu::createMenusWithPermissions()` →
 * `MenuPermissionBridge::findOrCreateMenu()`）保持一致：**有 path 用 path，无 path 用 name**。
 *
 * 三条守卫（缺一条就会误删）：
 *
 * 1. **根容器永不删**：插件根节点（`is_plugin_root = 1`）由框架生成、**不在 manifest 声明里**，
 *    删掉会让整个插件的菜单树失去入口；
 * 2. **祖先链不拆散**：只要某节点自身在声明中（或本身是根），其**全部祖先**一并保留 ——
 *    否则会把「仍有已声明子节点的父目录」删掉，留下 `parent_id` 指向已删行的孤儿菜单；
 * 3. **未声明子树整棵清**：与声明完全无关的子树（含其内部父目录）一次性全部返回，
 *    避免只清叶子、把空壳目录永久留在库里。
 *
 * @see \app\admin\service\MenuItemSync::prune()
 * @see \wise\plugin\adapter\MenuRegistrar::prune()
 */
class MenuTreePruner
{
    /**
     * 从声明树收集身份键（有 path 用 path，无 path 用 name；**递归全部子层**）。
     *
     * 身份键的粒度是「全局」而非「父节点下」：同一个 path 出现在任意位置都算已声明。
     * 这是**偏保守**的一侧 —— 宁可少删（留下重复节点由人工处理），也不要因位置变化误删。
     *
     * @param  array<int|string, mixed> $items 声明树（建议先经 {@see MenuSchema::normalizeTree()} 归一）
     * @return array{0: array<int, string>, 1: array<int, string>} `[path 集合, name 集合]`
     */
    public static function collectIdentity(array $items): array
    {
        $paths = [];
        $names = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $path = (string) ($item['path'] ?? '');
            if ($path !== '') {
                $paths[] = $path;
            } else {
                $names[] = (string) ($item['name'] ?? '');
            }

            $children = MenuSchema::childrenOf($item);
            if ($children !== []) {
                [$childPaths, $childNames] = self::collectIdentity($children);
                $paths                    = array_merge($paths, $childPaths);
                $names                    = array_merge($names, $childNames);
            }
        }

        return [array_values(array_unique($paths)), array_values(array_unique($names))];
    }

    /**
     * 解析可安全清理的节点 id（调用方负责软删；本方法不落库）。
     *
     * @param  array<int, array{id:int, parent_id:int, path?:string, name?:string, is_plugin_root?:int|bool}> $nodes
     *         库中现存节点（同一归属下的**全部存活行**；已软删的不必传，天然幂等）
     * @param  array<int, string> $declaredPaths 声明中的 path 集合（{@see self::collectIdentity()}）
     * @param  array<int, string> $declaredNames 声明中的 name 集合（仅「无 path 的节点」参与比对）
     * @return array<int, int> 可清理的节点 id（按输入顺序）
     */
    public static function resolve(array $nodes, array $declaredPaths, array $declaredNames): array
    {
        $index = [];
        foreach ($nodes as $node) {
            if (isset($node['id'])) {
                $index[(int) $node['id']] = $node;
            }
        }

        // 1. 标记「保留」：声明中的节点、插件根容器，及其全部祖先
        $keep = [];
        foreach ($index as $id => $node) {
            if (!self::isDeclared($node, $declaredPaths, $declaredNames) && !self::isRoot($node)) {
                continue;
            }

            // 沿 parent_id 上溯；父节点不在 $index（0 / 其它归属）即自然终止。
            // 条件里的 $keep 判断兼作环路保险：parent_id 自指或成环时第一轮即退出。
            for ($cursor = $id; isset($index[$cursor]) && !isset($keep[$cursor]); $cursor = (int) $index[$cursor]['parent_id']) {
                $keep[$cursor] = true;
            }
        }

        // 2. 未被标记保留的即为可清理项（含「整棵未声明子树」）
        $prunable = [];
        foreach (array_keys($index) as $id) {
            if (!isset($keep[$id])) {
                $prunable[] = $id;
            }
        }

        return $prunable;
    }

    /**
     * 节点是否在声明中（有 path 比 path，无 path 比 name）
     *
     * @param array<string, mixed> $node
     * @param array<int, string>   $declaredPaths
     * @param array<int, string>   $declaredNames
     */
    protected static function isDeclared(array $node, array $declaredPaths, array $declaredNames): bool
    {
        $path = (string) ($node['path'] ?? '');

        return $path !== ''
            ? in_array($path, $declaredPaths, true)
            : in_array((string) ($node['name'] ?? ''), $declaredNames, true);
    }

    /**
     * 是否为插件根容器节点（`is_plugin_root = 1`）
     *
     * @param array<string, mixed> $node
     */
    protected static function isRoot(array $node): bool
    {
        return (int) ($node['is_plugin_root'] ?? 0) === 1;
    }
}
