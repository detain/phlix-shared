<?php

declare(strict_types=1);

namespace Phlix\Shared\Events;

use Psr\Clock\ClockInterface;

/**
 * Common base for all Phlix PSR-14 events.
 *
 * Provides a single readonly `timestamp` (UNIX epoch seconds) captured at
 * construction time so listeners can correlate dispatch order without
 * having to add their own clock plumbing. Concrete events are expected to
 * declare their payload as additional `public readonly` constructor
 * properties — events are immutable by PSR-14 convention.
 *
 * Subclasses MUST call `parent::__construct()` so the timestamp is
 * populated. They MUST NOT add mutator methods or write to inherited
 * properties.
 *
 * @package Phlix\Shared\Events
 * @since 0.2.0
 */
abstract class AbstractEvent
{
    /**
     * UNIX timestamp (seconds since epoch) at which the event was
     * constructed. Captured here so dispatchers and listeners always see
     * the same value regardless of when a listener actually runs.
     */
    public readonly int $timestamp;

    /**
     * Capture the construction timestamp.
     *
     * @since 0.2.0
     *
     * @param ClockInterface|null $clock Optional PSR-20 clock for deterministic testing.
     *                                   When null (default), falls back to `time()` for BC.
     */
    public function __construct(?ClockInterface $clock = null)
    {
        $this->timestamp = $clock !== null
            ? $clock->now()->getTimestamp()
            : time();
    }
}
