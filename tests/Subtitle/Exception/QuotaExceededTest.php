<?php

/**
 * Quota Exceeded Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Subtitle\Exception;

use DateTimeImmutable;
use Phlix\Shared\Subtitle\Exception\QuotaExceeded;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Phlix\Shared\Subtitle\Exception\QuotaExceeded
 */
final class QuotaExceededTest extends TestCase
{
    public function test_is_a_runtime_exception(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new QuotaExceeded());
    }

    public function test_default_context_is_null(): void
    {
        $e = new QuotaExceeded();

        $this->assertSame('Subtitle provider download quota exceeded.', $e->getMessage());
        $this->assertNull($e->getDownloadsRemaining());
        $this->assertNull($e->getResetTimeUtc());
    }

    public function test_carries_string_context(): void
    {
        $e = new QuotaExceeded(
            'Out of downloads.',
            downloadsRemaining: 0,
            resetTimeUtc: '2026-07-22T00:00:00+00:00',
        );

        $this->assertSame('Out of downloads.', $e->getMessage());
        $this->assertSame(0, $e->getDownloadsRemaining());
        $this->assertSame('2026-07-22T00:00:00+00:00', $e->getResetTimeUtc());
    }

    public function test_normalises_datetimeimmutable_reset_time_to_iso8601(): void
    {
        $reset = new DateTimeImmutable('2026-07-22T06:30:00+00:00');
        $e = new QuotaExceeded('x', resetTimeUtc: $reset);

        $this->assertSame('2026-07-22T06:30:00+00:00', $e->getResetTimeUtc());
    }

    public function test_preserves_code_and_previous(): void
    {
        $previous = new RuntimeException('root cause');
        $e = new QuotaExceeded('x', code: 429, previous: $previous);

        $this->assertSame(429, $e->getCode());
        $this->assertSame($previous, $e->getPrevious());
    }
}
