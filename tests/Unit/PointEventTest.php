<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\ExtPoint;
use wise\support\event\CapabilityToggled;

/**
 * ExtPoint 解耦机制单元测试
 *
 * 覆盖两条通道：
 *  - 能力启停事件（bindEventDispatcher 派发 {@see CapabilityToggled} 事件对象）
 *  - 实现级展示元数据提供者（registerImplementationDescriber / describeImplementation）
 *
 * 设计动机：能力市场等消费方不再内联判断各业务包的实现状态，
 * 改为由业务包经这两条通道自注册给 ExtPoint。
 */
class PointEventTest extends TestCase
{
    protected function setUp(): void
    {
        ExtPoint::reset();
    }

    protected function tearDown(): void
    {
        ExtPoint::reset();
    }


    public function test_set_active_dispatches_capability_toggled_event(): void
    {
        ExtPoint::definePoint('demo.multi', ExtPoint::POLICY_MULTI);
        ExtPoint::register('demo.multi', 'alpha', 'Alpha');

        $seen = null;
        ExtPoint::bindEventDispatcher(function (object $event) use (&$seen): void {
            $seen = $event;
        });

        $this->assertTrue(ExtPoint::setActive('demo.multi', 'alpha', false, 'admin'));

        $this->assertInstanceOf(CapabilityToggled::class, $seen, 'setActive 应派发事件对象');
        $this->assertSame('demo.multi', $seen->point);
        $this->assertSame('alpha', $seen->name);
        $this->assertTrue($seen->before, 'before 应为变更前真实状态');
        $this->assertFalse($seen->after);
        $this->assertSame('admin', $seen->source);
    }

    public function test_set_active_without_dispatcher_is_silent(): void
    {
        ExtPoint::definePoint('demo.multi', ExtPoint::POLICY_MULTI);
        ExtPoint::register('demo.multi', 'alpha', 'Alpha');

        $this->assertTrue(ExtPoint::setActive('demo.multi', 'alpha', false));
    }

    public function test_dispatcher_exception_does_not_break_set_active(): void
    {
        ExtPoint::definePoint('demo.multi', ExtPoint::POLICY_MULTI);
        ExtPoint::register('demo.multi', 'alpha', 'Alpha');

        ExtPoint::bindEventDispatcher(function (): void {
            throw new \RuntimeException('dispatcher boom');
        });

        $this->assertTrue(ExtPoint::setActive('demo.multi', 'alpha', false));
        $this->assertFalse(ExtPoint::all('demo.multi')['alpha']['active']);
    }

    public function test_source_defaults_to_system(): void
    {
        ExtPoint::definePoint('demo.multi', ExtPoint::POLICY_MULTI);
        ExtPoint::register('demo.multi', 'alpha', 'Alpha');

        $seen = null;
        ExtPoint::bindEventDispatcher(function (object $event) use (&$seen): void {
            $seen = $event;
        });

        ExtPoint::setActive('demo.multi', 'alpha', false);

        $this->assertSame('system', $seen->source);
    }


    public function test_implementation_describer_merges_meta(): void
    {
        ExtPoint::registerImplementationDescriber(
            static fn(string $point, string $name): array => ['pending' => true]
        );
        ExtPoint::registerImplementationDescriber(
            static fn(string $point, string $name): array => ['badge' => 'new']
        );

        $this->assertSame(
            ['pending' => true, 'badge' => 'new'],
            ExtPoint::describeImplementation('demo.multi', 'alpha')
        );
    }

    public function test_implementation_describer_receives_context(): void
    {
        $captured = null;
        ExtPoint::registerImplementationDescriber(
            static function (string $point, string $name, mixed $concrete) use (&$captured): array {
                $captured = [$point, $name, $concrete];
                return [];
            }
        );

        ExtPoint::describeImplementation('member.feature', 'demo:achievements', 'DemoClass');

        $this->assertSame(['member.feature', 'demo:achievements', 'DemoClass'], $captured);
    }

    public function test_implementation_describer_exception_is_swallowed(): void
    {
        ExtPoint::registerImplementationDescriber(static function (): array {
            throw new \RuntimeException('describer boom');
        });
        ExtPoint::registerImplementationDescriber(static fn(): array => ['ok' => true]);

        $this->assertSame(['ok' => true], ExtPoint::describeImplementation('demo.multi', 'alpha'));
    }

    public function test_describe_implementation_without_describer_returns_empty(): void
    {
        $this->assertSame([], ExtPoint::describeImplementation('demo.multi', 'alpha'));
    }


    public function test_reset_clears_dispatcher_and_describers(): void
    {
        ExtPoint::bindEventDispatcher(static fn() => null);
        ExtPoint::registerImplementationDescriber(static fn(): array => ['a' => 1]);

        ExtPoint::reset();

        $this->assertSame([], ExtPoint::describeImplementation('x', 'y'));

        ExtPoint::definePoint('demo.multi', ExtPoint::POLICY_MULTI);
        ExtPoint::register('demo.multi', 'alpha', 'Alpha');
        $this->assertTrue(ExtPoint::setActive('demo.multi', 'alpha', false));
    }
}
