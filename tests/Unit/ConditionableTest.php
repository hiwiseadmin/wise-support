<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\traits\Conditionable;

/**
 * Conditionable Trait 单元测试
 */
class ConditionableTest extends TestCase
{
    public function test_when_condition_true_executes_callback(): void
    {
        $builder = $this->createBuilder();

        $result = $builder->when(true, function ($b) {
            $b->value = 'modified';
            return $b;
        });

        $this->assertEquals('modified', $result->value);
    }

    public function test_when_condition_false_skips_callback(): void
    {
        $builder = $this->createBuilder();

        $result = $builder->when(false, function ($b) {
            $b->value = 'modified';
            return $b;
        });

        $this->assertEquals('original', $result->value);
    }

    public function test_when_condition_false_executes_default_callback(): void
    {
        $builder = $this->createBuilder();

        $result = $builder->when(false, function ($b) {
            $b->value = 'should_not_happen';
            return $b;
        }, function ($b) {
            $b->value = 'else_branch';
            return $b;
        });

        $this->assertEquals('else_branch', $result->value);
    }

    public function test_when_can_return_different_type(): void
    {
        $builder = $this->createBuilder();

        $result = $builder->when(true, function ($b) {
            return $b->value;
        });

        $this->assertEquals('original', $result);
        $this->assertIsString($result);
    }

    public function test_unless_is_inverse_of_when(): void
    {
        $builder = $this->createBuilder();

        // unless(true) → 不执行 callback
        $result = $builder->unless(true, function ($b) {
            $b->value = 'modified';
            return $b;
        });
        $this->assertEquals('original', $result->value);

        // unless(false) → 执行 callback
        $builder2 = $this->createBuilder();
        $result2 = $builder2->unless(false, function ($b) {
            $b->value = 'modified';
            return $b;
        });
        $this->assertEquals('modified', $result2->value);
    }

    public function test_chained_when_calls(): void
    {
        $builder = $this->createBuilder();

        $result = $builder
            ->when(true, fn($b) => $b)
            ->when(false, fn($b) => $b)
            ->when(true, function ($b) {
                $b->value = 'chained';
                return $b;
            });

        $this->assertEquals('chained', $result->value);
    }

    public function test_when_with_truthy_condition(): void
    {
        $builder = $this->createBuilder();

        // non-empty string is truthy
        $result = $builder->when('hello', function ($b, $condition) {
            $b->value = $condition;
            return $b;
        });

        $this->assertEquals('hello', $result->value);
    }

    private function createBuilder(): object
    {
        return new class {
            use Conditionable;
            public string $value = 'original';
        };
    }
}
