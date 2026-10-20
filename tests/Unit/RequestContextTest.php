<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\context\RequestContext;

/**
 * RequestContext 请求上下文单元测试
 */
class RequestContextTest extends TestCase
{
    protected function setUp(): void
    {
        RequestContext::clear();
    }

    protected function tearDown(): void
    {
        RequestContext::clear();
    }

    // ============================================================
    // 基本 set/get/has/remove
    // ============================================================

    public function test_set_and_get(): void
    {
        RequestContext::set('key1', 'value1');

        $this->assertEquals('value1', RequestContext::get('key1'));
    }

    public function test_new_request_generation_drops_previous_identity(): void
    {
        RequestContext::setRequestId('req-1');
        RequestContext::setUser((object) ['id' => 42]);
        RequestContext::setGuard('api');
        RequestContext::set('locale', 'en-US');

        // 非协程常驻进程（Swoole 常驻 / Workerman / 队列 worker）里所有请求共用一个桶：
        // 请求世代变化必须丢弃上一个请求写入的身份与上下文
        RequestContext::setRequestId('req-2');

        $this->assertNull(RequestContext::getUser());
        $this->assertNull(RequestContext::getUserId());
        $this->assertSame('admin', RequestContext::getGuard(), '应回落默认 guard');
        $this->assertNull(RequestContext::get('locale'));
        $this->assertSame('req-2', RequestContext::getRequestId());
    }

    public function test_same_request_id_keeps_context(): void
    {
        RequestContext::setRequestId('req-1');
        RequestContext::setUser((object) ['id' => 7]);

        // 同一世代（含同一 id 重复设置）不得丢弃上下文
        RequestContext::setRequestId('req-1');

        $this->assertSame(7, RequestContext::getUserId());
    }

    public function test_get_unknown_returns_default(): void
    {
        $this->assertNull(RequestContext::get('nonexistent'));
        $this->assertEquals('fallback', RequestContext::get('nonexistent', 'fallback'));
    }

    public function test_has(): void
    {
        RequestContext::set('exists', true);

        $this->assertTrue(RequestContext::has('exists'));
        $this->assertFalse(RequestContext::has('nonexistent'));
    }

    public function test_remove(): void
    {
        RequestContext::set('key', 'value');
        RequestContext::remove('key');

        $this->assertFalse(RequestContext::has('key'));
        $this->assertNull(RequestContext::get('key'));
    }

    public function test_set_many(): void
    {
        RequestContext::setMany(['a' => 1, 'b' => 2]);
        RequestContext::setMany(['c' => 3]);

        $this->assertEquals(1, RequestContext::get('a'));
        $this->assertEquals(2, RequestContext::get('b'));
        $this->assertEquals(3, RequestContext::get('c'));
    }

    public function test_set_many_overwrites_existing(): void
    {
        RequestContext::set('a', 1);
        RequestContext::setMany(['a' => 100, 'b' => 2]);

        $this->assertEquals(100, RequestContext::get('a'));
        $this->assertEquals(2, RequestContext::get('b'));
    }

    // ============================================================
    // 便捷方法
    // ============================================================

    public function test_request_id(): void
    {
        $this->assertEquals('unknown', RequestContext::getRequestId());

        RequestContext::setRequestId('req-abc-123');
        $this->assertEquals('req-abc-123', RequestContext::getRequestId());
    }

    public function test_user(): void
    {
        $this->assertNull(RequestContext::getUser());

        $user = new class { public int $id = 42; };
        RequestContext::setUser($user);

        $this->assertSame($user, RequestContext::getUser());
    }

    public function test_user_id_from_user_object_id(): void
    {
        $user = new class { public int $id = 42; };
        RequestContext::setUser($user);

        $this->assertEquals(42, RequestContext::getUserId());
    }

    public function test_user_id_from_admin_id_fallback(): void
    {
        $user = new class { public int $admin_id = 100; };
        RequestContext::setUser($user);

        $this->assertEquals(100, RequestContext::getUserId());
    }

    public function test_user_id_null_when_no_user(): void
    {
        $this->assertNull(RequestContext::getUserId());
    }

    public function test_tenant_id(): void
    {
        $this->assertNull(RequestContext::getTenantId());

        RequestContext::setTenantId('tenant-001');
        $this->assertEquals('tenant-001', RequestContext::getTenantId());
    }

    public function test_guard(): void
    {
        $this->assertEquals('admin', RequestContext::getGuard()); // default

        RequestContext::setGuard('api');
        $this->assertEquals('api', RequestContext::getGuard());
    }

    public function test_locale(): void
    {
        $this->assertEquals('zh-CN', RequestContext::getLocale()); // default

        RequestContext::setLocale('en-US');
        $this->assertEquals('en-US', RequestContext::getLocale());
    }

    // ============================================================
    // all / logContext
    // ============================================================

    public function test_all_returns_copy(): void
    {
        RequestContext::set('a', 1);
        RequestContext::set('b', 2);

        $all = RequestContext::all();
        $all['a'] = 999; // should not affect internal storage

        $this->assertEquals(1, RequestContext::get('a'));
    }

    public function test_log_context(): void
    {
        RequestContext::setRequestId('rid-001');
        RequestContext::setGuard('admin');

        $user = new class { public int $id = 99; };
        RequestContext::setUser($user);

        $ctx = RequestContext::logContext();

        $this->assertEquals('rid-001', $ctx['request_id']);
        $this->assertEquals('admin', $ctx['guard']);
        $this->assertEquals(99, $ctx['user_id']);
    }

    public function test_log_context_without_user(): void
    {
        RequestContext::setRequestId('rid-002');

        $ctx = RequestContext::logContext();

        $this->assertEquals('rid-002', $ctx['request_id']);
        $this->assertArrayNotHasKey('user_id', $ctx);
    }

    // ============================================================
    // clear
    // ============================================================

    public function test_clear_removes_all_data(): void
    {
        RequestContext::setRequestId('rid-001');
        RequestContext::setUser(new class { public int $id = 1; });
        RequestContext::set('custom', 'val');

        RequestContext::clear();

        $this->assertEquals('unknown', RequestContext::getRequestId());
        $this->assertNull(RequestContext::getUser());
        $this->assertNull(RequestContext::get('custom'));
        $this->assertEquals([], RequestContext::all());
    }
}
