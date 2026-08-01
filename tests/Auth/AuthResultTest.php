<?php

/**
 * Auth Result Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Auth;

use Phlix\Shared\Auth\AuthResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Auth\AuthResult
 */
final class AuthResultTest extends TestCase
{
    public function testSuccessResultIsSuccess(): void
    {
        $result = new AuthResult(
            success: true,
            userId: 'user-123',
            externalId: 'ext-456',
            attributes: ['email' => 'test@example.com'],
        );

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
    }

    public function testFailureResultIsFailure(): void
    {
        $result = new AuthResult(
            success: false,
            error: 'token_expired',
        );

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isFailure());
    }

    public function testGetEmailReturnsEmailWhenPresent(): void
    {
        $result = new AuthResult(
            success: true,
            userId: 'user-123',
            attributes: ['email' => 'test@example.com', 'name' => 'Test User'],
        );

        $this->assertSame('test@example.com', $result->getEmail());
    }

    public function testGetEmailReturnsNullWhenMissing(): void
    {
        $result = new AuthResult(
            success: true,
            userId: 'user-123',
            attributes: [],
        );

        $this->assertNull($result->getEmail());
    }

    public function testGetEmailReturnsNullWhenNotString(): void
    {
        $result = new AuthResult(
            success: true,
            userId: 'user-123',
            attributes: ['email' => 12345],
        );

        $this->assertNull($result->getEmail());
    }

    public function testGetDisplayNameReturnsNameWhenPresent(): void
    {
        $result = new AuthResult(
            success: true,
            userId: 'user-123',
            attributes: ['name' => 'Test User'],
        );

        $this->assertSame('Test User', $result->getDisplayName());
    }

    public function testGetDisplayNameReturnsNullWhenMissing(): void
    {
        $result = new AuthResult(
            success: true,
            userId: 'user-123',
            attributes: [],
        );

        $this->assertNull($result->getDisplayName());
    }

    public function testGetDisplayNameReturnsNullWhenNotString(): void
    {
        $result = new AuthResult(
            success: true,
            userId: 'user-123',
            attributes: ['name' => ['not', 'a', 'string']],
        );

        $this->assertNull($result->getDisplayName());
    }

    public function testGetAvatarUrlReturnsUrlWhenPresent(): void
    {
        $result = new AuthResult(
            success: true,
            userId: 'user-123',
            attributes: ['avatarUrl' => 'https://example.com/avatar.png'],
        );

        $this->assertSame('https://example.com/avatar.png', $result->getAvatarUrl());
    }

    public function testGetAvatarUrlReturnsNullWhenMissing(): void
    {
        $result = new AuthResult(
            success: true,
            userId: 'user-123',
            attributes: [],
        );

        $this->assertNull($result->getAvatarUrl());
    }

    public function testGetAvatarUrlReturnsNullWhenNotString(): void
    {
        $result = new AuthResult(
            success: true,
            userId: 'user-123',
            attributes: ['avatarUrl' => ['not', 'a', 'url']],
        );

        $this->assertNull($result->getAvatarUrl());
    }

    public function testConstructorDefaults(): void
    {
        $result = new AuthResult(success: true);

        $this->assertNull($result->userId);
        $this->assertNull($result->externalId);
        $this->assertNull($result->error);
        $this->assertSame([], $result->attributes);
    }

    public function testConstructorWithAllParameters(): void
    {
        $result = new AuthResult(
            success: true,
            userId: 'user-123',
            externalId: 'ext-456',
            error: null,
            attributes: ['email' => 'test@example.com', 'name' => 'Test'],
        );

        $this->assertSame('user-123', $result->userId);
        $this->assertSame('ext-456', $result->externalId);
        $this->assertSame('test@example.com', $result->getEmail());
        $this->assertSame('Test', $result->getDisplayName());
    }

    public function testReadonlyProperties(): void
    {
        $result = new AuthResult(success: true, userId: 'user-123');

        // Ensure properties are readable
        $this->assertSame(true, $result->success);
        $this->assertSame('user-123', $result->userId);
    }
}
