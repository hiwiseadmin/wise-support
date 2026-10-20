<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\connector\AbstractConnector;
use wise\support\connector\ConnectorInterface;
use wise\support\connector\ConnectorManager;
use wise\support\connector\ConnectorResult;
use wise\support\connector\ConnectorType;
use wise\support\connector\PaymentConnectorInterface;
use wise\support\connector\SmsConnectorInterface;
use wise\support\connector\StorageConnectorInterface;

/**
 * 领域连接器契约 + ConnectorManager 便捷方法测试
 *
 * 验证「统一契约管共性、领域子接口管个性」的分层：
 *   - ConnectorManager::payment()/sms()/storage() 的强类型断言与收口；
 *   - 同类型下按领域契约筛选（避免选到同类型但契约不符的连接器）；
 *   - 领域连接器 execute() 动态分发到强类型方法。
 */
class DomainConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        ExtPoint::reset();
        ExtPoint::definePoint(
            ExtPoints::EXTERNAL_CONNECTOR,
            ExtPoint::POLICY_ROUTED,
            ConnectorInterface::class
        );
    }

    protected function tearDown(): void
    {
        ExtPoint::reset();
    }

    // ============================================================
    // payment()
    // ============================================================

    public function test_payment_returns_typed_connector(): void
    {
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'wechat_pay', fn () => new StubPayConnector());

        $this->assertInstanceOf(PaymentConnectorInterface::class, (new ConnectorManager())->payment());
    }

    public function test_payment_throws_when_no_connector(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ConnectorManager())->payment();
    }

    public function test_payment_ignores_non_payment_message_connector(): void
    {
        // 同点下存在 MESSAGE 类型的短信连接器，payment() 不应命中
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'aliyun_sms', fn () => new StubSmsConnector());

        $this->expectException(\RuntimeException::class);
        (new ConnectorManager())->payment();
    }

    // ============================================================
    // sms()：同类型（MESSAGE）下按契约筛选
    // ============================================================

    public function test_sms_filters_by_contract_within_same_type(): void
    {
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'generic_message', fn () => new StubMessageConnector());
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'aliyun_sms', fn () => new StubSmsConnector());

        $this->assertInstanceOf(SmsConnectorInterface::class, (new ConnectorManager())->sms());
    }

    public function test_sms_throws_when_only_non_sms_message_connector(): void
    {
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'generic_message', fn () => new StubMessageConnector());

        $this->expectException(\RuntimeException::class);
        (new ConnectorManager())->sms();
    }

    // ============================================================
    // storage()
    // ============================================================

    public function test_storage_by_name(): void
    {
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'local', fn () => new StubStorageConnector());

        $this->assertInstanceOf(StorageConnectorInterface::class, (new ConnectorManager())->storage('local'));
    }

    public function test_storage_by_active_type(): void
    {
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'local', fn () => new StubStorageConnector());

        $this->assertInstanceOf(StorageConnectorInterface::class, (new ConnectorManager())->storage());
    }

    public function test_storage_by_name_throws_for_wrong_contract(): void
    {
        ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'wechat_pay', fn () => new StubPayConnector());

        $this->expectException(\RuntimeException::class);
        (new ConnectorManager())->storage('wechat_pay');
    }

    // ============================================================
    // execute() 动态分发（与强类型方法共享实现）
    // ============================================================

    public function test_payment_execute_dispatches_to_charge(): void
    {
        $result = (new StubPayConnector())->execute('charge', ['out_trade_no' => 'T1', 'amount' => 100]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('T1', $result->getData()['out_trade_no']);
    }

    public function test_payment_execute_unknown_action(): void
    {
        $result = (new StubPayConnector())->execute('nope', []);

        $this->assertTrue($result->isFailure());
        $this->assertSame('UNSUPPORTED_ACTION', $result->getErrorCode());
    }
}

// ============================================================
// 测试桩
// ============================================================

class StubPayConnector extends AbstractConnector implements PaymentConnectorInterface
{
    public function getId(): string
    {
        return 'stub_pay';
    }

    public function getType(): ConnectorType
    {
        return ConnectorType::PAYMENT;
    }

    public function charge(array $order): array
    {
        return ['out_trade_no' => (string) ($order['out_trade_no'] ?? '')];
    }

    public function queryOrder(string $outTradeNo): array
    {
        return ['out_trade_no' => $outTradeNo];
    }

    public function refund(string $outTradeNo, int $amount, array $options = []): array
    {
        return ['ok' => true];
    }

    public function execute(string $action, array $input, array $context = []): ConnectorResult
    {
        return match ($action) {
            'charge' => ConnectorResult::ok($this->charge($input)),
            default  => ConnectorResult::fail('不支持的操作', 'UNSUPPORTED_ACTION'),
        };
    }
}

class StubSmsConnector extends AbstractConnector implements SmsConnectorInterface
{
    public function getId(): string
    {
        return 'stub_sms';
    }

    public function getType(): ConnectorType
    {
        return ConnectorType::MESSAGE;
    }

    public function send(string $phone, string $templateCode, array $params = [], array $options = []): ConnectorResult
    {
        return ConnectorResult::ok(['success' => true, 'response' => 'ok']);
    }

    public function sendBatch(array $phones, string $templateCode, array $params = [], array $options = []): ConnectorResult
    {
        return ConnectorResult::ok(['success' => count($phones), 'failed' => 0, 'failed_list' => []]);
    }
}

/**
 * MESSAGE 类型但未实现 SmsConnectorInterface（模拟邮件 / IM 连接器）
 */
class StubMessageConnector extends AbstractConnector
{
    public function getId(): string
    {
        return 'stub_message';
    }

    public function getType(): ConnectorType
    {
        return ConnectorType::MESSAGE;
    }
}

class StubStorageConnector extends AbstractConnector implements StorageConnectorInterface
{
    public function getId(): string
    {
        return 'stub_storage';
    }

    public function getType(): ConnectorType
    {
        return ConnectorType::STORAGE;
    }

    public function upload(string $localPath, string $objectName): array
    {
        return ['url' => 'u', 'path' => $objectName, 'size' => 0, 'mime_type' => 'x', 'storage' => 'stub'];
    }

    public function delete(string $objectName): bool
    {
        return true;
    }

    public function url(string $objectName, array $options = []): string
    {
        return 'u/' . $objectName;
    }

    public function exists(string $objectName): bool
    {
        return true;
    }

    public function size(string $objectName): int
    {
        return 0;
    }

    public function mimeType(string $objectName): string
    {
        return 'text/plain';
    }

    public function getDriverName(): string
    {
        return 'stub';
    }

    public function copy(string $source, string $destination): bool
    {
        return true;
    }

    public function move(string $source, string $destination): bool
    {
        return true;
    }

    public function listFiles(string $directory = '', bool $recursive = false): array
    {
        return [];
    }

    public function makeDirectory(string $directory): bool
    {
        return true;
    }

    public function deleteDirectory(string $directory): bool
    {
        return true;
    }

    public function temporaryUrl(string $objectName, int $expires = 3600, array $options = []): string
    {
        return 't';
    }

    public function deleteMultiple(array $objectNames): array
    {
        return ['success' => count($objectNames), 'failed' => 0];
    }

    public function lastModified(string $objectName): int
    {
        return 0;
    }

    public function getMetadata(string $objectName): array
    {
        return [];
    }

    public function setVisibility(string $objectName, string $visibility): bool
    {
        return true;
    }

    public function getVisibility(string $objectName): string
    {
        return 'public';
    }
}
