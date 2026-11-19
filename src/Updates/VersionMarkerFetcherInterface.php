<?php

/**
 * VersionMarkerFetcherInterface.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Updates;

/**
 * Non-blocking fetcher for a remote plain-text version marker (v0.57 leg
 * 0.12, M-23 / C-16).
 *
 * Exact twin lift: phlix-server `src/Server/Updates/VersionMarkerFetcherInterface.php`
 * (S74) and phlix-hub `src/Hub/Updates/VersionMarkerFetcherInterface.php`
 * (S75) — diff proven namespace/prose only (the server copy carries the
 * longer "why not the channel-based catalog fetcher" rationale; the law is
 * identical).
 *
 * `fetch(string $url): ?string`-style blocking reads are NOT acceptable here:
 * both hosts are resident-memory Workerman processes where a blocking HTTP
 * read freezes every connection, and the rejected coroutine-forked shapes
 * (channel arm selected on `Coroutine::getCid() > 0`) leave the async arm
 * structurally unreachable under PHPUnit — exactly the arm production takes
 * (the S170 defect class). This contract has no such fork: there is one code
 * path, and the suite drives it.
 */
interface VersionMarkerFetcherInterface
{
    /**
     * Fetch `$url` and invoke `$onDone` exactly once with the outcome.
     *
     * Implementations MUST NOT block the calling worker. `$onDone` receives
     * either the response body (first argument) or an error message (second
     * argument); exactly one of the two is non-null.
     *
     * @param string                                  $url    Absolute http(s) URL of the marker.
     * @param callable(string|null, string|null):void $onDone Completion callback: (body, error).
     *
     * @return void
     */
    public function fetch(string $url, callable $onDone): void;
}
