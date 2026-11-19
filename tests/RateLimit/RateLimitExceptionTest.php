<?php

/**
 * RateLimitException Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\RateLimit;

use Phlix\Shared\RateLimit\RateLimitException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Characterization of the rate-limit rejection exception, lifted from the
 * two repo twins (v0.57 leg 0.9, M-11). The shared class is the hub
 * SUPERSET: both repos carry the identical constructor and
 * `retryAfterSeconds()` delegation; only the hub grew `MESSAGE_PREFIX` and
 * the `retryAfterMessage()` sentence formatter (wire copy the SPA pins).
 * The server copy therefore contributes its arm-naming precision and the
 * hub copy its message-suite; this file is the union.
 */
#[CoversClass(RateLimitException::class)]
final class RateLimitExceptionTest extends TestCase
{
    public function testIsARuntimeExceptionWithTheCanonicalMessage(): void
    {
        $e = new RateLimitException(resetAt: 2_000, remaining: 3);

        self::assertInstanceOf(RuntimeException::class, $e);
        self::assertSame(
            'Too many authentication attempts. Please try again later.',
            $e->getMessage(),
        );
        self::assertSame(2_000, $e->resetAt);
        self::assertSame(3, $e->remaining);
    }

    public function testRemainingDefaultsToZero(): void
    {
        $e = new RateLimitException(resetAt: 1_160);

        self::assertSame(0, $e->remaining);
    }

    public function testRetryAfterSecondsReturnsExactSecondsWhenNowSupplied(): void
    {
        $e = new RateLimitException(resetAt: 1_160, remaining: 0);

        self::assertSame(60, $e->retryAfterSeconds(1_100));
        self::assertSame(1, $e->retryAfterSeconds(1_159));
    }

    public function testRetryAfterSecondsIsZeroAtTheResetInstant(): void
    {
        $e = new RateLimitException(resetAt: 1_160, remaining: 0);

        self::assertSame(0, $e->retryAfterSeconds(1_160));
    }

    public function testRetryAfterSecondsFloorsAtZeroWhenResetIsInThePast(): void
    {
        // resetAt already elapsed relative to the supplied clock → never negative.
        $e = new RateLimitException(resetAt: 1_000, remaining: 0);

        self::assertSame(
            0,
            $e->retryAfterSeconds(5_000),
            'retryAfterSeconds must never return a negative Retry-After.',
        );
    }

    public function testRetryAfterSecondsDefaultsToCurrentTimeWhenNowOmitted(): void
    {
        // With no explicit clock the method uses time(); a window 50s out must
        // report ~50s. Allow a 1s slack for a clock tick between the two reads.
        $e = new RateLimitException(resetAt: time() + 50, remaining: 0);

        $seconds = $e->retryAfterSeconds();

        self::assertGreaterThanOrEqual(49, $seconds);
        self::assertLessThanOrEqual(50, $seconds);
    }

    public function testRetryAfterSecondsDefaultingToNowFloorsAtZeroForPastWindow(): void
    {
        // A window already in the past, using the default time() clock, floors at 0.
        $e = new RateLimitException(resetAt: time() - 120, remaining: 0);

        self::assertSame(0, $e->retryAfterSeconds());
    }

    public function testRetryAfterMessageSpeaksThePluralWindow(): void
    {
        self::assertSame(
            'Too many attempts — try again in 45 seconds.',
            RateLimitException::retryAfterMessage(45),
        );
    }

    public function testRetryAfterMessageUsesTheSingularUnitForOneSecond(): void
    {
        // 1 is a legitimate retryAfter() value (the window resets one second
        // out); the sentence must not read "1 seconds".
        self::assertSame(
            'Too many attempts — try again in 1 second.',
            RateLimitException::retryAfterMessage(1),
        );
    }

    public function testRetryAfterMessageNeverSaysZeroSeconds(): void
    {
        // The floored arm: a window that already rolled reads as 'now',
        // never as "0 seconds".
        self::assertSame(
            'Too many attempts — you may try again now.',
            RateLimitException::retryAfterMessage(0),
        );
        self::assertSame(
            'Too many attempts — you may try again now.',
            RateLimitException::retryAfterMessage(-5),
        );
    }

    public function testRetryAfterMessageStaysInSecondsBelowAMinute(): void
    {
        self::assertSame(
            'Too many attempts — try again in 59 seconds.',
            RateLimitException::retryAfterMessage(59),
        );
    }

    public function testRetryAfterMessageSpeaksMinutesRoundedUp(): void
    {
        // A 900 s lockout window must read as minutes, rounded UP — never an
        // unlock promise that arrives early.
        self::assertSame(
            'Too many attempts — try again in 1 minute.',
            RateLimitException::retryAfterMessage(60),
        );
        self::assertSame(
            'Too many attempts — try again in 2 minutes.',
            RateLimitException::retryAfterMessage(61),
        );
        self::assertSame(
            'Too many attempts — try again in 15 minutes.',
            RateLimitException::retryAfterMessage(897),
        );
        self::assertSame(
            'Too many attempts — try again in 15 minutes.',
            RateLimitException::retryAfterMessage(900),
        );
    }

    public function testEveryArmSharesTheClientRecognisedPrefix(): void
    {
        // Consumer password forms let a sentence starting with this prefix
        // win over their generic copy; the literal is wire copy and pinned
        // byte-exact (em-dash included).
        self::assertSame('Too many attempts — ', RateLimitException::MESSAGE_PREFIX);
        foreach ([-5, 0, 1, 45, 60, 61, 900, 3600] as $seconds) {
            self::assertStringStartsWith(
                RateLimitException::MESSAGE_PREFIX,
                RateLimitException::retryAfterMessage($seconds),
            );
        }
    }
}
