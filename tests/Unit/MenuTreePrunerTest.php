<?php

declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\menu\MenuTreePruner;

/**
 * 菜单树差集解析（MenuTreePruner）单元测试
 *
 * 覆盖「清理 manifest/声明中已不存在的菜单」这一动作**全部误删风险点**：
 * 根容器、祖先链、整棵未声明子树、无 path 节点、环路。
 *
 * 该逻辑此前内联在 `MenuItemSync::prune()` 里（只有「按 path/name 判在不在声明中」一条规则），
 * 抽到 wise-support 后由库包链路与插件链路共用，因此必须有独立单测锁住守卫。
 */
class MenuTreePrunerTest extends TestCase
{

    public function test_collect_identity_splits_path_and_name(): void
    {
        [$paths, $names] = MenuTreePruner::collectIdentity([
            ['name' => '内容配置', 'path' => '', 'children' => [
                ['name' => '文章', 'path' => '/plugin/x/article'],
                ['name' => '分类', 'path' => '/plugin/x/category'],
            ]],
            ['name' => '仪表盘', 'path' => '/plugin/x/dashboard'],
        ]);

        $this->assertSame(['/plugin/x/article', '/plugin/x/category', '/plugin/x/dashboard'], $paths);
        $this->assertSame(['内容配置'], $names);
    }

    public function test_collect_identity_recurses_through_child_aliases(): void
    {
        [$paths] = MenuTreePruner::collectIdentity([
            ['name' => 'a', 'path' => '/a', 'child' => [
                ['name' => 'b', 'path' => '/b', 'childlist' => [
                    ['name' => 'c', 'path' => '/c'],
                ]],
            ]],
        ]);

        $this->assertSame(['/a', '/b', '/c'], $paths);
    }

    public function test_collect_identity_deduplicates_and_skips_non_array(): void
    {
        [$paths, $names] = MenuTreePruner::collectIdentity([
            'not-an-array',
            null,
            ['name' => 'a', 'path' => '/same'],
            ['name' => 'a2', 'path' => '/same'],
            ['name' => '无路径'],
            ['name' => '无路径'],
        ]);

        $this->assertSame(['/same'], $paths);
        $this->assertSame(['无路径'], $names);
    }

    public function test_collect_identity_on_empty_declaration(): void
    {
        $this->assertSame([[], []], MenuTreePruner::collectIdentity([]));
    }


    public function test_resolve_prunes_only_undeclared_leaves(): void
    {
        $nodes = [
            ['id' => 10, 'parent_id' => 0, 'path' => '', 'name' => '根', 'is_plugin_root' => 1],
            ['id' => 11, 'parent_id' => 10, 'path' => '/plugin/x/keep', 'name' => '保留'],
            ['id' => 12, 'parent_id' => 10, 'path' => '/plugin/x/gone', 'name' => '已删除'],
        ];

        $this->assertSame([12], MenuTreePruner::resolve($nodes, ['/plugin/x/keep'], []));
    }

    public function test_resolve_keeps_declared_node_even_without_path(): void
    {
        $nodes = [
            ['id' => 1, 'parent_id' => 0, 'name' => '系统设置', 'path' => ''],
            ['id' => 2, 'parent_id' => 0, 'name' => '临时目录', 'path' => ''],
        ];

        $this->assertSame([2], MenuTreePruner::resolve($nodes, [], ['系统设置']));
    }

    public function test_resolve_never_prunes_plugin_root(): void
    {
        $nodes = [
            ['id' => 1, 'parent_id' => 0, 'name' => '示例插件', 'path' => '', 'is_plugin_root' => 1],
        ];

        $this->assertSame([], MenuTreePruner::resolve($nodes, [], []));
    }

    public function test_resolve_prunes_nothing_when_everything_declared(): void
    {
        $nodes = [
            ['id' => 1, 'parent_id' => 0, 'path' => '/a', 'name' => 'a'],
            ['id' => 2, 'parent_id' => 1, 'path' => '/b', 'name' => 'b'],
        ];

        $this->assertSame([], MenuTreePruner::resolve($nodes, ['/a', '/b'], []));
    }


