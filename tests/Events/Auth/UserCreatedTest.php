<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Events\Auth;

use Phlix\Shared\Events\AbstractEvent;
use Phlix\Shared\Events\Auth\UserCreated;
use Phlix\Shared\Events\Auth\UserLoggedIn;
use Phlix\Shared\Events\Auth\UserLoggedOut;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Events\Auth\UserCreated
 * @covers \Phlix\Shared\Events\Auth\UserLoggedIn
 * @covers \Phlix\Shared\Events\Auth\UserLoggedOut
 */
final class UserCreatedTest extends TestCase
{
    public function test_user_created_populates_readonly_props(): void
    {
        $event = new UserCreated('u', 'alice', 'alice@example.com');

        $this->assertInstanceOf(AbstractEvent::class, $event);
        $this->assertSame('u', $event->userId);
        $this->assertSame('alice', $event->username);
        $this->assertSame('alice@example.com', $event->email);
        $this->assertGreaterThan(0, $event->timestamp);
    }

    public function test_user_logged_in_props(): void
    {
        $event = new UserLoggedIn('u', 's', '10.0.0.1', 'Mozilla/5.0');
        $this->assertSame('u', $event->userId);
        $this->assertSame('s', $event->sessionId);
        $this->assertSame('10.0.0.1', $event->ipAddress);
        $this->assertSame('Mozilla/5.0', $event->userAgent);
    }

    public function test_user_logged_out_reason_constants_distinct(): void
    {
        $this->assertSame('explicit', UserLoggedOut::REASON_EXPLICIT);
        $this->assertSame('expired', UserLoggedOut::REASON_EXPIRED);
        $this->assertSame('revoked', UserLoggedOut::REASON_REVOKED);
    }

    public function test_user_logged_out_props(): void
    {
        $event = new UserLoggedOut('u', 's', UserLoggedOut::REASON_REVOKED);
        $this->assertSame('u', $event->userId);
        $this->assertSame('s', $event->sessionId);
        $this->assertSame('revoked', $event->reason);
    }
}
