<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\ExtPoints;

/**
 * ExtPoints 框架标准扩展点常量单元测试
 */
class ExtPointsTest extends TestCase
{
    public function test_single_policy_constants(): void
    {
        $this->assertSame('image.driver', ExtPoints::IMAGE_DRIVER);
        $this->assertSame('image.processor', ExtPoints::IMAGE_PROCESSOR);
        $this->assertSame('jwt.provider', ExtPoints::JWT_PROVIDER);
    }

    public function test_routed_policy_constants(): void
    {
        $this->assertSame('connector', ExtPoints::EXTERNAL_CONNECTOR);
    }

    public function test_multi_policy_constants(): void
    {
        $this->assertSame('dashboard.widget', ExtPoints::DASHBOARD_WIDGET);
        $this->assertSame('menu.item', ExtPoints::MENU_ITEM);
        $this->assertSame('search.provider', ExtPoints::SEARCH_PROVIDER);
        $this->assertSame('bus.handler', ExtPoints::BUS_HANDLER_PREFIX);
        $this->assertSame('slot.content', ExtPoints::SLOT_CONTENT);
    }

    public function test_phase1_constants(): void
    {
        $this->assertSame('auth.guard', ExtPoints::AUTH_GUARD);
        $this->assertSame('jwt.provider', ExtPoints::JWT_PROVIDER);
        $this->assertSame('ws.handler', ExtPoints::WS_HANDLER);
        $this->assertSame('ws.heartbeat', ExtPoints::WS_HEARTBEAT);
    }

    public function test_get_point_meta_returns_expected_structure(): void
    {
        $meta = ExtPoints::getPointMeta();

        $this->assertIsArray($meta);
        $this->assertNotEmpty($meta);

        foreach ($meta as $item) {
            $this->assertArrayHasKey('point', $item);
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('policy', $item);
            $this->assertIsString($item['point']);
            $this->assertIsString($item['name']);
            $this->assertIsString($item['policy']);
        }
    }

    public function test_get_point_meta_contains_core_points(): void
    {
        $meta = ExtPoints::getPointMeta();
        $points = array_column($meta, 'point');

        $this->assertContains('image.driver', $points);
        $this->assertContains('connector', $points);
        $this->assertContains('auth.guard', $points);
        $this->assertContains('jwt.provider', $points);
    }

    public function test_get_point_meta_policy_matches_classification(): void
    {
        $meta = ExtPoints::getPointMeta();
        $map = [];
        foreach ($meta as $item) {
            $map[$item['point']] = $item['policy'];
        }

        // 独占型应为 single
        $this->assertSame('single', $map['image.driver']);
        $this->assertSame('single', $map['jwt.provider']);
        // 路由型应为 routed
        $this->assertSame('routed', $map['connector']);
        $this->assertSame('routed', $map['auth.guard']);
        // 共享型应为 multi
        $this->assertSame('multi', $map['search.provider']);
    }

    public function test_get_point_meta_has_no_duplicate_points(): void
    {
        $meta = ExtPoints::getPointMeta();
        $points = array_column($meta, 'point');

        $this->assertSame(count($points), count(array_unique($points)));
    }
}
