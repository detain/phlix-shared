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
     *                                                          {@see DateTimeImmutable} (normalised to an
     *                                                          ISO-8601 string) or a pre-formatted string, or
     *                                                          null when the provider does not report it.
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
        $this->resetTimeUtc = $resetTimeUtc instanceof DateTimeImmutable
            ? $resetTimeUtc->format(DateTimeImmutable::ATOM)
            : $resetTimeUtc;
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
