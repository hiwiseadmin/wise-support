<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use think\Container;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\bus\ServiceBus;
use wise\support\ws\ChannelInterface;
use wise\support\ws\ConnectionManager;
use wise\support\ws\MessageRouter;
use wise\support\ws\WsServerInterface;
use wise\support\ws\handler\PingPongHeartbeat;

/**
 * WebSocket 消息路由器 MessageRouter 单元测试
 */
class MessageRouterTest extends TestCase
{
    private MessageRouter $router;
    private ConnectionManager $manager;
    private RouterServerStub $server;
    private RouterLoggerStub $logger;

    protected function setUp(): void
    {
        ExtPoint::reset();

        $this->server  = new RouterServerStub();
        $this->logger  = new RouterLoggerStub();
        $this->manager = (new ConnectionManager())->setServer($this->server)->setLogger($this->logger);
        $this->router  = (new MessageRouter())
            ->setConnectionManager($this->manager)
            ->setLogger($this->logger);

        $this->manager->onOpen(1, ['user_id' => 42]);
    }

    protected function tearDown(): void
    {
        ExtPoint::reset();
        Container::getInstance()->delete(ServiceBus::class);
    }

    // ============================================================
    // 消息解析
    // ============================================================

    public function test_dispatch_invalid_json_sends_error(): void
    {
        $result = $this->router->dispatch(1, 'not-json');

        $this->assertNull($result);
        $this->assertSame('warning', $this->logger->lastLevel());
        $this->assertStringContainsString('无效 JSON', $this->logger->lastMessage());
        $this->assertErrorFrameSent('无效的消息格式');
    }

    public function test_dispatch_json_scalar_is_invalid(): void
    {
        $this->assertNull($this->router->dispatch(1, '123'));
        $this->assertErrorFrameSent('无效的消息格式');
    }

    public function test_dispatch_without_action_and_without_heartbeat_handler_logs_warning(): void
    {
        $result = $this->router->dispatch(1, '{"foo":"bar"}');

        $this->assertNull($result);
        $this->assertStringContainsString('消息缺少 action 字段', $this->logger->lastMessage());
        $this->assertSame([], $this->server->sent);
    }

    public function test_dispatch_heartbeat_ping_replies_pong(): void
    {
        ExtPoint::definePoint(ExtPoints::WS_HEARTBEAT, ExtPoint::POLICY_MULTI);
        ExtPoint::register(ExtPoints::WS_HEARTBEAT, 'ping_pong', PingPongHeartbeat::class);

        $result = $this->router->dispatch(1, '{"type":"ping"}');

        $this->assertTrue($result);
        $this->assertCount(1, $this->server->sent);
        $payload = json_decode($this->server->sent[0][1], true);
        $this->assertSame('pong', $payload['type']);
    }

    public function test_dispatch_explicit_ping_action_uses_heartbeat_handler(): void
    {
        ExtPoint::definePoint(ExtPoints::WS_HEARTBEAT, ExtPoint::POLICY_MULTI);

        $seenData = null;
        ExtPoint::register(
            ExtPoints::WS_HEARTBEAT,
            'custom_heartbeat',
            function (int $fd, array $data) use (&$seenData): ?string {
                $seenData = $data;
                return json_encode(['type' => 'pong', 'fd' => $fd]);
            }
        );

        $result = $this->router->dispatch(1, '{"action":"ping"}');

        $this->assertTrue($result);
        // 闭包型心跳处理器收到的是 (fd, 已解析的消息数组)
        $this->assertSame('ping', $seenData['action']);
        $this->assertStringContainsString('"fd":1', $this->server->sent[0][1]);
    }

    public function test_dispatch_explicit_pong_action_falls_back_to_routing(): void
    {
        ExtPoint::definePoint(ExtPoints::WS_HEARTBEAT, ExtPoint::POLICY_MULTI);
        // 心跳处理器不识别 pong（返回 null）→ 继续走正常路由，最终无处理器
        ExtPoint::register(ExtPoints::WS_HEARTBEAT, 'noop_heartbeat', fn() => null);

        $this->assertNull($this->router->dispatch(1, '{"action":"pong"}'));
        $this->assertErrorFrameSent('未知操作: pong');
    }

    public function test_dispatch_heartbeat_handler_exception_is_logged_and_not_taken_over(): void
    {
        ExtPoint::definePoint(ExtPoints::WS_HEARTBEAT, ExtPoint::POLICY_MULTI);
        ExtPoint::register(ExtPoints::WS_HEARTBEAT, 'boom', function (): string {
            throw new \RuntimeException('heartbeat boom');
        });

        $this->assertNull($this->router->dispatch(1, '{"type":"ping"}'));
        $this->assertNotNull($this->logger->find('心跳处理器 [boom] 处理失败'));
    }

