<?php

/**
 * Network probe for schema documentation links.
 *
 * Used ONLY by the `network`-grouped schema tests, which are excluded from the
 * default PHPUnit run (see `phpunit.xml`). The default suite asserts link
 * *shape* offline; this probe asserts link *liveness* on demand.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Schema;

final class SchemaLinkProbe
{
    /**
     * Statuses treated as "the page exists".
     *
     * 403 is included deliberately: several legitimate documentation hosts
     * (anidb.net, fanart.tv) sit behind bot protection that rejects an
     * automated request while serving humans fine. A 404/410/5xx is a real
     * failure; a bot-block is not.
     *
     * @var list<int>
     */
    private const ACCEPTABLE = [200, 401, 403, 405];

    /**
     * Browser-ish user agent, so bot filters do not fabricate failures.
     */
    private const USER_AGENT = 'Mozilla/5.0 (compatible; phlix-shared schema link check)';

    /**
     * Resolve the final HTTP status for a URL, following redirects.
     *
     * @param string $url Absolute https URL.
     *
     * @return int The final HTTP status code, or 0 when the request failed at
     *             the transport level.
     */
    public static function status(string $url): int
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return 0;
        }

        curl_setopt_array($handle, [
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return $status;
    }

    /**
     * Whether a probed status counts as a live link.
     *
     * @param int $status HTTP status from {@see status()}.
     *
     * @return bool True when the page is considered to exist.
     */
    public static function isAcceptable(int $status): bool
    {
        return in_array($status, self::ACCEPTABLE, true);
    }
}
