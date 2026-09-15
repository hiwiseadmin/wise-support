<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\traits\Tappable;

/**
 * Tappable Trait 单元测试
 */
class TappableTest extends TestCase
{
    public function test_tap_returns_self(): void
    {
        $builder = $this->createBuilder();

        $result = $builder->tap(function ($b) {
            // side effect
            $b->value = 'side_effect';
        });

        $this->assertSame($builder, $result);
        $this->assertEquals('side_effect', $result->value);
    }

    public function test_tap_does_not_change_return_value(): void
    {
        $builder = $this->createBuilder();

        $result = $builder
            ->tap(function ($b) {
                // 尝试返回新对象不影响主链
                return 'ignored';
            });

        $this->assertSame($builder, $result);
    }

    public function test_tap_with_mutations_visible_afterwards(): void
    {
        $builder = $this->createBuilder();

        $builder
            ->tap(function ($b) {
                $b->value = 'step1';
            })
            ->tap(function ($b) {
                $b->value .= '_step2';
            });

        $this->assertEquals('step1_step2', $builder->value);
    }

    public function test_tap_for_logging_does_not_interfere(): void
    {
        $builder = $this->createBuilder();
        $loggedValue = '';

        $builder
            ->tap(function ($b) use (&$loggedValue) {
                $loggedValue = $b->value;
            });

        $this->assertEquals('original', $loggedValue);
        $this->assertEquals('original', $builder->value);
    }

    private function createBuilder(): object
    {
        return new class {
            use Tappable;
            public string $value = 'original';
        };
    }
}
