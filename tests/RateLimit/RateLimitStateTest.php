<?php

/**
 * RateLimitState Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\RateLimit;

use Phlix\Shared\RateLimit\RateLimitState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Characterization of the immutable bucket-snapshot value object, lifted
 * from the byte-identical server and hub twins (v0.57 leg 0.9, M-11) — the
 * only differences on disk were namespace and header prose. The case set is
 * the union of both repo suites (the server pins every arm with distinct
 * named cases; the hub adds the property-exposure and empty-window walks).
 */
#[CoversClass(RateLimitState::class)]
final class RateLimitStateTest extends TestCase
{
    public function testExposesConstructorValuesAsReadonlyFields(): void
    {
        $state = new RateLimitState(
            count: 3,
            remaining: 2,
            resetAt: 1_500,
            limited: false,
            limit: 5,
        );

        self::assertSame(3, $state->count);
        self::assertSame(2, $state->remaining);
        self::assertSame(1_500, $state->resetAt);
        self::assertFalse($state->limited);
        self::assertSame(5, $state->limit);
    }

    public function testConstructorWithLimitedState(): void
    {
        $state = new RateLimitState(
            count: 100,
            remaining: 0,
            resetAt: 1_700_000_000,
            limited: true,
            limit: 100,
        );

        self::assertSame(100, $state->count);
        self::assertSame(0, $state->remaining);
        self::assertTrue($state->limited);
    }

    public function testRetryAfterReturnsSecondsUntilReset(): void
    {
        $state = new RateLimitState(count: 5, remaining: 0, resetAt: 1_160, limited: true, limit: 5);

        self::assertSame(60, $state->retryAfter(1_100));
    }

    public function testRetryAfterIsZeroAtTheResetInstant(): void
    {
        $state = new RateLimitState(count: 5, remaining: 0, resetAt: 1_160, limited: true, limit: 5);

        self::assertSame(0, $state->retryAfter(1_160));
    }

    public function testRetryAfterFloorsAtZeroPastReset(): void
    {
        $state = new RateLimitState(count: 5, remaining: 0, resetAt: 1_160, limited: true, limit: 5);

        self::assertSame(0, $state->retryAfter(5_000), 'retryAfter must never be negative.');
    }

    public function testRetryAfterOnEmptyStateIsZero(): void
    {
        // resetAt = 0 mirrors the "no active window" state produced by peek().
        $state = new RateLimitState(count: 0, remaining: 5, resetAt: 0, limited: false, limit: 5);

        self::assertSame(0, $state->retryAfter(1_000));
    }
}
