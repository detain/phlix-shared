<?php

/**
 * User Info Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Auth;

use Phlix\Shared\Auth\UserInfo;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Auth\UserInfo
 */
final class UserInfoTest extends TestCase
{
    public function testConstructorWithAllParameters(): void
    {
        $info = new UserInfo(
            externalId: 'https://accounts.google.com/12345',
            email: 'test@example.com',
            displayName: 'Test User',
            avatarUrl: 'https://example.com/avatar.png',
            rawAttributes: ['sub' => '12345', 'email_verified' => true],
        );

        $this->assertSame('https://accounts.google.com/12345', $info->externalId);
        $this->assertSame('test@example.com', $info->email);
        $this->assertSame('Test User', $info->displayName);
        $this->assertSame('https://example.com/avatar.png', $info->avatarUrl);
        $this->assertSame(['sub' => '12345', 'email_verified' => true], $info->rawAttributes);
    }

    public function testConstructorWithMinimalParameters(): void
    {
        $info = new UserInfo(externalId: 'https://accounts.google.com/12345');

        $this->assertSame('https://accounts.google.com/12345', $info->externalId);
        $this->assertNull($info->email);
        $this->assertNull($info->displayName);
        $this->assertNull($info->avatarUrl);
        $this->assertSame([], $info->rawAttributes);
    }

    public function testHasEmailReturnsTrueWhenEmailPresent(): void
    {
        $info = new UserInfo(externalId: 'id', email: 'test@example.com');

        $this->assertTrue($info->hasEmail());
    }

    public function testHasEmailReturnsFalseWhenEmailNull(): void
    {
        $info = new UserInfo(externalId: 'id');

        $this->assertFalse($info->hasEmail());
    }

    public function testHasDisplayNameReturnsTrueWhenDisplayNamePresent(): void
    {
        $info = new UserInfo(externalId: 'id', displayName: 'Test User');

        $this->assertTrue($info->hasDisplayName());
    }

    public function testHasDisplayNameReturnsFalseWhenDisplayNameNull(): void
    {
        $info = new UserInfo(externalId: 'id');

        $this->assertFalse($info->hasDisplayName());
    }

    public function testHasAvatarUrlReturnsTrueWhenAvatarUrlPresent(): void
    {
        $info = new UserInfo(externalId: 'id', avatarUrl: 'https://example.com/avatar.png');

        $this->assertTrue($info->hasAvatarUrl());
    }

    public function testHasAvatarUrlReturnsFalseWhenAvatarUrlNull(): void
    {
        $info = new UserInfo(externalId: 'id');

        $this->assertFalse($info->hasAvatarUrl());
    }

    public function testGetClaimReturnsValueWhenPresent(): void
    {
        $info = new UserInfo(
            externalId: 'id',
            rawAttributes: ['sub' => '12345', 'email_verified' => true],
        );

        $this->assertSame('12345', $info->getClaim('sub'));
        $this->assertTrue($info->getClaim('email_verified'));
    }

    public function testGetClaimReturnsDefaultWhenMissing(): void
    {
        $info = new UserInfo(externalId: 'id', rawAttributes: []);

        $this->assertNull($info->getClaim('sub'));
        $this->assertSame('default-value', $info->getClaim('sub', 'default-value'));
    }

    public function testGetClaimWithNullDefault(): void
    {
        $info = new UserInfo(externalId: 'id', rawAttributes: ['key' => 'value']);

        $this->assertNull($info->getClaim('nonexistent', null));
    }

    public function testReadonlyProperties(): void
    {
        $info = new UserInfo(
            externalId: 'id',
            email: 'test@example.com',
            displayName: 'Test',
            avatarUrl: 'https://example.com/avatar.png',
            rawAttributes: ['key' => 'value'],
        );

        // Ensure properties are readable
        $this->assertSame('id', $info->externalId);
        $this->assertSame('test@example.com', $info->email);
        $this->assertSame('Test', $info->displayName);
        $this->assertSame('https://example.com/avatar.png', $info->avatarUrl);
        $this->assertSame(['key' => 'value'], $info->rawAttributes);
    }
}
