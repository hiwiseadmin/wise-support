<?php
declare(strict_types=1);

/**
 * ExtPoint 快速验证脚本
 *
 * 运行方式: php tests/test_extpoint.php
 */

// --- 手动 PSR-4 ---
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
require_once __DIR__ . '/_fixtures.php';

use wise\support\ExtPoint;
use wise\support\ExtPoints;

$pass = 0;
$fail = 0;

function assert_eq($label, $expected, $actual): void {
    global $pass, $fail;
    if ($expected === $actual) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        echo "  ✗ {$label}\n";
        echo "    expected: " . var_export($expected, true) . "\n";
        echo "    actual:   " . var_export($actual, true) . "\n";
    }
}

function assert_count($label, int $expected, array $actual): void {
    assert_eq($label, $expected, count($actual));
}

function assert_contains($label, array $haystack, $needle): void {
    global $pass, $fail;
    if (in_array($needle, $haystack, true)) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        echo "  ✗ {$label}\n";
        echo "    needle [{$needle}] not found in " . json_encode($haystack) . "\n";
    }
}

function assert_throws($label, callable $fn, string $expectedMsg): void {
    global $pass, $fail;
    try {
        $fn();
        $fail++;
        echo "  ✗ {$label} — 未抛出异常\n";
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), $expectedMsg)) {
            $pass++;
            echo "  ✓ {$label}\n";
        } else {
            $fail++;
            echo "  ✗ {$label} — 异常消息不匹配\n";
            echo "    expected to contain: {$expectedMsg}\n";
            echo "    actual: {$e->getMessage()}\n";
        }
    }
}

echo "=== ExtPoint 测试 ===\n\n";

// --- 1. definePoint ---
echo "1. definePoint\n";
ExtPoint::reset();
ExtPoint::definePoint('test.single', ExtPoint::POLICY_SINGLE);
$def = ExtPoint::getDefinition('test.single');
assert_eq('定义 single 扩展点', ExtPoint::POLICY_SINGLE, $def['policy']);

ExtPoint::definePoint('test.contract', ExtPoint::POLICY_SINGLE, TestInterface::class);
$def2 = ExtPoint::getDefinition('test.contract');
assert_eq('定义含契约的扩展点', TestInterface::class, $def2['contract']);

assert_eq('defined 返回 true', true, ExtPoint::defined('test.single'));
assert_eq('defined 返回 false', false, ExtPoint::defined('never'));

assert_throws('无效策略抛异常', function () {
    ExtPoint::definePoint('bad', 'invalid_strategy');
}, '策略');

// --- 2. register ---
echo "\n2. register\n";
ExtPoint::reset();
ExtPoint::definePoint('payment.driver', ExtPoint::POLICY_SINGLE);
ExtPoint::register('payment.driver', 'wechat', WechatPay::class);
$all = ExtPoint::all('payment.driver');
assert_count('注册一个实现', 1, $all);
assert_eq('获取实现', WechatPay::class, ExtPoint::get('payment.driver', 'wechat'));

ExtPoint::register('payment.driver', 'alipay', Alipay::class);
assert_count('注册第二个实现', 2, ExtPoint::all('payment.driver'));

// 同名覆盖
ExtPoint::register('payment.driver', 'wechat', 'NewWechatPay');
assert_eq('同名覆盖', 'NewWechatPay', ExtPoint::get('payment.driver', 'wechat'));

// 契约校验
ExtPoint::definePoint('test.contract', ExtPoint::POLICY_SINGLE, TestInterface::class);
ExtPoint::register('test.contract', 'valid', TestImpl::class);
assert_eq('契约校验通过', TestImpl::class, ExtPoint::get('test.contract', 'valid'));

assert_throws('契约校验失败', function () {
    ExtPoint::register('test.contract', 'bad', BadImpl::class);
}, '不符合契约');

// 无定义也允许注册（宽松模式）
ExtPoint::register('undef.point', 'key', 'SomeClass');
assert_eq('未定义点注册', 'SomeClass', ExtPoint::get('undef.point', 'key'));

// 优先级排序
ExtPoint::definePoint('test.priority', ExtPoint::POLICY_MULTI);
ExtPoint::register('test.priority', 'medium', 'Medium', 50);
ExtPoint::register('test.priority', 'high', 'High', 10);
ExtPoint::register('test.priority', 'low', 'Low', 100);
$ordered = array_keys(ExtPoint::all('test.priority'));
assert_eq('优先级排序', ['high', 'medium', 'low'], $ordered);

// --- 3. single 策略 — active ---
echo "\n3. single 策略 — active\n";
ExtPoint::reset();
ExtPoint::definePoint('payment.driver', ExtPoint::POLICY_SINGLE);
ExtPoint::register('payment.driver', 'wechat', WechatPay::class);
$active = ExtPoint::active('payment.driver');
assert_eq('首注册自动 active', 'wechat', $active['name']);