    public function test_dispatch_heartbeat_handler_returning_null_is_not_taken_over(): void
    {
        ExtPoint::definePoint(ExtPoints::WS_HEARTBEAT, ExtPoint::POLICY_MULTI);
        ExtPoint::register(ExtPoints::WS_HEARTBEAT, 'null_handler', fn() => null);

        $this->assertNull($this->router->dispatch(1, '{"type":"ping"}'));
        $this->assertStringContainsString('消息缺少 action 字段', $this->logger->lastMessage());
    }

    // ============================================================
    // 鉴权预检
    // ============================================================

    public function test_authorize_returns_self(): void
    {
        $this->assertSame($this->router, $this->router->authorize(null));
    }

    public function test_authorize_deny_blocks_dispatch(): void
    {
        $this->router->authorize(fn(int $fd, array $payload): string => '未登录');

        $result = $this->router->dispatch(1, '{"action":"game.sync"}');

        $this->assertNull($result);
        $this->assertErrorFrameSent('未登录');
        $this->assertNotNull($this->logger->find('鉴权拦截 action [game.sync]'));
    }

    public function test_authorize_allow_passes_through(): void
    {
        $this->router->authorize(fn() => null);
        ExtPoint::register('ws.handler.game.sync', 'handler', fn(array $payload) => 'ok');

        $this->assertSame(['handler' => 'ok'], $this->router->dispatch(1, '{"action":"game.sync"}'));
    }

    public function test_authorize_empty_string_is_treated_as_pass(): void
    {
        $this->router->authorize(fn() => '');
        ExtPoint::register('ws.handler.game.sync', 'handler', fn(array $payload) => 'ok');

        $this->assertSame(['handler' => 'ok'], $this->router->dispatch(1, '{"action":"game.sync"}'));
    }

    public function test_authorize_non_string_is_treated_as_pass(): void
    {
        $this->router->authorize(fn() => true);
        ExtPoint::register('ws.handler.game.sync', 'handler', fn(array $payload) => 'ok');

        $this->assertSame(['handler' => 'ok'], $this->router->dispatch(1, '{"action":"game.sync"}'));
    }

    public function test_authorize_exception_fails_closed(): void
    {
        $this->router->authorize(function (): void {
            throw new \RuntimeException('auth backend down');
        });

        $result = $this->router->dispatch(1, '{"action":"game.sync"}');

        $this->assertNull($result);
        $this->assertErrorFrameSent('鉴权失败，请求被拒绝');
        $this->assertNotNull($this->logger->find('鉴权回调异常'));
    }

    public function test_authorize_is_not_applied_to_ping(): void
    {
        $this->router->authorize(fn() => '拒绝');
        ExtPoint::definePoint(ExtPoints::WS_HEARTBEAT, ExtPoint::POLICY_MULTI);
        ExtPoint::register(ExtPoints::WS_HEARTBEAT, 'ping_pong', PingPongHeartbeat::class);

        // 无 action 的控制帧由内置心跳接管并直接 return，不进入业务分发，
        // 因此不受业务鉴权拦截：应正常回 PONG 而非 error 帧
        $this->assertTrue($this->router->dispatch(1, '{"type":"ping"}'));
        $this->assertCount(1, $this->server->sent);
        $frame = json_decode($this->server->sent[0][1], true);
        $this->assertSame('pong', $frame['type']);
    }

    public function test_authorize_callback_receives_authoritative_action(): void
    {
        $seen = null;
        $this->router->authorize(function (int $fd, array $payload) use (&$seen): ?string {
            $seen = $payload;
            return null;
        });
        ExtPoint::register('ws.handler.game.sync', 'handler', fn(array $payload) => 'ok');

        $this->router->dispatch(1, '{"action":"game.sync","data":{"n":1}}');

        // 回调必须拿到本次实际被路由的 action（route() 用它拼 ws.handler.{action}），
        // 而不是只能读客户端自选的业务 body
        $this->assertSame('game.sync', $seen['action']);
        $this->assertSame(['n' => 1], $seen['data']);
    }

    public function test_authorize_deny_blocks_pong_frame_that_falls_through_heartbeat(): void
    {
        $this->router->authorize(fn() => '拒绝');
        ExtPoint::definePoint(ExtPoints::WS_HEARTBEAT, ExtPoint::POLICY_MULTI);
        // 心跳层不接管 action=pong（返回 null）→ 该帧实际会进入 route()
        ExtPoint::register(ExtPoints::WS_HEARTBEAT, 'noop_heartbeat', fn() => null);

        $this->assertNull($this->router->dispatch(1, '{"action":"pong"}'));
        $this->assertErrorFrameSent('拒绝');
        $this->assertNotNull($this->logger->find('鉴权拦截 action [pong]'));
    }

