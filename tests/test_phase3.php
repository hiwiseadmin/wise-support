<?php
declare(strict_types=1);

/**
 * Phase 3 子系统验证脚本
 *
 * 运行方式: php tests/test_phase3.php
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'wise\\support\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) === 0) {
        $relativeClass = substr($class, $len);
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// 加载测试辅助类
require_once __DIR__ . '/_fixtures_phase3.php';

use wise\support\connector\ConnectorInterface;
use wise\support\connector\ConnectorManager;
use wise\support\connector\ConnectorResult;
use wise\support\connector\ConnectorType;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\bus\Message;
use wise\support\bus\ServiceBus;

$pass = 0;
$fail = 0;

function assert_eq($label, $expected, $actual): void {
    global $pass, $fail;
    if ($expected === $actual) {
        $pass++;
        echo "  \033[32m✓\033[0m {$label}\n";
    } else {
        $fail++;
        echo "  \033[31m✗\033[0m {$label}\n";
        echo "    expected: " . var_export($expected, true) . "\n";
        echo "    actual:   " . var_export($actual, true) . "\n";
    }
}

function assert_true($label, bool $condition): void {
    assert_eq($label, true, $condition);
}

function assert_not_null($label, $value): void {
    global $pass, $fail;
    if ($value !== null) {
        $pass++;
        echo "  \033[32m✓\033[0m {$label}\n";
    } else {
        $fail++;
        echo "  \033[31m✗\033[0m {$label} — is null\n";
    }
}

function assert_contains($label, string $haystack, string $needle): void {
    global $pass, $fail;
    if (str_contains($haystack, $needle)) {
        $pass++;
        echo "  \033[32m✓\033[0m {$label}\n";
    } else {
        $fail++;
        echo "  \033[31m✗\033[0m {$label}\n";
        echo "    '{$needle}' not found in '{$haystack}'\n";
    }
}

echo "=== Phase 3: ServiceBus + Connector 子系统验证 ===\n\n";

// ============================================================
// 1. Message 消息
// ============================================================
echo "1. Message\n";

$request = Message::request('payment.charge', ['amount' => 100]);
assert_eq('请求消息类型', Message::TYPE_REQUEST, $request->getType());
assert_eq('请求消息 action', 'payment.charge', $request->getAction());
assert_eq('请求消息 payload', 100, $request->get('amount'));
assert_true('请求消息 isRequest', $request->isRequest());
assert_not_null('请求消息 ID', $request->getId());

$event = Message::event('order.created', ['order_id' => 42]);
assert_eq('事件消息类型', Message::TYPE_EVENT, $event->getType());
assert_true('事件消息 isEvent', $event->isEvent());

$reply = Message::reply($request, ['transaction_id' => 'tx_123']);
assert_eq('回复消息类型', Message::TYPE_REPLY, $reply->getType());
assert_eq('回复关联 ID', $request->getId(), $reply->getCorrelationId());
assert_true('回复消息 isReply', $reply->isReply());

$error = Message::error($request, '余额不足', 402);
assert_true('错误回复 isError', $error->isError());
assert_eq('错误消息', '余额不足', $error->getError());
assert_contains('错误回复 action', $error->getAction(), '.reply');

// 序列化
$serialized = $request->toArray();
$restored = Message::fromArray($serialized);
assert_eq('序列化往返 type', $request->getType(), $restored->getType());
assert_eq('序列化往返 action', $request->getAction(), $restored->getAction());

echo "\n2. ServiceBus\n";
ExtPoint::reset();

// 注册消息处理器
ExtPoint::definePoint('bus.handler.payment.charge', ExtPoint::POLICY_SINGLE);
ExtPoint::register('bus.handler.payment.charge', 'wechat', TestPaymentHandler::class);

ExtPoint::definePoint('bus.handler.order.created', ExtPoint::POLICY_MULTI);
ExtPoint::register('bus.handler.order.created', 'email', TestNotificationHandler::class);
ExtPoint::register('bus.handler.order.created', 'sms', TestNotificationHandler::class);

ExtPoint::definePoint('bus.handler.cache.invalidate', ExtPoint::POLICY_MULTI);
ExtPoint::register('bus.handler.cache.invalidate', 'tag_cleaner', TestCacheHandler::class);
ExtPoint::register('bus.handler.cache.invalidate', 'full_flush', TestCacheHandler::class);

$bus = new ServiceBus();

// ask — 请求-响应
$result = $bus->ask('payment.charge', ['amount' => 100]);
assert_not_null('ask 返回结果', $result);
assert_true('ask 结果非错误', !$result->isError());
assert_eq('ask 处理器名称标记', 'wechat', $result->get('handler'));

// ask — 无处理器时返回错误
$result2 = $bus->ask('nonexistent.action', []);
assert_true('ask 无处理器返回错误', $result2->isError());
assert_contains('ask 错误消息', $result2->getError(), '没有注册的处理器');

// publish — 发布-订阅
$publishedCount = 0;
$bus->publish('order.created', ['order_id' => 42], function (Message $reply) use (&$publishedCount) {
    $publishedCount++;
});
assert_eq('publish 触发2个处理器', 2, $publishedCount);

// broadcast — 广播
$bus->broadcast('cache.invalidate', ['tag' => 'product_list']);
// broadcast 不抛异常即为成功

// canHandle / listHandlers
assert_true('canHandle payment.charge', $bus->canHandle('payment.charge'));
assert_true('canHandle order.created', $bus->canHandle('order.created'));
assert_eq('canHandle nonexistent', false, $bus->canHandle('nonexistent'));

$handlers = $bus->listHandlers('order.created');
assert_eq('listHandlers 数量', 2, count($handlers));

echo "\n3. ConnectorManager + ConnectorInterface\n";
ExtPoint::reset();

// 定义连接器扩展点
ExtPoint::definePoint(ExtPoints::EXTERNAL_CONNECTOR, ExtPoint::POLICY_ROUTED);

// 注册测试连接器
ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'wechat_pay', TestPaymentConnector::class);
ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'aliyun_sms', TestSmsConnector::class);
ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, 'github_oauth', TestOAuthConnector::class);

$connManager = new ConnectorManager();

// 按名称获取
$wechat = $connManager->driver('wechat_pay');
assert_eq('连接器 ID', 'wechat_pay', $wechat->getId());
assert_eq('连接器名称', '微信支付', $wechat->getName());
assert_eq('连接器类型', ConnectorType::PAYMENT, $wechat->getType());

// 执行操作
$payResult = $wechat->execute('charge', ['amount' => 100]);
assert_true('连接器执行成功', $payResult->isSuccess());
assert_eq('连接器返回数据', 'charged', $payResult->getData());

// 失败场景
$failResult = $wechat->execute('charge', ['amount' => -1]);
assert_true('连接器执行失败', $failResult->isFailure());
assert_contains('连接器错误消息', $failResult->getError(), '无效金额');

// activeFor
$activePayment = $connManager->activeFor(ConnectorType::PAYMENT);
assert_not_null('activeFor PAYMENT', $activePayment);
assert_eq('activeFor 返回微信支付', 'wechat_pay', $activePayment->getId());

$activeMessage = $connManager->activeFor(ConnectorType::MESSAGE);
assert_not_null('activeFor MESSAGE', $activeMessage);
assert_eq('activeFor 返回阿里云短信', 'aliyun_sms', $activeMessage->getId());

// healthCheck
assert_true('wechat_pay 健康检查', $connManager->healthCheck('wechat_pay'));

// all — 按类型分组
$all = $connManager->all();
assert_eq('all 包含 payment 类型', true, isset($all['payment']));
assert_eq('all 包含 message 类型', true, isset($all['message']));
assert_eq('all 包含 oauth 类型', true, isset($all['oauth']));

// 未注册的连接器
try {
    $connManager->driver('nonexistent');
    assert_eq('未注册连接器抛异常', 'FAILED', 'OK');
} catch (\RuntimeException $e) {
    assert_contains('未注册连接器消息', $e->getMessage(), '未注册');
}

echo "\n4. ConnectorResult\n";
$ok = ConnectorResult::ok(['id' => 't_123']);
assert_true('ok isSuccess', $ok->isSuccess());
assert_eq('ok getData', ['id' => 't_123'], $ok->getData());

$fail = ConnectorResult::fail('网络超时', 'TIMEOUT', 'raw_response');
assert_true('fail isFailure', $fail->isFailure());
assert_eq('fail getError', '网络超时', $fail->getError());
assert_eq('fail getErrorCode', 'TIMEOUT', $fail->getErrorCode());
assert_eq('fail getRaw', 'raw_response', $fail->getRaw());

echo "\n5. ConnectorType 枚举\n";
assert_eq('PAYMENT value', 'payment', ConnectorType::PAYMENT->value);
assert_eq('PAYMENT label', '支付网关', ConnectorType::PAYMENT->label());
assert_eq('OAUTH label', 'OAuth 登录', ConnectorType::OAUTH->label());
assert_eq('STORAGE label', '云存储', ConnectorType::STORAGE->label());

// ============================================================
// 结果
// ============================================================
echo "\n============================\n";
printf("  通过: %d  |  失败: %d\n", $pass, $fail);
echo "============================\n";

if ($fail > 0) {
    exit(1);
}