ExtPoint::register('payment.driver', 'alipay', Alipay::class);
$active2 = ExtPoint::active('payment.driver');
assert_eq('第二注册不抢 active', 'wechat', $active2['name']);

$all = ExtPoint::all('payment.driver');
assert_eq('支付宝是候补', false, $all['alipay']['active']);

// 手动切换
ExtPoint::setActive('payment.driver', 'alipay', true);
$active3 = ExtPoint::active('payment.driver');
assert_eq('切换 active 到支付宝', 'alipay', $active3['name']);

// 验证 single 下只有一个 active
$afterSwitch = ExtPoint::all('payment.driver');
assert_eq('微信已停用', false, $afterSwitch['wechat']['active']);
assert_eq('支付宝已激活', true, $afterSwitch['alipay']['active']);

// active 返回 null
ExtPoint::definePoint('empty', ExtPoint::POLICY_SINGLE);
assert_eq('空点 active 为 null', null, ExtPoint::active('empty'));

// --- 4. multi 策略 ---
echo "\n4. multi 策略\n";
ExtPoint::reset();
ExtPoint::definePoint('notification.channel', ExtPoint::POLICY_MULTI);
ExtPoint::register('notification.channel', 'sms', 'Sms');
ExtPoint::register('notification.channel', 'email', 'Email');
assert_count('multi 全部注册', 2, ExtPoint::all('notification.channel'));

// --- 5. routed 策略 ---
echo "\n5. routed 策略\n";
ExtPoint::reset();
ExtPoint::definePoint('oauth.driver', ExtPoint::POLICY_ROUTED);
ExtPoint::register('oauth.driver', 'github', 'GithubOAuth');
ExtPoint::register('oauth.driver', 'wechat', 'WechatOAuth');
assert_eq('按名称获取 github', 'GithubOAuth', ExtPoint::get('oauth.driver', 'github'));
assert_eq('按名称获取 wechat', 'WechatOAuth', ExtPoint::get('oauth.driver', 'wechat'));
assert_eq('不存在的返回 null', null, ExtPoint::get('oauth.driver', 'google'));

// --- 6. resolve ---
echo "\n6. resolve\n";
ExtPoint::reset();
ExtPoint::definePoint('payment.driver', ExtPoint::POLICY_SINGLE);
ExtPoint::register('payment.driver', 'wechat', WechatPay::class);
ExtPoint::register('payment.driver', 'alipay', Alipay::class);
$resolved = ExtPoint::resolve('payment.driver');
assert_count('resolve single 返回活跃', 1, $resolved);

ExtPoint::definePoint('notification.channel', ExtPoint::POLICY_MULTI);
ExtPoint::register('notification.channel', 'sms', 'Sms');
ExtPoint::register('notification.channel', 'email', 'Email');
assert_count('resolve multi 返回全部', 2, ExtPoint::resolve('notification.channel'));

// --- 7. unregister / has / count / points ---
echo "\n7. unregister / has / count / points\n";
ExtPoint::reset();
ExtPoint::definePoint('test.ops', ExtPoint::POLICY_MULTI);
ExtPoint::register('test.ops', 'a', 'A');
ExtPoint::register('test.ops', 'b', 'B');
assert_eq('has 存在', true, ExtPoint::has('test.ops', 'a'));
ExtPoint::unregister('test.ops', 'a');
assert_eq('has 删除后不存在', false, ExtPoint::has('test.ops', 'a'));
assert_eq('count 减少', 1, ExtPoint::count('test.ops'));

ExtPoint::register('test.ops', 'c', 'C');
assert_contains('points 列出所有点', ExtPoint::points(), 'test.ops');

// --- 8. reset ---
echo "\n8. reset\n";
ExtPoint::reset();
assert_eq('reset 后 defined 为假', false, ExtPoint::defined('payment.driver'));
assert_eq('reset 后 isEmpty 为真', true, ExtPoint::isEmpty('payment.driver'));

// --- 9. ExtPoints 常量 ---
echo "\n9. ExtPoints 常量\n";
assert_eq('PAYMENT_DRIVER 常量', 'payment.driver', ExtPoints::PAYMENT_DRIVER);
assert_eq('OAUTH_DRIVER 常量', 'oauth.driver', ExtPoints::OAUTH_DRIVER);
assert_eq('NOTIFICATION_CHANNEL 常量', 'notification.channel', ExtPoints::NOTIFICATION_CHANNEL);
assert_eq('EXTERNAL_CONNECTOR 常量', 'connector', ExtPoints::EXTERNAL_CONNECTOR);

// --- 结果 ---
echo "\n============================\n";
echo "  通过: {$pass}  |  失败: {$fail}\n";
echo "============================\n";

if ($fail > 0) {
    exit(1);
}
