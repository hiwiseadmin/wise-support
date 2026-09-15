<?php
declare(strict_types=1);

namespace wise\support\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\support\event\WiseEvents;

/**
 * WiseEvents 框架标准事件常量单元测试
 */
class WiseEventsTest extends TestCase
{
    public function test_auth_event_constants(): void
    {
        $this->assertSame('auth.login.success', WiseEvents::AUTH_LOGIN_SUCCESS);
        $this->assertSame('auth.login.fail', WiseEvents::AUTH_LOGIN_FAIL);
        $this->assertSame('auth.logout', WiseEvents::AUTH_LOGOUT);
        $this->assertSame('auth.session.expire', WiseEvents::AUTH_SESSION_EXPIRE);
        $this->assertSame('auth.locked', WiseEvents::AUTH_LOCKED);
    }

    public function test_user_event_constants(): void
    {
        $this->assertSame('user.created', WiseEvents::USER_CREATED);
        $this->assertSame('user.updated', WiseEvents::USER_UPDATED);
        $this->assertSame('user.deleted', WiseEvents::USER_DELETED);
        $this->assertSame('user.password.changed', WiseEvents::USER_PASSWORD_CHANGED);
    }

    public function test_jwt_event_constants(): void
    {
        $this->assertSame('jwt.issuing', WiseEvents::JWT_ISSUING);
        $this->assertSame('jwt.issued', WiseEvents::JWT_ISSUED);
        $this->assertSame('jwt.parsing', WiseEvents::JWT_PARSING);
        $this->assertSame('jwt.parsed', WiseEvents::JWT_PARSED);
        $this->assertSame('jwt.revoked', WiseEvents::JWT_REVOKED);
        $this->assertSame('jwt.invalid', WiseEvents::JWT_INVALID);
        $this->assertSame('jwt.global_revoke', WiseEvents::JWT_GLOBAL_REVOKE);
    }

    public function test_system_event_constants(): void
    {
        $this->assertSame('system.booted', WiseEvents::APP_BOOTED);
        $this->assertSame('system.shutdown', WiseEvents::APP_SHUTDOWN);
    }

    public function test_config_event_constants(): void
    {
        $this->assertSame('config.changed', WiseEvents::CONFIG_CHANGED);
        $this->assertSame('config.saving', WiseEvents::CONFIG_SAVING);
        $this->assertSame('config.saved', WiseEvents::CONFIG_SAVED);
        $this->assertSame('config.deleting', WiseEvents::CONFIG_DELETING);
        $this->assertSame('config.reverted', WiseEvents::CONFIG_REVERTED);
    }

    public function test_all_returns_constant_values(): void
    {
        $all = WiseEvents::all();

        $this->assertIsArray($all);
        $this->assertNotEmpty($all);
        // 所有事件名应为小写点号分隔格式
        $this->assertContains('auth.login.success', $all);
        $this->assertContains('user.created', $all);
        $this->assertContains('ws.connection.open', $all);
    }

    public function test_all_does_not_contain_non_string_values(): void
    {
        $all = WiseEvents::all();

        foreach ($all as $value) {
            $this->assertIsString($value);
        }
    }

    public function test_ws_event_constants(): void
    {
        $this->assertSame('ws.connection.open', WiseEvents::WS_CONNECTION_OPEN);
        $this->assertSame('ws.connection.close', WiseEvents::WS_CONNECTION_CLOSE);
        $this->assertSame('ws.heartbeat.timeout', WiseEvents::WS_HEARTBEAT_TIMEOUT);
    }

    public function test_notification_event_constants(): void
    {
        $this->assertSame('notification.sending', WiseEvents::NOTIFICATION_SENDING);
        $this->assertSame('notification.sent', WiseEvents::NOTIFICATION_SENT);
        $this->assertSame('notification.failed', WiseEvents::NOTIFICATION_FAILED);
    }

    public function test_ext_event_constants(): void
    {
        $this->assertSame('ext.registered', WiseEvents::EXT_REGISTERED);
        $this->assertSame('ext.unregistered', WiseEvents::EXT_UNREGISTERED);
        $this->assertSame('ext.active_switched', WiseEvents::EXT_ACTIVE_SWITCHED);
    }
}
