<?php

/**
 * Jwt Exception.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Security;

use RuntimeException;

/**
 * The single rejection type thrown by {@see Jwt::verify()}.
 *
 * Exactly three reasons, mirroring the two parents' distinct throw sites —
 * server `src/Auth/JwtHandler::decode()` and hub `src/Auth/JwtHandler::decode()`
 * both separate malformed-input, header-lies-about-alg/typ, and
 * signature-mismatch, and callers across the estate branch on that shape.
 * The reason is a typed property, not message parsing: `getMessage()` is
 * free text for logs, {@see self::$reason} is the machine-readable law.
 *
 * The `typ` rejection rides the `alg-unexpected` arm deliberately: both
 * parents check `alg` and `typ` in the same header-pin block *before* any
 * signature work, and the estate has never distinguished them programmatically.
 *
 * @package Phlix\Shared\Security
 * @since 0.56.0
 */
final class JwtException extends RuntimeException
{
    /** Token is not three segments, or header/payload is not a JSON object. */
    public const string REASON_MALFORMED = 'malformed';

    /** Header declares an `alg` (or `typ`) other than what this codec signs. */
    public const string REASON_ALG_UNEXPECTED = 'alg-unexpected';

    /** Three well-formed segments, but the HMAC does not prove them. */
    public const string REASON_SIGNATURE_MISMATCH = 'signature-mismatch';

    /**
     * @param string $reason One of the three REASON_* constants.
     * @param string $message Human-readable detail for logs (never parsed).
     */
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /**
     * Reject a token that is not three segments or whose header/payload is
     * not a JSON object — the shape gate, run before any crypto work.
     */
    public static function malformed(string $message): self
    {
        return new self(self::REASON_MALFORMED, $message);
    }

    /**
     * Reject a header that lies about `alg` (or `typ`) — both parents check
     * them in one block before signature work; see the class law above.
     */
    public static function algUnexpected(string $message): self
    {
        return new self(self::REASON_ALG_UNEXPECTED, $message);
    }

    /**
     * Reject a well-formed triple whose HMAC does not verify against the
     * expected secret.
     */
    public static function signatureMismatch(string $message = 'JWT signature mismatch.'): self
    {
        return new self(self::REASON_SIGNATURE_MISMATCH, $message);
    }
}
