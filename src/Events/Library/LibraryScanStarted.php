<?php

/**
 * Library Scan Started.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Events\Library;

use Phlix\Shared\Events\AbstractEvent;

/**
 * Fired when a library scan begins.
 *
 * Fired by: `\Phlix\Media\Library\MediaScanner::scan()` at the top of
 * the scan, after path validation.
 * Typical listener: progress dashboard (start spinner), webhook
 * notification framework (notify "scan started"), maintenance window
 * coordinator (pause other heavy jobs).
 *
 * Manifest alias: `phlix.library.scan.started`.
 *
 * @package Phlix\Shared\Events\Library
 * @since 0.2.0
 */
final class LibraryScanStarted extends AbstractEvent
{
    /**
     * @param string $libraryId   UUID of the library being scanned.
     * @param string $libraryName Human-readable library name (for log /
     *                            notification rendering).
     * @param string $path        Absolute filesystem path being walked.
     */
    public function __construct(
        public readonly string $libraryId,
        public readonly string $libraryName,
        public readonly string $path,
    ) {
        parent::__construct();
    }
}
