<?php

/**
 * Quota Exceeded.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Subtitle\Exception;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use RuntimeException;
use Throwable;

/**
 * Thrown by {@see \Phlix\Shared\Subtitle\SubtitleSourceInterface::download()}
 * when a provider's (usually daily) subtitle download quota is exhausted.
 *
 * Downloads — not searches — are the metered operation, so this signals the
 * host to stop attempting downloads against this source and to surface/persist
 * the quota state to the operator. It optionally carries the remaining
 * allowance and the reset time when the provider reports them.
 *
 * The reset time is always exposed as a normalised ISO-8601 UTC string
 * (`DateTimeImmutable::ATOM`, e.g. `2026-07-22T00:00:00+00:00`) or null. A
 * string argument is provider-reported, so it is parsed with
 * {@see DateTimeImmutable} rather than passed through: it must be a
 * `YYYY-MM-DD` calendar date, optionally followed by an ISO time and a `Z` or
 * numeric offset, and parse without warnings (an offset-less value is read as
 * UTC). Anything else — empty, relative (`tomorrow`, `2026-07-13 next monday`),
 * or garbage — degrades to null ("reset time unknown") instead of throwing, so
 * a malformed reset field can never mask the quota signal itself.
 *
 * @package Phlix\Shared\Subtitle\Exception
 * @since 0.42.0
 */
final class QuotaExceeded extends RuntimeException
{
    /**
     * Remaining downloads the provider reported (null when unknown).
     *
     * @var int|null
     */
    private readonly ?int $downloadsRemaining;

    /**
     * When the quota resets, as an ISO-8601 UTC string (null when unknown).
     *
     * @var string|null
     */
    private readonly ?string $resetTimeUtc;

    /**
     * @param string                       $message            Human-readable reason.
     * @param int|null                     $downloadsRemaining Remaining downloads the provider reported, if any.
     *                                                          Usually 0 when the quota is exhausted; null when
     *                                                          the provider does not report it.
     * @param DateTimeImmutable|string|null $resetTimeUtc      When the quota resets. Accepts a
     *                                                          {@see DateTimeImmutable} (converted to UTC and
     *                                                          normalised to an ISO-8601 string), a
     *                                                          provider-reported date-time string (validated
     *                                                          and normalised the same way; unparseable
     *                                                          values become null), or null when the
     *                                                          provider does not report it.
     * @param int                          $code               Exception code (default 0).
     * @param Throwable|null               $previous           Underlying cause, if any.
     */
    public function __construct(
        string $message = 'Subtitle provider download quota exceeded.',
        ?int $downloadsRemaining = null,
        DateTimeImmutable|string|null $resetTimeUtc = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);

        $this->downloadsRemaining = $downloadsRemaining;
        $this->resetTimeUtc = self::normaliseResetTime($resetTimeUtc);
    }

    /**
     * Normalise a reset time to an ISO-8601 UTC string, or null when absent/invalid.
     *
     * @param DateTimeImmutable|string|null $resetTimeUtc Reset time as given to the constructor.
     *
     * @return string|null `DateTimeImmutable::ATOM` in UTC, or null.
     */
    private static function normaliseResetTime(DateTimeImmutable|string|null $resetTimeUtc): ?string
    {
        $utc = new DateTimeZone('UTC');

        if ($resetTimeUtc instanceof DateTimeImmutable) {
            return $resetTimeUtc->setTimezone($utc)->format(DateTimeImmutable::ATOM);
        }

        if ($resetTimeUtc === null) {
            return null;
        }

        $value = trim($resetTimeUtc);
        // Full ISO shape only: a date-prefixed relative suffix
        // (`2026-07-13 next monday`, `... +1 year`) must not reach the
        // DateTimeImmutable relative-format parser.
        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/i',
                $value
            ) !== 1
        ) {
            return null;
        }

        try {
            $parsed = new DateTimeImmutable($value, $utc);
        } catch (Exception) {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $parsed->setTimezone($utc)->format(DateTimeImmutable::ATOM);
    }

    /**
     * Remaining downloads the provider reported, or null when unknown.
     *
     * @return int|null
     */
    public function getDownloadsRemaining(): ?int
    {
        return $this->downloadsRemaining;
    }

    /**
     * When the quota resets, as an ISO-8601 UTC string, or null when unknown.
     *
     * @return string|null
     */
    public function getResetTimeUtc(): ?string
    {
        return $this->resetTimeUtc;
    }
}
