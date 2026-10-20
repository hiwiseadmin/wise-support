<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use think\Container;
use wise\support\traits\EventTrait;

/**
 * 事件调度 Trait 单元测试
 *
 * EventTrait 通过全局 event() 函数派发，测试中以 Event 门面替身拦截。
 */
class EventTraitTest extends TestCase
{
    private EventStub $eventStub;
    private EventTraitFixture $fixture;
    private EventConfigStub $config;
    private ?object $previousConfig = null;
    private bool $hadConfig = false;
    private string $errorLogFile;

    protected function setUp(): void
    {
        $this->eventStub = new EventStub();
        $this->config    = new EventConfigStub();

        // 临时替换 config，tearDown 恢复，避免污染依赖真实 think\Config 的其它测试
        $this->previousConfig = $this->readBoundInstance('config');
        $this->hadConfig      = $this->previousConfig !== null;

        Container::getInstance()->bind('event', $this->eventStub);
        Container::getInstance()->bind('config', $this->config);

        $this->fixture      = new EventTraitFixture('');
        $this->errorLogFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise_event_warn.log';
        @unlink($this->errorLogFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->errorLogFile);

        if ($this->hadConfig) {
            Container::getInstance()->instance('config', $this->previousConfig);
        } else {
            Container::getInstance()->delete('config');
        }
        Container::getInstance()->delete('event');
    }

    private function readBoundInstance(string $abstract): ?object
    {
        $property = new \ReflectionProperty(Container::class, 'instances');
        $property->setAccessible(true);

        $instances = $property->getValue(Container::getInstance());
        $instance  = $instances[$abstract] ?? null;

        return is_object($instance) ? $instance : null;
    }

    /**
     * 捕获 dispatch 期间写入 error_log 的内容
     */
    private function captureErrorLog(callable $callback): string
    {
        $original = ini_get('error_log');
        ini_set('error_log', $this->errorLogFile);

        try {
            $callback();
        } finally {
            if ($original !== false) {
                ini_set('error_log', $original);
            }
        }

        return is_file($this->errorLogFile) ? (string) file_get_contents($this->errorLogFile) : '';
    }

    public function test_dispatch_forwards_event_and_args(): void
    {
        $this->eventStub->result = 'listener-result';

        $result = $this->fixture->dispatchUserLogin(['id' => 1, 'ip' => '1.1.1.1']);

        $this->assertSame('listener-result', $result);
        $this->assertSame(
            [['UserLogin', ['id' => 1, 'ip' => '1.1.1.1']]],
            $this->eventStub->calls
        );
    }

    public function test_dispatch_only_first_extra_arg_reaches_framework_event_helper(): void
    {
        // 框架 event($event, $args = null) 仅接收一个参数，多余的实参会被丢弃
        $this->fixture->dispatchLegacyLogin(1, ['ip' => '1.1.1.1']);

        $this->assertSame([['Login', 1]], $this->eventStub->calls);
    }

    public function test_dispatch_applies_event_prefix(): void
    {
        $fixture = new EventTraitFixture('plugin.cms.');

        $fixture->dispatchArticlePublished(7);

        $this->assertSame('plugin.cms.ArticlePublished', $this->eventStub->calls[0][0]);
    }

    public function test_dispatch_without_args(): void
    {
        $this->fixture->dispatchNoOp();

        $this->assertSame([['NoOp', null]], $this->eventStub->calls);
    }

    public function test_dispatch_many_returns_result_per_event(): void
    {
        $this->eventStub->result = 'ok';

        $results = $this->fixture->dispatchManyEvents(['A', 'B'], 5);

        $this->assertSame(['A' => 'ok', 'B' => 'ok'], $results);
        $this->assertCount(2, $this->eventStub->calls);
        $this->assertSame(['A', 5], $this->eventStub->calls[0]);
        $this->assertSame(['B', 5], $this->eventStub->calls[1]);
    }

    public function test_dispatch_many_with_empty_list(): void
    {
        $this->assertSame([], $this->fixture->dispatchManyEvents([]));
        $this->assertSame([], $this->eventStub->calls);
    }