    public function test_custom_heartbeat_is_skipped_for_anonymous_connection(): void
    {
        // fd=2 未绑定用户（未登录）
        $this->manager->onOpen(2, []);
        ExtPoint::definePoint(ExtPoints::WS_HEARTBEAT, ExtPoint::POLICY_MULTI);

        $invoked = false;
        ExtPoint::register(ExtPoints::WS_HEARTBEAT, 'custom', function () use (&$invoked): ?string {
            $invoked = true;
            return '{"x":1}';
        });
        ExtPoint::register(ExtPoints::WS_HEARTBEAT, 'ping_pong', PingPongHeartbeat::class);

        $result = $this->router->dispatch(2, '{"type":"ping"}');

        $this->assertTrue($result, '内置心跳仍应为未登录连接回帧');
        $this->assertFalse($invoked, '未登录连接不得执行自定义心跳处理器');
        $this->assertNotNull($this->logger->find('跳过心跳处理器 [custom]'));
        $this->assertCount(1, $this->server->sent);
        $this->assertSame('pong', json_decode($this->server->sent[0][1], true)['type']);
    }

    // ============================================================
    // ExtPoint 路由
    // ============================================================

    public function test_route_via_closure_handler_builds_payload(): void
    {
        $captured = null;
        ExtPoint::register('ws.handler.room.join', 'join', function (array $payload) use (&$captured) {
            $captured = $payload;
            return 'joined';
        });

        $result = $this->router->dispatch(1, '{"action":"room.join","room":"room_a","data":{"n":1}}');

        $this->assertSame(['join' => 'joined'], $result);
        $this->assertSame(1, $captured['fd']);
        $this->assertSame('room_a', $captured['room']);
        $this->assertSame(['n' => 1], $captured['data']);
        $this->assertSame(42, $captured['user_id']);
    }

    public function test_route_via_handler_object(): void
    {
        ExtPoint::register('ws.handler.game.frame', 'frame', RouterPayloadHandler::class);

        $result = $this->router->dispatch(1, '{"action":"game.frame","data":{"x":1}}');

        $this->assertSame(['frame' => 'handled'], $result);
    }

    public function test_route_via_channel_handler(): void
    {
        RouterChannelHandler::$received = null;
        ExtPoint::register('ws.handler.game.channel', 'channel', RouterChannelHandler::class);

        $result = $this->router->dispatch(1, '{"action":"game.channel","data":{"k":"v"}}');

        // onMessage 返回 void，故结果为 null；业务数据经静态属性捕获
        $this->assertSame(['channel' => null], $result);
        $this->assertSame([1, ['k' => 'v']], RouterChannelHandler::$received);
    }

    public function test_route_without_data_does_not_expose_envelope_as_payload_data(): void
    {
        $captured = null;
        ExtPoint::register('ws.handler.no.data', 'h', function (array $payload) use (&$captured) {
            $captured = $payload;
            return null;
        });

        // 客户端在缺省 data 时试图用帧顶层字段冒充业务数据（含自选 user_id）
        $this->router->dispatch(1, '{"action":"no.data","other":1,"user_id":999}');

        // 业务 body 只含客户端显式提供的 data；缺省即空数组（信封不并入）
        $this->assertSame([], $captured['data']);
        // 权威字段仍由服务端从连接状态推导，客户端覆盖无效
        $this->assertSame(42, $captured['user_id']);
        $this->assertSame('no.data', $captured['action']);
    }

    public function test_route_handler_exception_is_logged_and_skipped(): void
    {
        ExtPoint::register('ws.handler.boom', 'boom', function (): void {
            throw new \RuntimeException('handler boom');
        });

        $this->assertSame([], $this->router->dispatch(1, '{"action":"boom"}'));
        $this->assertNotNull($this->logger->find('处理器 [boom]'));
    }

    public function test_route_without_handler_sends_error_frame(): void
    {
        $result = $this->router->dispatch(1, '{"action":"nothing.here"}');

        $this->assertNull($result);
        $this->assertErrorFrameSent('未知操作: nothing.here');
        $this->assertNotNull($this->logger->find('无处理器处理'));
    }

    // ============================================================
    // ServiceBus 回退路由
    // ============================================================

    public function test_route_falls_back_to_service_bus(): void
    {
        $bus = new RouterServiceBusStub();
        Container::getInstance()->bind(ServiceBus::class, $bus);

        $result = $this->router->dispatch(1, '{"action":"order.created","data":{"id":7}}');

        $this->assertSame('bus-result', $result);
        $this->assertSame('order.created', $bus->publishedAction);
        $this->assertSame(7, $bus->publishedPayload['data']['id']);
        $this->assertSame(1, $bus->publishedPayload['fd']);
    }

