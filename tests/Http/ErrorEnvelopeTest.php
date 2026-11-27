<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Http;

use Phlix\Shared\Http\ErrorEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * ErrorEnvelope Test.
 *
 * Characterization pins for v0.57 leg 0.10 M-16, lifted from the estate
 * twins' emit law: phlix-server `src/Server/Http/Response.php` `error()`
 * (:212) / `rateLimitedBody()` (:261) and phlix-hub `src/Http/Response.php`
 * `error()` (:230) / `rateLimitedBody()` (:269) — byte-identical bodies
 * (A-04). The hub 503 maintenance frame (`Application.php:294-303`) rides
 * the same `build()` law with host copy in `error` and the HUB-49
 * disambiguation `detail` merged additive.
 *
 * Adoption truth (S-shared-03): these pins characterize the LAW the twins
 * emit, not host consumption of the kernel — neither host imports
 * `Phlix\Shared\Http\ErrorEnvelope` yet (see the class' INERT adoption
 * header); the twins stay the shipped enforcement until the consumer wave.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
#[CoversClass(ErrorEnvelope::class)]
final class ErrorEnvelopeTest extends TestCase
{
    public function testBuildLeadsWithErrorAndCodeInLiteralOrder(): void
    {
        self::assertSame(
            ['error' => 'Library not found', 'code' => 'library.not_found'],
            ErrorEnvelope::build('Library not found', 'library.not_found'),
        );

        $keys = array_keys(ErrorEnvelope::build('m', 'c'));
        self::assertSame(['error', 'code'], $keys);
    }

    public function testExtraMergesAdditivelyAfterErrorAndCode(): void
    {
        self::assertSame(
            [
                'error' => 'Too many requests',
                'code' => 'rate_limited',
                'message' => 'Too many attempts — try again in 1 second.',
            ],
            ErrorEnvelope::build('Too many requests', 'rate_limited', [
                'message' => 'Too many attempts — try again in 1 second.',
            ]),
        );
    }

    public function testLeftBiasedUnionKeepsCanonicalKeys(): void
    {
        // The `+` union law: a caller-supplied `error`/`code` in $extra can
        // never overwrite the canonical pair (both hosts' `error()` bodies).
        $body = ErrorEnvelope::build('canonical', 'c', ['error' => 'x', 'code' => 'y', 'detail' => 'd']);

        self::assertSame('canonical', $body['error']);
        self::assertSame('c', $body['code']);
        self::assertSame('d', $body['detail']);
    }

    public function testRateLimitedBodyIsTheSharedLiteralPair(): void
    {
        self::assertSame(
            ['error' => 'Too Many Requests', 'code' => 'rate_limited'],
            ErrorEnvelope::rateLimited(),
        );
        self::assertSame('rate_limited', ErrorEnvelope::RATE_LIMITED_CODE);
    }

    public function testRateLimitedAcceptsAdditiveExtra(): void
    {
        $body = ErrorEnvelope::rateLimited(['message' => 'Too many attempts — you may try again now.']);

        self::assertSame('Too Many Requests', $body['error']);
        self::assertSame('rate_limited', $body['code']);
        self::assertSame('Too many attempts — you may try again now.', $body['message']);
    }

    public function testRetryAfterHeaderIsPlainDeltaSecondsString(): void
    {
        // RFC 9110 §10.2.3 delta-seconds; the layers above already clamp
        // non-negative (disclosed posture: the formatter itself is verbatim
        // (string) casting on both hosts).
        self::assertSame('0', ErrorEnvelope::retryAfterHeader(0));
        self::assertSame('60', ErrorEnvelope::retryAfterHeader(60));
        self::assertSame('-5', ErrorEnvelope::retryAfterHeader(-5));
    }
}
