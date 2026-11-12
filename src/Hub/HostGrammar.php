<?php

/**
 * Host Grammar.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Hub;

use InvalidArgumentException;

/**
 * Host / URL grammar shared by the Hub DTO `fromPayload()` boundaries.
 *
 * One grammar, two consumers: the `hostnameCandidates` list on
 * {@see HeartbeatDto} and {@see ClaimRequest}, and the `hubBaseUrl` on
 * {@see ClaimResponse}. A host is an RFC 1123 hostname (ASCII letters,
 * digits and inner hyphens; labels 1–63 octets; total ≤ 253; no trailing
 * dot; the rightmost label may not be all-numeric, so a malformed dotted
 * quad such as `1.2.3.999` cannot pass as a name), an IPv4 dotted quad, or
 * an IPv6 literal. Non-ASCII names must arrive punycoded (`xn--…`).
 *
 * A hostname candidate is either a bare host — optionally `:port`, IPv6
 * bracketed when a port follows — or an absolute `http`/`https` URL whose
 * authority is such a host. The URL form is the estate's live wire: the
 * server advertises `scheme://host:port` (HubClient::getHostnameCandidates,
 * PortForwardService). Userinfo, query and fragment are refused in every
 * URL (credential smuggling / `host@evil` confusion is not advertise
 * material); a path is allowed (reverse-proxy base paths). Ports are
 * 1–65535. Any byte outside printable ASCII (space, control, non-ASCII)
 * refuses the whole value.
 *
 * This is shape validation only — it says a string is syntactically a
 * host or URL, not that it is reachable, public, or trustworthy.
 *
 * @internal Shared-package implementation detail of the Hub DTOs; not a
 *           consumer API and may change without a major bump.
 *
 * @package Phlix\Shared\Hub
 * @since 0.55.0
 */
final class HostGrammar
{
    private const LABEL = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';

    /**
     * Parse an optional `hostnameCandidates` payload key into a list.
     *
     * Absent → `[]`. Present-but-not-array and non-string entries keep
     * their pre-existing messages verbatim; an entry that fails
     * {@see self::isValidCandidate()} throws naming its zero-based index
     * (the value itself is not echoed — consumers relay these messages).
     *
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException When the key is malformed.
     */
    public static function parseCandidates(array $payload, string $context): array
    {
        if (!array_key_exists('hostnameCandidates', $payload)) {
            return [];
        }
        if (!is_array($payload['hostnameCandidates'])) {
            throw new InvalidArgumentException(sprintf('%s "hostnameCandidates" must be a list of strings.', $context));
        }

        $candidates = [];
        foreach ($payload['hostnameCandidates'] as $candidate) {
            if (!is_string($candidate)) {
                throw new InvalidArgumentException(
                    sprintf('%s "hostnameCandidates" must contain only strings.', $context),
                );
            }
            if (!self::isValidCandidate($candidate)) {
                throw new InvalidArgumentException(sprintf(
                    '%s "hostnameCandidates" entry %d must be a hostname, IP literal, or http(s) URL.',
                    $context,
                    count($candidates),
                ));
            }
            $candidates[] = $candidate;
        }

        return $candidates;
    }

    /**
     * Whether `$candidate` is a bare host[:port] or an absolute http(s) URL.
     */
    public static function isValidCandidate(string $candidate): bool
    {
        if (!self::isPrintableAscii($candidate)) {
            return false;
        }
        if (str_contains($candidate, '://')) {
            return self::isValidHttpUrl($candidate);
        }
        if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (preg_match('/^\[([^\]]+)\](?::(\d{1,5}))?$/D', $candidate, $m) === 1) {
            return filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                && (!isset($m[2]) || self::isValidPort((int) $m[2]));
        }
        if (preg_match('/^([^:\[\]]+)(?::(\d{1,5}))?$/D', $candidate, $m) === 1) {
            return self::isValidHost($m[1])
                && (!isset($m[2]) || self::isValidPort((int) $m[2]));
        }

        return false;
    }

    /**
     * Whether `$url` is an absolute `http`/`https` URL with a valid host,
     * an optional 1–65535 port, an optional path, and no userinfo, query or
     * fragment. Scheme matching is case-insensitive.
     */
    public static function isValidHttpUrl(string $url): bool
    {
        if (!self::isPrintableAscii($url) || str_contains($url, '\\')) {
            return false;
        }
        if (preg_match('#^https?://#i', $url) !== 1) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        // parse_url() is lenient about '?'/'#' with empty tails; refuse the
        // delimiters themselves so the "no query/fragment" claim is literal.
        if (str_contains($url, '?') || str_contains($url, '#') || str_contains($url, '@')) {
            return false;
        }
        if (isset($parts['port']) && !self::isValidPort($parts['port'])) {
            return false;
        }

        $host = $parts['host'];
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return self::isValidHost($host);
    }

    /**
     * Whether `$host` is an IPv4 dotted quad or an RFC 1123 hostname
     * (IPv6 literals are handled by the callers, bracketed or bare).
     */
    public static function isValidHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }
        if (strlen($host) > 253) {
            return false;
        }
        if (preg_match('/^' . self::LABEL . '(?:\.' . self::LABEL . ')*$/D', $host) !== 1) {
            return false;
        }
        $labels = explode('.', $host);

        return !ctype_digit($labels[count($labels) - 1]);
    }

    private static function isValidPort(int $port): bool
    {
        return $port >= 1 && $port <= 65535;
    }

    private static function isPrintableAscii(string $value): bool
    {
        return $value !== '' && preg_match('/^[\x21-\x7E]+$/D', $value) === 1;
    }
}
