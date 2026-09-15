<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\Pipeline;

/**
 * Pipeline 通用管道单元测试
 */
class PipelineTest extends TestCase
{
    public function test_basic_pipeline_with_closures(): void
    {
        $result = (new Pipeline())
            ->send(10)
            ->through([
                fn($v, $next) => $next($v + 5),
                fn($v, $next) => $next($v * 2),
            ])
            ->then(fn($v) => $v);

        $this->assertEquals(30, $result); // (10 + 5) * 2 = 30
    }

    public function test_pipeline_with_class_pipes(): void
    {
        $result = (new Pipeline())
            ->send(['name' => '  John  ', 'email' => 'JOHN@example.com'])
            ->through([
                TrimPipe::class,
                LowercaseEmailPipe::class,
            ])
            ->then(fn($data) => $data);

        $this->assertEquals('John', $result['name']);
        $this->assertEquals('john@example.com', $result['email']);
    }

    public function test_then_return_preserves_passable(): void
    {
        $data = ['status' => 'draft'];

        $result = (new Pipeline())
            ->send($data)
            ->through([
                fn($data, $next) => $next(array_merge($data, ['processed' => true])),
            ])
            ->thenReturn();

        $this->assertEquals('draft', $result['status']);
        $this->assertTrue($result['processed']);
    }

    public function test_pipeline_via_custom_method(): void
    {
        $result = (new Pipeline())
            ->send('hello')
            ->through([CustomMethodPipe::class])
            ->via('process')
            ->thenReturn();

        $this->assertEquals('HELLO', $result);
    }

    public function test_empty_pipeline_returns_passable(): void
    {
        $result = (new Pipeline())
            ->send(42)
            ->through([])
            ->then(fn($v) => $v);

        $this->assertEquals(42, $result);
    }

    public function test_pipe_execution_order_is_onion(): void
    {
        // Pipe 1 wraps Pipe 2 wraps Pipe 3
        // Execution: Pipe1 before → Pipe2 before → Pipe3 before → Pipe3 after → Pipe2 after → Pipe1 after
        $log = [];

        $result = (new Pipeline())
            ->send('start')
            ->through([
                function ($v, $next) use (&$log) {
                    $log[] = 'pipe1_before';
                    $result = $next($v);
                    $log[] = 'pipe1_after';
                    return $result;
                },
                function ($v, $next) use (&$log) {
                    $log[] = 'pipe2_before';
                    $result = $next($v);
                    $log[] = 'pipe2_after';
                    return $result;
                },
            ])
            ->then(function ($v) use (&$log) {
                $log[] = 'destination';
                return $v;
            });

        $this->assertEquals('start', $result);
        $this->assertEquals([
            'pipe1_before',
            'pipe2_before',
            'destination',
            'pipe2_after',
            'pipe1_after',
        ], $log);
    }

    public function test_pipeline_stops_on_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Pipe error');

        (new Pipeline())
            ->send('start')
            ->through([
                fn($v, $next) => throw new \RuntimeException('Pipe error'),
            ])
            ->then(fn($v) => $v);
    }

    public function test_pipe_instances_are_cached(): void
    {
        TrimPipe::$instantiationCount = 0;

        (new Pipeline())
            ->send(['name' => '  A  '])
            ->through([
                TrimPipe::class,
                TrimPipe::class, // 同一个类出现两次
            ])
            ->thenReturn();

        // 同一类只实例化一次
        $this->assertEquals(1, TrimPipe::$instantiationCount);
    }

    public function test_flush_pipe_cache(): void
    {
        $pipeline = new Pipeline();

        $pipeline
            ->send(['name' => 'A'])
            ->through([TrimPipe::class])
            ->thenReturn();

        $pipeline->flushPipeCache();

        TrimPipe::$instantiationCount = 0;

        $pipeline
            ->send(['name' => 'B'])
            ->through([TrimPipe::class])
            ->thenReturn();

        // flush 后重新创建实例
        $this->assertEquals(1, TrimPipe::$instantiationCount);
    }
}

// ==================== Test Pipe Classes ====================

class TrimPipe
{
    public static int $instantiationCount = 0;

    public function __construct()
    {
        self::$instantiationCount++;
    }

    public function handle(array $data, \Closure $next): array
    {
        foreach ($data as $key => &$value) {
            if (is_string($value)) {
                $value = trim($value);
            }
        }
        return $next($data);
    }
}

class LowercaseEmailPipe
{
    public function handle(array $data, \Closure $next): array
    {
        if (isset($data['email'])) {
            $data['email'] = strtolower($data['email']);
        }
        return $next($data);
    }
}

class CustomMethodPipe
{
    public function process(string $value, \Closure $next): string
    {
        return $next(strtoupper($value));
    }
}