    public function test_route_service_bus_failure_is_swallowed(): void
    {
        $bus = new RouterServiceBusStub();
        $bus->throwOnCanHandle = true;
        Container::getInstance()->bind(ServiceBus::class, $bus);

        $this->assertNull($this->router->dispatch(1, '{"action":"order.created"}'));
        $this->assertNotNull($this->logger->find('ServiceBus 路由 [order.created] 失败'));
        $this->assertErrorFrameSent('未知操作: order.created');
    }

    public function test_route_without_connection_manager_skips_service_bus(): void
    {
        $router = (new MessageRouter())->setLogger($this->logger);

        $this->assertNull($router->dispatch(1, '{"action":"order.created"}'));
        $this->assertErrorFrameSent(null, false);
    }

    // ============================================================
    // 异常帧
    // ============================================================

    public function test_error_frame_is_not_sent_without_connection_manager(): void
    {
        $router = new MessageRouter();

        $this->assertNull($router->dispatch(1, 'bad-json'));
        $this->assertSame([], $this->server->sent);
    }

    public function test_set_logger_is_chainable(): void
    {
        $router = new MessageRouter();

        $this->assertSame($router, $router->setLogger($this->logger));
        $this->assertSame($router, $router->setConnectionManager($this->manager));
    }

    // ============================================================
    // 断言辅助
    // ============================================================

    private function assertErrorFrameSent(?string $expectedMessage, bool $expectSent = true): void
    {
        if (!$expectSent) {
            $this->assertSame([], $this->server->sent);
            return;
        }

        $this->assertNotEmpty($this->server->sent, '应发送错误帧');

        $last = $this->server->sent[count($this->server->sent) - 1][1];
        $frame = json_decode($last, true);

        $this->assertSame('error', $frame['action']);
        if ($expectedMessage !== null) {
            $this->assertSame($expectedMessage, $frame['data']['message']);
        }
    }
}

// ============================================================
// 测试替身
// ============================================================

class RouterServerStub implements WsServerInterface
{
    /** @var array<int, array{0:int,1:string}> */
    public array $sent = [];

    public function start(): void {}
    public function stop(): void {}
    public function reload(): void {}
    public function send(int $fd, string $data): void { $this->sent[] = [$fd, $data]; }
    public function close(int $fd, int $code = 1000, string $reason = ''): void {}
    public function count(): int { return count($this->sent); }
    public function isOnline(int $fd): bool { return true; }
    public function getFds(): array { return []; }
    public function getName(): string { return 'stub'; }
}

class RouterLoggerStub implements \Psr\Log\LoggerInterface
{
    /** @var array<int, array{level:string,message:string}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }

    public function emergency(\Stringable|string $message, array $context = []): void { $this->log('emergency', $message, $context); }
    public function alert(\Stringable|string $message, array $context = []): void { $this->log('alert', $message, $context); }
    public function critical(\Stringable|string $message, array $context = []): void { $this->log('critical', $message, $context); }
    public function error(\Stringable|string $message, array $context = []): void { $this->log('error', $message, $context); }
    public function warning(\Stringable|string $message, array $context = []): void { $this->log('warning', $message, $context); }
    public function notice(\Stringable|string $message, array $context = []): void { $this->log('notice', $message, $context); }
    public function info(\Stringable|string $message, array $context = []): void { $this->log('info', $message, $context); }
    public function debug(\Stringable|string $message, array $context = []): void { $this->log('debug', $message, $context); }

    public function lastMessage(): string
    {
        return $this->records === [] ? '' : $this->records[count($this->records) - 1]['message'];
    }

    public function lastLevel(): string
    {
        return $this->records === [] ? '' : $this->records[count($this->records) - 1]['level'];
    }

    public function find(string $needle): ?string
    {
        foreach ($this->records as $record) {
            if (str_contains($record['message'], $needle)) {
                return $record['message'];
            }
        }
        return null;
    }
}

class RouterPayloadHandler
{
    public function handle(array $payload): string
    {
        unset($payload);
        return 'handled';
    }
}

class RouterChannelHandler implements ChannelInterface
{
    /** @var array{0:int,1:array}|null */
    public static ?array $received = null;

    public function getName(): string { return 'router.channel'; }

    public function onMessage(int $fd, array $data): void
    {
        self::$received = [$fd, $data];
    }

    public function onJoin(int $fd): void {}
    public function onLeave(int $fd): void {}
}

class RouterServiceBusStub
{
    public bool $throwOnCanHandle = false;
    public ?string $publishedAction = null;
    public array $publishedPayload = [];

    public function canHandle(string $action): bool
    {
        if ($this->throwOnCanHandle) {
            throw new \RuntimeException('bus unavailable');
        }
        return true;
    }

    public function publish(string $action, array $payload = [], ?callable $onReply = null)
    {
        $this->publishedAction  = $action;
        $this->publishedPayload = $payload;
        return 'bus-result';
    }
}