    public function test_resolve_keeps_undeclared_ancestor_of_declared_child(): void
    {
        $nodes = [
            ['id' => 10, 'parent_id' => 0, 'path' => '', 'name' => '插件根', 'is_plugin_root' => 1],
            ['id' => 11, 'parent_id' => 10, 'path' => '', 'name' => '手工分组'],
            ['id' => 12, 'parent_id' => 11, 'path' => '/plugin/x/keep', 'name' => '保留项'],
        ];

        $this->assertSame([], MenuTreePruner::resolve($nodes, ['/plugin/x/keep'], []));
    }

    public function test_resolve_prunes_whole_undeclared_subtree(): void
    {
        $nodes = [
            ['id' => 10, 'parent_id' => 0, 'path' => '', 'name' => '插件根', 'is_plugin_root' => 1],
            ['id' => 11, 'parent_id' => 10, 'path' => '/plugin/x/keep', 'name' => '保留项'],
            ['id' => 20, 'parent_id' => 10, 'path' => '', 'name' => '陈旧分组'],
            ['id' => 21, 'parent_id' => 20, 'path' => '/plugin/x/old', 'name' => '陈旧子项'],
            ['id' => 22, 'parent_id' => 21, 'path' => '/plugin/x/old2', 'name' => '陈旧孙项'],
        ];

        $this->assertSame([20, 21, 22], MenuTreePruner::resolve($nodes, ['/plugin/x/keep'], []));
    }

    public function test_resolve_keeps_declared_parent_chain_of_deep_node(): void
    {
        $nodes = [
            ['id' => 1, 'parent_id' => 0, 'path' => '/a', 'name' => 'a'],
            ['id' => 2, 'parent_id' => 1, 'path' => '/b', 'name' => 'b'],
            ['id' => 3, 'parent_id' => 2, 'path' => '/c', 'name' => 'c'],
            ['id' => 4, 'parent_id' => 2, 'path' => '/d', 'name' => 'd'],
        ];

        $this->assertSame([4], MenuTreePruner::resolve($nodes, ['/a', '/c'], []));
    }


    public function test_resolve_handles_parent_outside_scope(): void
    {
        $nodes = [
            ['id' => 7, 'parent_id' => 0, 'path' => '/plugin/x/gone', 'name' => '陈旧'],
            ['id' => 8, 'parent_id' => 99, 'name' => '挂到别处的陈旧目录', 'path' => ''],
        ];

        $this->assertSame([7, 8], MenuTreePruner::resolve($nodes, [], []));
    }

    public function test_resolve_survives_parent_cycle(): void
    {
        $nodes = [
            ['id' => 1, 'parent_id' => 2, 'path' => '/keep', 'name' => 'a'],
            ['id' => 2, 'parent_id' => 1, 'path' => '', 'name' => 'b'],
            ['id' => 3, 'parent_id' => 3, 'path' => '/keep', 'name' => 'self'],
        ];

        $this->assertSame([], MenuTreePruner::resolve($nodes, ['/keep'], []));
    }

    public function test_resolve_ignores_nodes_without_id(): void
    {
        $nodes = [
            ['parent_id' => 0, 'path' => '/x', 'name' => '缺 id'],
            ['id' => 5, 'parent_id' => 0, 'path' => '/y', 'name' => '有 id'],
        ];

        $this->assertSame([5], MenuTreePruner::resolve($nodes, [], []));
    }

    public function test_resolve_accepts_string_plugin_root_flag(): void
    {
        $nodes = [
            ['id' => 1, 'parent_id' => 0, 'path' => '', 'name' => '根', 'is_plugin_root' => '1'],
        ];

        $this->assertSame([], MenuTreePruner::resolve($nodes, [], []));
    }

    public function test_resolve_on_empty_node_set(): void
    {
        $this->assertSame([], MenuTreePruner::resolve([], ['/x'], []));
    }
}
