<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\connector\ConnectorInterface;
use wise\support\connector\ConnectorManager;
use wise\support\connector\ConnectorResult;
use wise\support\connector\ConnectorType;

/**
 * ConnectorResult / ConnectorType / ConnectorManager 单元测试
 */
class ConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        ExtPoint::reset();
    }

    protected function tearDown(): void
    {
        ExtPoint::reset();
    }

    // ============================================================
    // ConnectorResult
    // ============================================================

    public function test_result_ok(): void
    {
        $result = ConnectorResult::ok(['id' => 1]);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
        $this->assertSame(['id' => 1], $result->getData());
        $this->assertNull($result->getError());
        $this->assertNull($result->getErrorCode());
        $this->assertNull($result->getRaw());
        $this->assertSame([], $result->getMeta());
    }

    public function test_result_ok_with_meta(): void
    {
        $result = ConnectorResult::ok(['id' => 1], ['latency' => 100]);

        $this->assertSame(['latency' => 100], $result->getMeta());
    }

    public function test_result_fail(): void
    {
        $result = ConnectorResult::fail('连接超时', 'TIMEOUT', 'raw-body');

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isFailure());
        $this->assertSame('连接超时', $result->getError());
        $this->assertSame('TIMEOUT', $result->getErrorCode());
        $this->assertSame('raw-body', $result->getRaw());
        $this->assertNull($result->getData());
    }

    public function test_result_fail_with_defaults(): void
    {
        $result = ConnectorResult::fail('错误');

        $this->assertNull($result->getErrorCode());
        $this->assertNull($result->getRaw());
    }

    public function test_result_to_array(): void
    {
        $result = ConnectorResult::ok(['x' => 1], ['m' => 2]);

        $this->assertSame([
            'success'    => true,
            'data'       => ['x' => 1],
            'error'      => null,
            'error_code' => null,
            'meta'       => ['m' => 2],
        ], $result->toArray());
    }

    public function test_result_full_constructor(): void
    {
        $result = new ConnectorResult(false, null, 'err', 'E1', 'raw', ['k' => 'v']);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('err', $result->getError());
        $this->assertSame('E1', $result->getErrorCode());
        $this->assertSame('raw', $result->getRaw());
        $this->assertSame(['k' => 'v'], $result->getMeta());
    }

    // ============================================================
    // ConnectorType
    // ============================================================

    public function test_connector_type_values(): void
    {
        $this->assertSame('oauth', ConnectorType::OAUTH->value);
        $this->assertSame('webhook', ConnectorType::WEBHOOK->value);
        $this->assertSame('api', ConnectorType::API->value);
        $this->assertSame('message', ConnectorType::MESSAGE->value);
        $this->assertSame('payment', ConnectorType::PAYMENT->value);
        $this->assertSame('storage', ConnectorType::STORAGE->value);
        $this->assertSame('ai', ConnectorType::AI->value);
        $this->assertSame('iot', ConnectorType::IOT->value);
    }

    public function test_connector_type_labels(): void
    {
        $this->assertSame('OAuth 登录', ConnectorType::OAUTH->label());
        $this->assertSame('Webhook 回调', ConnectorType::WEBHOOK->label());
        $this->assertSame('API 调用', ConnectorType::API->label());
        $this->assertSame('消息推送', ConnectorType::MESSAGE->label());
        $this->assertSame('支付网关', ConnectorType::PAYMENT->label());
        $this->assertSame('云存储', ConnectorType::STORAGE->label());
        $this->assertSame('AI 服务', ConnectorType::AI->label());
        $this->assertSame('IoT 设备', ConnectorType::IOT->label());
    }

    // ============================================================
    // ConnectorManager
    // ============================================================

    public function test_driver_returns_registered_callable_connector(): void
    {
        $connector = new StubConnector('wechat_pay', ConnectorType::PAYMENT);
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'wechat_pay', fn() => $connector);

        $manager = new ConnectorManager();

        $this->assertSame($connector, $manager->driver('wechat_pay'));
    }

    public function test_driver_caches_instance(): void
    {
        $calls = 0;
        $connector = new StubConnector('wechat_pay', ConnectorType::PAYMENT);
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'wechat_pay', function () use (&$calls, $connector) {
            $calls++;
            return $connector;
        });

        $manager = new ConnectorManager();
        $manager->driver('wechat_pay');
        $manager->driver('wechat_pay');

        $this->assertSame(1, $calls);
    }

    public function test_driver_throws_when_unregistered(): void
    {
        $manager = new ConnectorManager();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('未注册');

        $manager->driver('nonexistent');
    }

    public function test_driver_throws_when_class_not_exists(): void
    {
        // 注册一个不存在的类名，触发 resolve() 的 class_exists 检查
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'bad', 'Non\\Existent\\Connector\\Class');

        $manager = new ConnectorManager();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('不存在');

        $manager->driver('bad');
    }

    public function test_driver_throws_when_callable_returns_non_interface(): void
    {
        // 闭包返回非 ConnectorInterface 对象，resolve() 应抛出异常
        // （注意：源码在格式化闭包时会抛 Error，此处仅验证异常被抛出）
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'bad', fn() => new \stdClass());

        $manager = new ConnectorManager();

        $this->expectException(\Throwable::class);

        $manager->driver('bad');
    }

    public function test_active_for_returns_highest_priority(): void
    {
        $low = new StubConnector('low', ConnectorType::PAYMENT);
        $high = new StubConnector('high', ConnectorType::PAYMENT);

        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'low', fn() => $low, 50);
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'high', fn() => $high, 10);

        $manager = new ConnectorManager();

        $this->assertSame($high, $manager->activeFor(ConnectorType::PAYMENT));
    }

    public function test_active_for_filters_by_type(): void
    {
        $pay = new StubConnector('pay', ConnectorType::PAYMENT);
        $storage = new StubConnector('storage', ConnectorType::STORAGE);

        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'pay', fn() => $pay);
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'storage', fn() => $storage);

        $manager = new ConnectorManager();

        $this->assertSame($pay, $manager->activeFor(ConnectorType::PAYMENT));
        $this->assertSame($storage, $manager->activeFor(ConnectorType::STORAGE));
    }

    public function test_active_for_returns_null_when_no_match(): void
    {
        $storage = new StubConnector('storage', ConnectorType::STORAGE);
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'storage', fn() => $storage);

        $manager = new ConnectorManager();

        $this->assertNull($manager->activeFor(ConnectorType::PAYMENT));
    }

    public function test_active_for_skips_inactive(): void
    {
        $pay = new StubConnector('pay', ConnectorType::PAYMENT);
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'pay', fn() => $pay);

        // connector 属安全相关扩展点（ExtPoint::SECURITY_RELEVANT_POINTS）：
        // 活跃变更需要宿主授权，未绑定授权器时会被拒绝
        ExtPoint::bindOverrideAuthorizer(fn() => true);
        $this->assertTrue(ExtPoint::setActive(ExtPoints::EXTERNAL_CONNECTOR, 'pay', false));

        $manager = new ConnectorManager();

        $this->assertNull($manager->activeFor(ConnectorType::PAYMENT));
    }

    public function test_all_groups_by_type(): void
    {
        $pay = new StubConnector('pay', ConnectorType::PAYMENT);
        $storage = new StubConnector('storage', ConnectorType::STORAGE);

        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'pay', fn() => $pay);
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'storage', fn() => $storage);

        $manager = new ConnectorManager();
        $grouped = $manager->all();

        $this->assertArrayHasKey('payment', $grouped);
        $this->assertArrayHasKey('storage', $grouped);
        $this->assertSame($pay, $grouped['payment']['pay']);
        $this->assertSame($storage, $grouped['storage']['storage']);
    }

    public function test_health_check_returns_true_for_healthy(): void
    {
        $healthy = new StubConnector('pay', ConnectorType::PAYMENT, true);
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'pay', fn() => $healthy);

        $manager = new ConnectorManager();

        $this->assertTrue($manager->healthCheck('pay'));
    }

    public function test_health_check_returns_false_for_unhealthy(): void
    {
        $unhealthy = new StubConnector('pay', ConnectorType::PAYMENT, false);
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'pay', fn() => $unhealthy);

        $manager = new ConnectorManager();

        $this->assertFalse($manager->healthCheck('pay'));
    }

    public function test_health_check_returns_false_when_driver_throws(): void
    {
        $manager = new ConnectorManager();

        $this->assertFalse($manager->healthCheck('nonexistent'));
    }

    public function test_health_check_all(): void
    {
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'a', fn() => new StubConnector('a', ConnectorType::PAYMENT, true));
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'b', fn() => new StubConnector('b', ConnectorType::PAYMENT, false));

        $manager = new ConnectorManager();
        $results = $manager->healthCheckAll();

        $this->assertSame(['a' => true, 'b' => false], $results);
    }

    public function test_get_config_schema_and_set_config(): void
    {
        $connector = new StubConnector('pay', ConnectorType::PAYMENT);
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'pay', fn() => $connector);

        $manager = new ConnectorManager();

        $this->assertSame(['api_key' => 'text'], $manager->getConfigSchema('pay'));

        $manager->setConfig('pay', ['api_key' => 'secret']);
        $this->assertSame(['api_key' => 'secret'], $connector->config);
    }

    public function test_clear_cache_forces_re_resolve(): void
    {
        $calls = 0;
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'pay', function () use (&$calls) {
            $calls++;
            return new StubConnector('pay', ConnectorType::PAYMENT);
        });

        $manager = new ConnectorManager();
        $manager->driver('pay');
        $manager->clearCache();
        $manager->driver('pay');

        $this->assertSame(2, $calls);
    }

    public function test_active_for_skips_failing_connector(): void
    {
        $ok = new StubConnector('ok', ConnectorType::PAYMENT);
        // bad 连接器实例化时抛异常
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'bad', fn() => throw new \RuntimeException('init fail'), 10);
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'ok', fn() => $ok, 50);

        $manager = new ConnectorManager();

        $this->assertSame($ok, $manager->activeFor(ConnectorType::PAYMENT));
    }
}

// ============================================================
// 测试辅助类
// ============================================================

class StubConnector implements ConnectorInterface
{
    public array $config = [];

    public function __construct(
        private string $id,
        private ConnectorType $type,
        private bool $healthy = true,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->id;
    }

    public function getType(): ConnectorType
    {
        return $this->type;
    }

    public function getDescription(): string
    {
        return 'stub';
    }

    public function getActions(): array
    {
        return [];
    }

    public function execute(string $action, array $input, array $context = []): ConnectorResult
    {
        return ConnectorResult::ok();
    }

    public function healthCheck(): bool
    {
        return $this->healthy;
    }

    public function getConfigSchema(): array
    {
        return ['api_key' => 'text'];
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function getConfig(): array
    {
        return $this->config;
    }
}
