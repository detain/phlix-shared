<?php

/**
 * Jwt Token Parts.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Security;

/**
 * The signature-verified contents of a JWT, as decoded maps.
 *
 * A pure value object produced by {@see Jwt::verify()}: by the time an
 * instance exists, the alg/typ header pin has passed and the HMAC has been
 * proven with `hash_equals` — both fields below are trusted reads of data an
 * authenticated sender wrote. Claim *semantics* (expiry, audience, issuer)
 * are deliberately NOT modelled here; that is the job of the
 * {@see \Phlix\Shared\Auth\JwtClaims} DTO and each consumer's validation
 * pipeline. This class carries bytes that proved themselves, nothing more.
 *
 * Key-shape law (inherited verbatim from both parents' decoders): payload
 * keys are filtered to strings, so numeric JSON keys are dropped rather than
 * arriving as PHP int offsets.
 *
 * @package Phlix\Shared\Security
 * @since 0.56.0
 */
final class JwtTokenParts
{
    /**
     * @param array<array-key, mixed> $header  The decoded JOSE header (e.g. alg/typ).
     * @param array<string, mixed>    $payload The decoded claims set, string keys only.
     */
    public function __construct(
        public readonly array $header,
        public readonly array $payload,
    ) {
    }
}
