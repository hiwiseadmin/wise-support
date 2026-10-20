<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\menu\MenuSchema;

/**
 * 后台菜单节点规范 MenuSchema 单元测试
 */
class MenuSchemaTest extends TestCase
{
    // ============================================================
    // 常量
    // ============================================================

    public function test_constants(): void
    {
        $this->assertSame('dir', MenuSchema::TYPE_DIR);
        $this->assertSame('menu', MenuSchema::TYPE_MENU);
        $this->assertSame('button', MenuSchema::TYPE_BUTTON);
        $this->assertSame(['dir', 'menu', 'button'], MenuSchema::TYPES);
        $this->assertSame('dir', MenuSchema::DEFAULT_TYPE);
        $this->assertSame(1, MenuSchema::DEFAULT_STATUS);
        $this->assertSame(['children', 'child', 'childlist'], MenuSchema::CHILD_KEYS);
    }

    // ============================================================
    // normalizeTree()
    // ============================================================

    public function test_normalize_tree_drops_invalid_nodes(): void
    {
        $nodes = MenuSchema::normalizeTree([
            'not-an-array',
            ['icon' => 'bi-x'],
            ['name' => ''],
            ['name' => '   '],
            null,
            ['name' => 'valid'],
        ]);

        $this->assertCount(1, $nodes);
        $this->assertSame('valid', $nodes[0]['name']);
    }

    public function test_normalize_tree_returns_list(): void
    {
        $nodes = MenuSchema::normalizeTree([
            'a' => ['name' => 'A'],
            'b' => ['name' => 'B'],
        ]);

        $this->assertSame([0, 1], array_keys($nodes));
    }

    // ============================================================
    // normalizeNode()
    // ============================================================

    public function test_normalize_node_applies_defaults(): void
    {
        $node = MenuSchema::normalizeNode(['name' => '  系统管理  ']);

        $this->assertSame('系统管理', $node['name']);
        $this->assertSame('', $node['icon']);
        $this->assertSame('', $node['path']);
        $this->assertSame('', $node['component']);
        $this->assertSame(0, $node['sort']);
        $this->assertSame('dir', $node['type']);
        $this->assertSame(1, $node['status']);
        $this->assertArrayNotHasKey('children', $node);
    }

    public function test_normalize_node_casts_types(): void
    {
        $node = MenuSchema::normalizeNode([
            'name'   => 'X',
            'sort'   => '5',
            'status' => '0',
        ]);

        $this->assertSame(5, $node['sort']);
        $this->assertSame(0, $node['status']);
    }

    public function test_normalize_node_falls_back_for_illegal_type(): void
    {
        $this->assertSame('dir', MenuSchema::normalizeNode(['name' => 'X', 'type' => 'unknown'])['type']);
        $this->assertSame('dir', MenuSchema::normalizeNode(['name' => 'X', 'type' => ''])['type']);
    }

    public function test_normalize_node_lowercases_and_trims_type(): void
    {
        $this->assertSame('menu', MenuSchema::normalizeNode(['name' => 'X', 'type' => '  MENU '])['type']);
    }

    public function test_normalize_node_rejects_missing_name(): void
    {
        $this->assertNull(MenuSchema::normalizeNode(['icon' => 'bi-x']));
        $this->assertNull(MenuSchema::normalizeNode('string'));
        $this->assertNull(MenuSchema::normalizeNode(42));
    }

    public function test_normalize_node_preserves_extra_keys(): void
    {
        $node = MenuSchema::normalizeNode([
            'name'          => 'X',
            'mount'         => '/admin',
            'pinned'        => true,
            'is_plugin_root' => 1,
            'description'   => 'd',
        ]);

        $this->assertSame('/admin', $node['mount']);
        $this->assertTrue($node['pinned']);
        $this->assertSame(1, $node['is_plugin_root']);
        $this->assertSame('d', $node['description']);
    }

    // ============================================================
    // 子节点别名收敛
    // ============================================================

    public function test_normalize_node_accepts_children_key(): void
    {
        $node = MenuSchema::normalizeNode([
            'name'     => 'parent',
            'children' => [['name' => 'c1'], ['name' => 'c2']],
        ]);

        $this->assertSame(['c1', 'c2'], array_column($node['children'], 'name'));
    }

    public function test_normalize_node_accepts_child_alias_with_single_node(): void
    {
        $node = MenuSchema::normalizeNode([
            'name'  => 'parent',
            'child' => ['name' => 'only-child'],
        ]);

        $this->assertCount(1, $node['children']);
        $this->assertSame('only-child', $node['children'][0]['name']);
        $this->assertArrayNotHasKey('child', $node);
    }

    public function test_normalize_node_accepts_childlist_alias(): void
    {
        $node = MenuSchema::normalizeNode([
            'name'      => 'parent',
            'childlist' => [['name' => 'c1']],
        ]);

        $this->assertSame('c1', $node['children'][0]['name']);
        $this->assertArrayNotHasKey('childlist', $node);
    }

    public function test_children_key_has_priority_over_aliases(): void
    {
        $node = MenuSchema::normalizeNode([
            'name'     => 'parent',
            'children' => [['name' => 'from-children']],
            'child'    => [['name' => 'from-child']],
        ]);

        $this->assertSame('from-children', $node['children'][0]['name']);
    }

    public function test_empty_children_are_removed(): void
    {
        $this->assertArrayNotHasKey('children', MenuSchema::normalizeNode(['name' => 'X', 'children' => []]));
        $this->assertArrayNotHasKey('children', MenuSchema::normalizeNode(['name' => 'X', 'children' => 'invalid']));
    }

    public function test_children_of_returns_empty_for_missing_or_invalid(): void
    {
        $this->assertSame([], MenuSchema::childrenOf([]));
        $this->assertSame([], MenuSchema::childrenOf(['children' => 'x']));
        $this->assertSame([], MenuSchema::childrenOf(['children' => null]));
    }

    public function test_children_of_wraps_single_node(): void
    {
        $this->assertSame([['name' => 'a']], MenuSchema::childrenOf(['children' => ['name' => 'a']]));
    }

    // ============================================================
    // 递归归一 + 计数
    // ============================================================

    public function test_nested_tree_is_normalized_recursively(): void
    {
        $nodes = MenuSchema::normalizeTree([
            [
                'name'  => 'root',
                'type'  => 'DIR',
                'child' => [
                    ['name' => 'mid', 'children' => [['name' => 'leaf', 'type' => 'button']]],
                    ['icon' => 'dropped'],
                ],
            ],
        ]);

        $this->assertSame('dir', $nodes[0]['type']);
        $this->assertCount(1, $nodes[0]['children']);
        $this->assertSame('button', $nodes[0]['children'][0]['children'][0]['type']);
    }

    public function test_count_nodes_counts_recursively(): void
    {
        $nodes = MenuSchema::normalizeTree([
            ['name' => 'a', 'children' => [['name' => 'a1'], ['name' => 'a2', 'children' => [['name' => 'a2x']]]]],
            ['name' => 'b'],
        ]);

        $this->assertSame(5, MenuSchema::countNodes($nodes));
    }

    public function test_count_nodes_handles_empty(): void
    {
        $this->assertSame(0, MenuSchema::countNodes([]));
    }
}
