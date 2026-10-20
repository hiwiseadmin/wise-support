<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\ExtPoint;

/**
 * ExtPoint 扩展点元数据（自声明）单元测试
 *
 * 覆盖：definePoint($meta) / describePoint() / getPointMeta() / describedPoints()。
 *
 * 设计动机：各业务包 / 插件自定义的扩展点，其展示名与描述由归属方自声明，
 * 能力市场据此自动发现，避免框架层（app\admin\controller\Capability）硬编码。
 */
class PointMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        ExtPoint::reset();
    }

    protected function tearDown(): void
    {
        ExtPoint::reset();
    }

    public function test_define_point_with_meta_registers_description(): void
    {
        ExtPoint::definePoint('member.feature', ExtPoint::POLICY_MULTI, null, [
            'name'     => '会员功能模块',
            'module'   => 'wise-member',
            'category' => '会员',
        ]);

        $this->assertSame([
            'name'     => '会员功能模块',
            'module'   => 'wise-member',
            'category' => '会员',
        ], ExtPoint::getPointMeta('member.feature'));

        $this->assertArrayHasKey('member.feature', ExtPoint::describedPoints());
    }

    public function test_define_point_without_meta_leaves_no_description(): void
    {
        ExtPoint::definePoint('framework.point', ExtPoint::POLICY_MULTI);

        $this->assertNull(ExtPoint::getPointMeta('framework.point'));
        $this->assertSame([], ExtPoint::describedPoints());
    }

    public function test_describe_point_declares_meta_independently(): void
    {
        ExtPoint::definePoint('member.feature', ExtPoint::POLICY_MULTI);
        ExtPoint::describePoint('member.feature', ['name' => '会员功能模块']);

        $this->assertSame('会员功能模块', ExtPoint::getPointMeta('member.feature')['name']);
    }

    public function test_describe_point_merges_keys(): void
    {
        ExtPoint::describePoint('member.feature', ['name' => '会员功能', 'module' => 'wise-member']);
        ExtPoint::describePoint('member.feature', ['name' => '会员功能模块']);

        // 合并语义：同键后者覆盖、未涉及键保留
        $this->assertSame([
            'name'   => '会员功能模块',
            'module' => 'wise-member',
        ], ExtPoint::getPointMeta('member.feature'));
    }

    public function test_define_point_without_meta_keeps_existing_description(): void
    {
        ExtPoint::definePoint('member.feature', ExtPoint::POLICY_MULTI, null, ['name' => '会员功能模块']);
        // 重复 definePoint（另一包防御式调用）不传 meta 时不应清空已有描述
        ExtPoint::definePoint('member.feature', ExtPoint::POLICY_MULTI);

        $this->assertSame('会员功能模块', ExtPoint::getPointMeta('member.feature')['name']);
    }

    public function test_describe_point_can_hide_point_from_market(): void
    {
        ExtPoint::definePoint('internal.point', ExtPoint::POLICY_MULTI, null, ['visible' => false]);

        $this->assertFalse(ExtPoint::getPointMeta('internal.point')['visible']);
    }

    public function test_described_points_returns_all_declared_points(): void
    {
        ExtPoint::definePoint('a.point', ExtPoint::POLICY_MULTI, null, ['name' => 'A']);
        ExtPoint::definePoint('b.point', ExtPoint::POLICY_MULTI, null, ['name' => 'B']);

        $this->assertSame(['a.point', 'b.point'], array_keys(ExtPoint::describedPoints()));
    }

    public function test_get_point_meta_returns_null_for_unknown_point(): void
    {
        $this->assertNull(ExtPoint::getPointMeta('not.exists'));
    }

    public function test_reset_clears_point_meta(): void
    {
        ExtPoint::describePoint('a.point', ['name' => 'A']);
        $this->assertNotEmpty(ExtPoint::describedPoints());

        ExtPoint::reset();

        $this->assertSame([], ExtPoint::describedPoints());
        $this->assertNull(ExtPoint::getPointMeta('a.point'));
    }
}
