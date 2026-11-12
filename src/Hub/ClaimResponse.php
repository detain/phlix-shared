<?php

/**
 * Claim Response.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Hub;

use InvalidArgumentException;
use Phlix\Shared\Support\PayloadAssert;

/**
 * Hub → Server response to {@see ClaimRequest}.
 *
 * Master plan §6 step 2.
 *
 * SECURITY CONTRACT — `hubBaseUrl`. The hub asserts this URL and the
 * server persists it as its enrollment target: every later heartbeat,
 * enrollment-JWT refresh and hub call is sent there carrying the
 * server's enrollment JWT. `fromPayload()` therefore refuses anything that
 * is not an absolute `http`/`https` URL with a valid host (grammar in
 * {@see HostGrammar}), and refuses userinfo, query and fragment outright —
 * no `file:`/`gopher:`/`javascript:` scheme, no `https://hub@evil` host
 * confusion, no credentials riding the URL.
 *
 * What the gate does NOT do, and cannot (the DTO never sees the origin the
 * caller dialed): it does not prove the URL is the operator's hub, it does
 * not require TLS (`http://localhost:8800` is the hub's dev default), and it
 * does not block private or loopback hosts. Consumers MUST receive this
 * response only over the channel to the operator-configured hub (HTTPS in
 * production) and SHOULD refuse a `hubBaseUrl` whose origin differs from
 * the one they dialed, or that downgrades `https` to `http`, before
 * persisting it.
 *
 * @package Phlix\Shared\Hub
 * @since 0.2.0
 */
final readonly class ClaimResponse
{
    use PayloadAssert;

    /**
     * @param string $claimCode   Human-friendly code like "ABCD-1234" the operator pastes on the hub portal.
     * @param int    $expiresIn   Seconds the claim code is valid (master plan says 600).
     * @param string $claimId     UUID — opaque token the server stores so it can poll claim status.
     * @param string $hubBaseUrl  Where the server should send heartbeats once enrolled
     *                            (absolute http(s) URL — see SECURITY CONTRACT above).
     */
    public function __construct(
        public string $claimCode,
        public int $expiresIn,
        public string $claimId,
        public string $hubBaseUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * Reject arms: `claimCode`/`claimId`/`hubBaseUrl` missing, non-string or
     * empty; `expiresIn` missing or non-int; `hubBaseUrl` outside the
     * SECURITY CONTRACT grammar (class docblock).
     *
     * @throws InvalidArgumentException When a required field is missing, wrong-typed or empty,
     *                                  or `hubBaseUrl` is not an acceptable http(s) URL.
     */
    public static function fromPayload(array $payload): self
    {
        $claimCode = self::requireNonEmptyString($payload, 'claimCode', 'ClaimResponse');
        $claimId = self::requireNonEmptyString($payload, 'claimId', 'ClaimResponse');
        $hubBaseUrl = self::requireNonEmptyString($payload, 'hubBaseUrl', 'ClaimResponse');
        if (!HostGrammar::isValidHttpUrl($hubBaseUrl)) {
            throw new InvalidArgumentException(
                'ClaimResponse "hubBaseUrl" must be an absolute http(s) URL with a valid host'
                . ' and no userinfo, query or fragment.',
            );
        }
        $expiresIn = self::requireInt($payload, 'expiresIn', 'ClaimResponse');

        return new self(
            claimCode: $claimCode,
            expiresIn: $expiresIn,
            claimId: $claimId,
            hubBaseUrl: $hubBaseUrl,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'claimCode' => $this->claimCode,
            'expiresIn' => $this->expiresIn,
            'claimId' => $this->claimId,
            'hubBaseUrl' => $this->hubBaseUrl,
        ];
    }
}
