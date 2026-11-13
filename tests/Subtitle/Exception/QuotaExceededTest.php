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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(\Phlix\Shared\Subtitle\Exception\QuotaExceeded::class)]
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

    public function test_normalises_non_utc_datetimeimmutable_reset_time_to_utc(): void
    {
        $reset = new DateTimeImmutable('2026-07-22T08:30:00+02:00');
        $e = new QuotaExceeded('x', resetTimeUtc: $reset);

        $this->assertSame('2026-07-22T06:30:00+00:00', $e->getResetTimeUtc());
    }

    public function test_normalises_system_timezone_datetimeimmutable_reset_time_to_utc(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

        try {
            // Same instant, expressed in a +02:00 local zone by the default TZ.
            $reset = new DateTimeImmutable('2026-07-22T08:30:00');
            $e = new QuotaExceeded('x', resetTimeUtc: $reset);

            $this->assertSame('2026-07-22T06:30:00+00:00', $e->getResetTimeUtc());
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function test_preserves_code_and_previous(): void
    {
        $previous = new RuntimeException('root cause');
        $e = new QuotaExceeded('x', code: 429, previous: $previous);

        $this->assertSame(429, $e->getCode());
        $this->assertSame($previous, $e->getPrevious());
    }

    public function test_normalises_provider_string_reset_time_to_utc_atom(): void
    {
        // OpenSubtitles reports fractional-second `Z` timestamps.
        $this->assertSame(
            '2022-04-08T13:03:16+00:00',
            (new QuotaExceeded('x', resetTimeUtc: '2022-04-08T13:03:16.000Z'))->getResetTimeUtc(),
        );
        $this->assertSame(
            '2026-07-22T06:30:00+00:00',
            (new QuotaExceeded('x', resetTimeUtc: '2026-07-22T08:30:00+02:00'))->getResetTimeUtc(),
        );
    }

    public function test_z_and_plus_zero_inputs_emit_the_same_canonical_form(): void
    {
        $z = (new QuotaExceeded('x', resetTimeUtc: '2026-07-22T06:30:00Z'))->getResetTimeUtc();
        $off = (new QuotaExceeded('x', resetTimeUtc: '2026-07-22T06:30:00+00:00'))->getResetTimeUtc();

        $this->assertSame('2026-07-22T06:30:00+00:00', $z);
        $this->assertSame($z, $off);
        $this->assertSame($z, (new QuotaExceeded('x', resetTimeUtc: $z))->getResetTimeUtc());
    }

    public function test_offsetless_string_reset_time_is_read_as_utc(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

        try {
            $e = new QuotaExceeded('x', resetTimeUtc: '2026-07-22 00:00:00');

            $this->assertSame('2026-07-22T00:00:00+00:00', $e->getResetTimeUtc());
        } finally {
            date_default_timezone_set($previous);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidResetStrings(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'relative' => ['tomorrow'],
            'military zone letter' => ['a'],
            'impossible date' => ['2026-02-30T00:00:00Z'],
            'impossible hour' => ['2026-07-22T25:00:00Z'],
            'trailing garbage' => ['2026-07-22 garbage'],
            'date-prefixed relative' => ['2026-07-13 next monday'],
            'date-prefixed relative offset' => ['2026-07-13 +1 year'],
            'oversized junk' => [str_repeat('9', 200)],
        ];
    }

    #[DataProvider('invalidResetStrings')]
    public function test_invalid_string_reset_time_degrades_to_null_without_masking_quota(string $value): void
    {
        $e = new QuotaExceeded('Out of downloads.', downloadsRemaining: 0, resetTimeUtc: $value);

        $this->assertNull($e->getResetTimeUtc());
        $this->assertSame('Out of downloads.', $e->getMessage());
        $this->assertSame(0, $e->getDownloadsRemaining());
    }
}