    // ============================================================
    // 多参数丢参告警（调试模式）
    // ============================================================

    public function test_multi_args_warning_is_logged_in_debug_mode(): void
    {
        $this->config->data['app'] = ['debug' => true];

        $logged = $this->captureErrorLog(function (): void {
            $this->fixture->dispatchLegacyLogin(1, ['ip' => '1.1.1.1']);
        });

        $this->assertStringContainsString('EventTrait::dispatch(Login)', $logged);
        $this->assertStringContainsString('其余参数已被丢弃', $logged);
        $this->assertStringContainsString('请改用数组载荷', $logged);
    }

    public function test_multi_args_warning_is_silent_in_production(): void
    {
        $this->config->data['app'] = ['debug' => false];

        $logged = $this->captureErrorLog(function (): void {
            $this->fixture->dispatchLegacyLogin(1, ['ip' => '1.1.1.1']);
        });

        $this->assertSame('', $logged);
    }

    public function test_single_arg_dispatch_never_warns(): void
    {
        $this->config->data['app'] = ['debug' => true];

        $logged = $this->captureErrorLog(function (): void {
            $this->fixture->dispatchUserLogin(['id' => 1]);
        });

        $this->assertSame('', $logged);
    }

    public function test_unavailable_config_does_not_break_dispatch(): void
    {
        // 配置不可用（未绑定 config 且无 App）时，告警分支必须静默跳过
        Container::getInstance()->delete('config');

        $result = $this->fixture->dispatchLegacyLogin(5, ['x' => 1]);

        $this->assertSame([['Login', 5]], $this->eventStub->calls);
        $this->assertNull($result);
    }

    public function test_dispatch_until_returns_true_when_framework_lacks_event_until(): void
    {
        if (function_exists('eventUntil')) {
            $this->markTestSkipped('当前框架已提供 eventUntil()，该分支不适用');
        }

        // 框架未提供 eventUntil() → 视为全部通过，且不应触发事件派发
        $this->assertTrue($this->fixture->dispatchUntilGuard('BeforeDelete', 9));
        $this->assertSame([], $this->eventStub->calls);
    }

    public function test_dispatch_until_applies_prefix(): void
    {
        $fixture = new EventTraitFixture('plugin.cms.');

        $this->assertTrue($fixture->dispatchUntilGuard('BeforeDelete'));
    }
}

// ============================================================
// 测试替身与夹具
// ============================================================

class EventStub
{
    /** @var array<int, array{0:string,1:mixed}> */
    public array $calls = [];
    public mixed $result = null;

    public function trigger(string $event, mixed $args = null): mixed
    {
        $this->calls[] = [$event, $args];
        return $this->result;
    }
}

class EventConfigStub
{
    /** @var array<string, mixed> */
    public array $data = [];

    public function get(string $name = '', mixed $default = null): mixed
    {
        if ($name === '') {
            return $this->data;
        }

        $value = $this->data;
        foreach (explode('.', $name) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function set(array $config, string $name = ''): void
    {
        $this->data[$name] = $config;
    }

    public function has(string $name): bool
    {
        return isset($this->data[$name]);
    }
}

class EventTraitFixture
{
    use EventTrait {
        dispatch as public;
        dispatchMany as public;
        dispatchUntil as public;
    }

    public function __construct(string $prefix = '')
    {
        $this->eventPrefix = $prefix;
    }

    public function dispatchUserLogin(array $payload): mixed
    {
        return $this->dispatch('UserLogin', $payload);
    }

    public function dispatchLegacyLogin(int $userId, array $meta = []): mixed
    {
        return $this->dispatch('Login', $userId, $meta);
    }

    public function dispatchArticlePublished(int $id): mixed
    {
        return $this->dispatch('ArticlePublished', $id);
    }

    public function dispatchNoOp(): mixed
    {
        return $this->dispatch('NoOp');
    }

    public function dispatchManyEvents(array $events, mixed ...$args): array
    {
        return $this->dispatchMany($events, ...$args);
    }

    public function dispatchUntilGuard(string $event, mixed ...$args): bool
    {
        return $this->dispatchUntil($event, ...$args);
    }
}
