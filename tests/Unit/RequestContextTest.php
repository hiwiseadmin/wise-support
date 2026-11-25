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

    public function test_user_id_set_without_user_object(): void
    {
        RequestContext::setUserId(7);

        $this->assertEquals(7, RequestContext::getUserId());
        $this->assertNull(RequestContext::getUser());
    }

    public function test_set_user_overrides_user_id_only_value(): void
    {
        RequestContext::setUserId(7);
        RequestContext::setUser(new class { public int $id = 99; });

        $this->assertEquals(99, RequestContext::getUserId());
    }

    public function test_set_user_null_clears_user_id(): void
    {
        RequestContext::setUser(new class { public int $id = 5; });
        RequestContext::setUser(null);

        $this->assertNull(RequestContext::getUserId());
        $this->assertNull(RequestContext::getUser());
    }

    public function test_clear_removes_user_id(): void
    {
        RequestContext::setUserId(11);
        RequestContext::clear();

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
        $this->assertEquals('admin', RequestContext::getGuard());

        RequestContext::setGuard('api');
        $this->assertEquals('api', RequestContext::getGuard());
    }

    public function test_locale(): void
    {
        $this->assertEquals('zh-CN', RequestContext::getLocale());

        RequestContext::setLocale('en-US');
        $this->assertEquals('en-US', RequestContext::getLocale());
    }


    public function test_all_returns_copy(): void
    {
        RequestContext::set('a', 1);
        RequestContext::set('b', 2);

        $all = RequestContext::all();
        $all['a'] = 999;

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
