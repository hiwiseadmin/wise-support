<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use wise\support\ExtPoint;

/**
 * ExtPoint 统一扩展点注册中心单元测试
 */
class ExtPointTest extends TestCase
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
    // definePoint — 扩展点定义
    // ============================================================

    public function test_define_point_with_valid_policy(): void
    {
        ExtPoint::definePoint('test.single', ExtPoint::POLICY_SINGLE);

        $def = ExtPoint::getDefinition('test.single');

        $this->assertNotNull($def);
        $this->assertEquals(ExtPoint::POLICY_SINGLE, $def['policy']);
        $this->assertNull($def['contract']);
    }

    public function test_define_point_with_contract(): void
    {
        ExtPoint::definePoint('test.contract', ExtPoint::POLICY_SINGLE, TestableInterface::class);

        $def = ExtPoint::getDefinition('test.contract');

        $this->assertEquals(TestableInterface::class, $def['contract']);
    }

    public function test_define_point_with_invalid_policy_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('策略');

        ExtPoint::definePoint('test.invalid', 'unknown_policy');
    }

    public function test_define_point_initializes_registry_slot(): void
    {
        ExtPoint::definePoint('test.slot', ExtPoint::POLICY_MULTI);

        $this->assertTrue(ExtPoint::isEmpty('test.slot'));
        $this->assertEquals(0, ExtPoint::count('test.slot'));
    }

    public function test_defined_returns_false_for_undefined(): void
    {
        $this->assertFalse(ExtPoint::defined('never.defined'));
    }

    public function test_defined_returns_true_after_definition(): void
    {
        ExtPoint::definePoint('test.defined', ExtPoint::POLICY_MULTI);

        $this->assertTrue(ExtPoint::defined('test.defined'));
    }

    public function test_get_policy_returns_default_for_undefined(): void
    {
        $this->assertEquals(ExtPoint::POLICY_MULTI, ExtPoint::getPolicy('undefined.point'));
    }

    // ============================================================
    // register — 实现注册
    // ============================================================

    public function test_register_single_implementation(): void
    {
        ExtPoint::definePoint('payment.driver', ExtPoint::POLICY_SINGLE);
        ExtPoint::register('payment.driver', 'wechat', TestStubDriver::class);

        $all = ExtPoint::all('payment.driver');

        $this->assertCount(1, $all);
        $this->assertEquals(TestStubDriver::class, $all['wechat']['concrete']);
    }

    public function test_register_multiple_implementations(): void
    {
        ExtPoint::definePoint('notification.channel', ExtPoint::POLICY_MULTI);
        ExtPoint::register('notification.channel', 'sms', 'SmsChannel');
        ExtPoint::register('notification.channel', 'email', 'EmailChannel');
        ExtPoint::register('notification.channel', 'push', 'PushChannel');

        $all = ExtPoint::all('notification.channel');

        $this->assertCount(3, $all);
    }

    public function test_register_contract_validation_passes(): void
    {
        ExtPoint::definePoint('test.contract', ExtPoint::POLICY_SINGLE, TestableInterface::class);
        ExtPoint::register('test.contract', 'valid', TestStubDriver::class);

        $this->assertEquals(TestStubDriver::class, ExtPoint::get('test.contract', 'valid'));
    }

    public function test_register_contract_validation_fails(): void
    {
        ExtPoint::definePoint('test.contract', ExtPoint::POLICY_SINGLE, TestableInterface::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('不符合契约');

        ExtPoint::register('test.contract', 'invalid', NonConformingClass::class);
    }

    public function test_register_without_definition_does_not_throw(): void
    {
        // 未定义的扩展点也允许注册（宽松模式），策略默认为 multi
        ExtPoint::register('unknown.point', 'some', 'SomeClass');

        $this->assertEquals('SomeClass', ExtPoint::get('unknown.point', 'some'));
    }

    public function test_register_override_same_name(): void
    {
        ExtPoint::definePoint('test.override', ExtPoint::POLICY_MULTI);
        ExtPoint::register('test.override', 'key', 'FirstClass');
        ExtPoint::register('test.override', 'key', 'SecondClass');

        $this->assertEquals('SecondClass', ExtPoint::get('test.override', 'key'));
    }

    public function test_register_with_priority_sorting(): void
    {
        ExtPoint::definePoint('test.priority', ExtPoint::POLICY_MULTI);
        ExtPoint::register('test.priority', 'medium', 'MediumClass', 50);
        ExtPoint::register('test.priority', 'low', 'LowClass', 100);
        ExtPoint::register('test.priority', 'high', 'HighClass', 10);

        $all = ExtPoint::all('test.priority');
        $keys = array_keys($all);

        $this->assertEquals(['high', 'medium', 'low'], $keys);
    }

    public function test_register_callable_factory(): void
    {
        ExtPoint::definePoint('test.factory', ExtPoint::POLICY_SINGLE);
        $factory = fn() => new \stdClass();
        ExtPoint::register('test.factory', 'closure', $factory);

        $this->assertIsCallable(ExtPoint::get('test.factory', 'closure'));
    }

    // ============================================================
    // single 策略：active / setActive
    // ============================================================

    public function test_single_policy_first_registration_auto_active(): void
    {
        ExtPoint::definePoint('payment.driver', ExtPoint::POLICY_SINGLE);
        ExtPoint::register('payment.driver', 'wechat', 'WechatPay');

        $active = ExtPoint::active('payment.driver');

        $this->assertNotNull($active);
        $this->assertEquals('wechat', $active['name']);
        $this->assertEquals('WechatPay', $active['concrete']);
        $this->assertTrue($active['active']);
    }

    public function test_single_policy_second_registration_not_active(): void
    {
        ExtPoint::definePoint('payment.driver', ExtPoint::POLICY_SINGLE);
        ExtPoint::register('payment.driver', 'wechat', 'WechatPay');
        ExtPoint::register('payment.driver', 'alipay', 'Alipay');

        $active = ExtPoint::active('payment.driver');

        // 首注册者保持 active
        $this->assertEquals('wechat', $active['name']);

        // 验证支付宝是候补
        $all = ExtPoint::all('payment.driver');
        $this->assertFalse($all['alipay']['active']);
    }

    public function test_set_active_switches_active_implementation(): void
    {
        ExtPoint::definePoint('payment.driver', ExtPoint::POLICY_SINGLE);
        ExtPoint::register('payment.driver', 'wechat', 'WechatPay');
        ExtPoint::register('payment.driver', 'alipay', 'Alipay');

        // 切换到支付宝
        ExtPoint::setActive('payment.driver', 'alipay', true);

        $active = ExtPoint::active('payment.driver');
        $this->assertEquals('alipay', $active['name']);

        // 验证微信已被停用
        $all = ExtPoint::all('payment.driver');
        $this->assertFalse($all['wechat']['active']);
        $this->assertTrue($all['alipay']['active']);
    }

    public function test_set_active_single_policy_only_one_active(): void
    {
        ExtPoint::definePoint('payment.driver', ExtPoint::POLICY_SINGLE);
        ExtPoint::register('payment.driver', 'wechat', 'WechatPay');
        ExtPoint::register('payment.driver', 'alipay', 'Alipay');
        ExtPoint::register('payment.driver', 'unionpay', 'UnionPay');

        ExtPoint::setActive('payment.driver', 'alipay', true);

        $all = ExtPoint::all('payment.driver');
        $activeCount = 0;
        foreach ($all as $item) {
            if ($item['active']) {
                $activeCount++;
            }
        }

        $this->assertEquals(1, $activeCount, 'single 策略下同时只有一个 active');
        $this->assertEquals('alipay', ExtPoint::active('payment.driver')['name']);
    }

    public function test_active_returns_highest_priority_when_none_active(): void
    {
        ExtPoint::definePoint('payment.driver', ExtPoint::POLICY_SINGLE);
        ExtPoint::register('payment.driver', 'wechat', 'WechatPay', 50);
        ExtPoint::register('payment.driver', 'alipay', 'Alipay', 10);

        // 手动停用所有
        ExtPoint::setActive('payment.driver', 'wechat', false);
        ExtPoint::setActive('payment.driver', 'alipay', false);

        $active = ExtPoint::active('payment.driver');
        // 应返回 priority 最高的（alipay priority=10）
        $this->assertEquals('alipay', $active['name']);
    }

    public function test_active_returns_null_for_empty_point(): void
    {
        ExtPoint::definePoint('empty.point', ExtPoint::POLICY_SINGLE);

        $this->assertNull(ExtPoint::active('empty.point'));
    }

    // ============================================================
    // multi 策略
    // ============================================================

    public function test_multi_policy_all_parallel(): void
    {
        ExtPoint::definePoint('notification.channel', ExtPoint::POLICY_MULTI);
        ExtPoint::register('notification.channel', 'sms', 'SmsChannel');
        ExtPoint::register('notification.channel', 'email', 'EmailChannel');

        $all = ExtPoint::all('notification.channel');

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('sms', $all);
        $this->assertArrayHasKey('email', $all);
    }

    public function test_multi_policy_set_active_independent(): void
    {
        ExtPoint::definePoint('notification.channel', ExtPoint::POLICY_MULTI);
        ExtPoint::register('notification.channel', 'sms', 'SmsChannel');
        ExtPoint::register('notification.channel', 'email', 'EmailChannel');

        ExtPoint::setActive('notification.channel', 'sms', false);

        $all = ExtPoint::all('notification.channel');
        $this->assertFalse($all['sms']['active']);
        $this->assertTrue($all['email']['active']);
    }

    // ============================================================
    // routed 策略
    // ============================================================

    public function test_routed_policy_named_lookup(): void
    {
        ExtPoint::definePoint('oauth.driver', ExtPoint::POLICY_ROUTED);
        ExtPoint::register('oauth.driver', 'github', 'GithubDriver');
        ExtPoint::register('oauth.driver', 'wechat', 'WechatDriver');
        ExtPoint::register('oauth.driver', 'google', 'GoogleDriver');

        $this->assertEquals('GithubDriver', ExtPoint::get('oauth.driver', 'github'));
        $this->assertEquals('WechatDriver', ExtPoint::get('oauth.driver', 'wechat'));
        $this->assertEquals('GoogleDriver', ExtPoint::get('oauth.driver', 'google'));
        $this->assertNull(ExtPoint::get('oauth.driver', 'facebook'));
    }

    // ============================================================
    // resolve — 统一解析
    // ============================================================

    public function test_resolve_single_returns_active_only(): void
    {
        ExtPoint::definePoint('payment.driver', ExtPoint::POLICY_SINGLE);
        ExtPoint::register('payment.driver', 'wechat', 'WechatPay');
        ExtPoint::register('payment.driver', 'alipay', 'Alipay');

        $result = ExtPoint::resolve('payment.driver');

        $this->assertCount(1, $result);
        $this->assertEquals('WechatPay', $result['wechat']['concrete']);
    }

    public function test_resolve_multi_returns_all(): void
    {
        ExtPoint::definePoint('notification.channel', ExtPoint::POLICY_MULTI);
        ExtPoint::register('notification.channel', 'sms', 'SmsChannel');
        ExtPoint::register('notification.channel', 'email', 'EmailChannel');

        $result = ExtPoint::resolve('notification.channel');

        $this->assertCount(2, $result);
    }

    public function test_resolve_routed_returns_all(): void
    {
        ExtPoint::definePoint('oauth.driver', ExtPoint::POLICY_ROUTED);
        ExtPoint::register('oauth.driver', 'github', 'GithubDriver');
        ExtPoint::register('oauth.driver', 'wechat', 'WechatDriver');

        $result = ExtPoint::resolve('oauth.driver');

        // routed 返回全部，由调用方进一步按名称筛选
        $this->assertCount(2, $result);
    }

    public function test_resolve_override_strategy(): void
    {
        ExtPoint::definePoint('payment.driver', ExtPoint::POLICY_SINGLE);
        ExtPoint::register('payment.driver', 'wechat', 'WechatPay');
        ExtPoint::register('payment.driver', 'alipay', 'Alipay');

        // 显式用 multi 策略，应返回全部
        $result = ExtPoint::resolve('payment.driver', ExtPoint::POLICY_MULTI);

        $this->assertCount(2, $result);
    }

    // ============================================================
    // unregister / has / isEmpty / count / points
    // ============================================================

    public function test_unregister_removes_implementation(): void
    {
        ExtPoint::definePoint('test.remove', ExtPoint::POLICY_MULTI);
        ExtPoint::register('test.remove', 'a', 'ClassA');
        ExtPoint::register('test.remove', 'b', 'ClassB');

        ExtPoint::unregister('test.remove', 'a');

        $this->assertFalse(ExtPoint::has('test.remove', 'a'));
        $this->assertTrue(ExtPoint::has('test.remove', 'b'));
        $this->assertCount(1, ExtPoint::all('test.remove'));
    }

    public function test_has_returns_false_for_nonexistent(): void
    {
        $this->assertFalse(ExtPoint::has('nonexistent', 'key'));
    }

    public function test_has_returns_true_after_register(): void
    {
        ExtPoint::definePoint('test.has', ExtPoint::POLICY_MULTI);
        ExtPoint::register('test.has', 'key', 'Class');

        $this->assertTrue(ExtPoint::has('test.has', 'key'));
    }

    public function test_is_empty_returns_true_for_undefined_point(): void
    {
        $this->assertTrue(ExtPoint::isEmpty('undefined.point'));
    }

    public function test_count_returns_correct_number(): void
    {
        ExtPoint::definePoint('test.count', ExtPoint::POLICY_MULTI);
        ExtPoint::register('test.count', 'a', 'A');
        ExtPoint::register('test.count', 'b', 'B');
        ExtPoint::register('test.count', 'c', 'C');

        $this->assertEquals(3, ExtPoint::count('test.count'));
    }

    public function test_points_returns_all_registered_point_names(): void
    {
        ExtPoint::definePoint('test.a', ExtPoint::POLICY_MULTI);
        ExtPoint::definePoint('test.b', ExtPoint::POLICY_SINGLE);
        ExtPoint::register('test.a', 'x', 'X');
        ExtPoint::register('test.b', 'y', 'Y');

        $points = ExtPoint::points();

        $this->assertContains('test.a', $points);
        $this->assertContains('test.b', $points);
    }

    // ============================================================
    // 重置
    // ============================================================

    public function test_reset_clears_all_data(): void
    {
        ExtPoint::definePoint('test.reset', ExtPoint::POLICY_SINGLE);
        ExtPoint::register('test.reset', 'key', 'Class');

        ExtPoint::reset();

        $this->assertFalse(ExtPoint::defined('test.reset'));
        $this->assertTrue(ExtPoint::isEmpty('test.reset'));
        $this->assertEmpty(ExtPoint::points());
    }

    // ============================================================
    // 综合场景
    // ============================================================

    public function test_full_plugin_ecosystem_scenario(): void
    {
        // 模拟框架启动：定义标准扩展点
        ExtPoint::definePoint('payment.driver', ExtPoint::POLICY_SINGLE);
        ExtPoint::definePoint('oauth.driver', ExtPoint::POLICY_ROUTED);
        ExtPoint::definePoint('notification.channel', ExtPoint::POLICY_MULTI);

        // 模拟支付插件注册
        ExtPoint::register('payment.driver', 'wechat_pay', 'WechatPayDriver');
        ExtPoint::register('payment.driver', 'alipay', 'AlipayDriver');

        // 模拟 OAuth 插件注册
        ExtPoint::register('oauth.driver', 'github', 'GithubOAuth');
        ExtPoint::register('oauth.driver', 'wechat_open', 'WechatOpenOAuth');

        // 模拟通知插件注册
        ExtPoint::register('notification.channel', 'sms', 'SmsChannel');
        ExtPoint::register('notification.channel', 'email', 'EmailChannel');
        ExtPoint::register('notification.channel', 'wecom', 'WecomChannel');

        // === 验证 ===

        // 支付：微信应为活跃
        $payActive = ExtPoint::active('payment.driver');
        $this->assertEquals('wechat_pay', $payActive['name']);

        // OAuth：按名称查找
        $this->assertEquals('GithubOAuth', ExtPoint::get('oauth.driver', 'github'));
        $this->assertEquals('WechatOpenOAuth', ExtPoint::get('oauth.driver', 'wechat_open'));
        $this->assertNull(ExtPoint::get('oauth.driver', 'google'));

        // 通知：全部注册
        $notifChannels = ExtPoint::all('notification.channel');
        $this->assertCount(3, $notifChannels);

        // 切换支付到支付宝
        ExtPoint::setActive('payment.driver', 'alipay', true);
        $this->assertEquals('alipay', ExtPoint::active('payment.driver')['name']);
    }
}

// ============================================================
// 测试辅助接口与类
// ============================================================

interface TestableInterface
{
    public function execute(): string;
}

class TestStubDriver implements TestableInterface
{
    public function execute(): string
    {
        return 'stub';
    }
}

class NonConformingClass
{
    // 未实现 TestableInterface
}
