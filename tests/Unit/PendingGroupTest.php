<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\router\PendingGroup;

/**
 * 延迟路由分组句柄 PendingGroup 单元测试
 */
class PendingGroupTest extends TestCase
{
    public function test_call_records_and_returns_self(): void
    {
        $pending = new PendingGroup();

        $result = $pending->allowCrossDomain(['*']);

        $this->assertSame($pending, $result);
    }

    public function test_call_is_chainable_in_order(): void
    {
        $pending = new PendingGroup();
        $pending->allowCrossDomain(['a'])->middleware(['mw'])->name('x');

        $group = new PendingGroupReplayTarget();
        $pending->replay($group);

        $this->assertSame(
            [
                ['allowCrossDomain', [['a']]],
                ['middleware', [['mw']]],
                ['name', ['x']],
            ],
            $group->calls
        );
    }

    public function test_replay_with_null_is_noop(): void
    {
        $pending = new PendingGroup();
        $pending->allowCrossDomain(['*']);

        $pending->replay(null);

        // 无异常即通过：仅验证空分组不会被重放
        $this->assertTrue(true);
    }

    public function test_replay_skips_unsupported_methods(): void
    {
        $pending = new PendingGroup();
        $pending->allowCrossDomain(['*']);
        $pending->notExistingMethod('x');

        $group = new PendingGroupReplayTarget();
        $pending->replay($group);

        $this->assertSame([['allowCrossDomain', [['*']]]], $group->calls);
    }

    public function test_replay_without_recorded_calls_does_nothing(): void
    {
        $group = new PendingGroupReplayTarget();

        (new PendingGroup())->replay($group);

        $this->assertSame([], $group->calls);
    }
}

class PendingGroupReplayTarget
{
    /** @var array<int, array{0:string,1:array}> */
    public array $calls = [];

    public function allowCrossDomain(array $domains): self
    {
        $this->calls[] = ['allowCrossDomain', [$domains]];
        return $this;
    }

    public function middleware(array $middleware): self
    {
        $this->calls[] = ['middleware', [$middleware]];
        return $this;
    }

    public function name(string $name): self
    {
        $this->calls[] = ['name', [$name]];
        return $this;
    }
}
