<?php

/**
 * Ed25519 Pem.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Security;

use InvalidArgumentException;
use RuntimeException;

/**
 * Pure Ed25519 key-material primitives: native PEM build/parse, PKCS#8 seed
 * extraction, JWK assembly, and the deterministic kid fingerprint.
 *
 * Extracted from the two `Ed25519KeyManager` implementations (M-10). The
 * key *custody* half of those classes — flock, tempnam/0600 ceremony,
 * rotation policy (hub's OVERLAP_TTL previous-key sidecar vs the server's
 * single key), file I/O — is per-product on purpose and stays in the repos;
 * this class owns only the string-level crypto they both re-implemented.
 *
 * PEM dialects. Two native dialects are in production today: phlix-server
 * writes base64url of the raw 64-byte libsodium secret key wrapped in the
 * `ED25519 PRIVATE KEY` label with 64-character line folding (SV-4.16),
 * while phlix-hub wrapped plain base64 in the same label on one line.
 * {@see self::parsePem()} reads both (the URL-translate is a no-op on plain
 * base64 and strict decoding accepts padding) plus standard RFC 8410
 * PKCS#8; {@see self::buildPem()} writes the server native dialect only.
 * A reader that tolerates history while the writer stays canonical is the
 * posture both parents already hold; hub-side adoption re-labels new files,
 * which is format-local and reversible because every dialect stays readable.
 *
 * Oversized native bodies (decoded length > 64) take their first 64 bytes —
 * the server's long-standing `strlen >= 64` substr posture. The hub's exact
 * 64 check rejected them, but that was incidental strictness, not a security
 * boundary (extra trailing bytes were never consulted).
 *
 * Sodium arm (phlix-shared-023). Expanding a PKCS#8 seed into the 64-byte
 * libsodium secret key is the only place this class touches the sodium
 * extension; the native dialect and {@see self::extractSeedFromPkcs8()} are
 * sodium-free. The dependency is declared in composer.json `suggest`, and
 * every sodium call is routed through one guarded expansion seam behind a
 * {@see self::hasSodiumSupport()} probe (the AbstractArrClient ext-curl
 * idiom), so a build without the extension gets a typed
 * {@see \RuntimeException} carrying the install hint and the native-dialect
 * escape hatch — never a fatal undefined-function error mid-parse.
 *
 * kid law (E-1). The fingerprint is parameterized, not unified: each repo
 * passes what it emits TODAY — `8` for the server's historical 11-character
 * wire shape, `null` for the hub's full 43-character shape. The emitted kid
 * bytes of neither repo may change in this wave; unifying the length is a
 * key-identity change reserved for owner-row O-2. What IS unified is the
 * hub's determinism law (B-04/#4): a kid is always the fingerprint of the
 * public half, never a wall-clock stamp.
 *
 * @package Phlix\Shared\Security
 * @since 0.56.0
 */
final class Ed25519Pem
{
    /**
     * First digest bytes the server's kid wire shape encodes.
     *
     * Historical constant (E-1): the server keys enrollment/JWKS consumers
     * with base64url(SHA-256(pub)[0:8]) — 11 characters.
     */
    public const int PREFIX_BYTES_SERVER = 8;

    /** Native label written by this class and read by {@see self::parsePem()}. */
    private const string NATIVE_LABEL = 'ED25519 PRIVATE KEY';

    /** Ed25519 algorithm OID 1.3.101.112 as DER. */
    private const string OID_ED25519 = "\x06\x03\x2b\x65\x70";

    /**
     * DER framing of the seed: the 32-byte seed OCTET STRING (`04 20`)
     * nested inside the PrivateKey OCTET STRING (`04 22`).
     */
    private const string SEED_MARKER = "\x04\x22\x04\x20";

    /** A libsodium Ed25519 secret key is exactly seed (32) || public (32). */
    private const int SECRET_KEY_BYTES = 64;

    /**
     * Prevent instantiation — this class is a static utility only.
     */
    private function __construct()
    {
    }

    /**
     * Builds the native PEM string from a raw libsodium secret key.
     *
     * Byte-identical to the server writer it replaces: base64url of the
     * 64-byte secret, 64-character line folding, native label, trailing
     * newline.
     *
     * @param string $secretKey 64-byte libsodium secret key (seed || public key).
     *
     * @return string PEM-encoded private key with line breaks.
     *
     * @throws InvalidArgumentException If the input is not exactly 64 bytes.
     */
    public static function buildPem(string $secretKey): string
    {
        if (strlen($secretKey) !== self::SECRET_KEY_BYTES) {
            throw new InvalidArgumentException(
                'Ed25519 secret key must be exactly ' . self::SECRET_KEY_BYTES
                    . ' bytes, got ' . strlen($secretKey) . '.'
            );
        }

        $base64 = Base64Url::encode($secretKey);

        return '-----BEGIN ' . self::NATIVE_LABEL . "-----\n"
            . implode("\n", str_split($base64, 64)) . "\n"
            . '-----END ' . self::NATIVE_LABEL . "-----\n";
    }

    /**
     * Parses a PEM string into the raw 64-byte secret key and its public half.
     *
     * Accepts, in order: the native label around base64url (server dialect)
     * or plain base64 (hub dialect) of the 64-byte secret; and a standard
     * PKCS#8 `PRIVATE KEY` block whose 32-byte seed is expanded through
     * {@see \sodium_crypto_sign_seed_keypair()} so the rest of the pipeline
     * is identical regardless of source format.
     *
     * @param string $pem The PEM content.
     *
     * @return array{secret: string, public: string}|null Null on any parse failure;
     *                                                    callers map that to their own
     *                                                    load-error posture.
     *
     * @throws RuntimeException If a well-formed PKCS#8 Ed25519 key must expand its
     *                          seed but the PHP sodium extension is absent. Native
     *                          dialects and parse failures never reach this arm.
     */
    public static function parsePem(string $pem): ?array
    {
        $secret = self::parseNativePem($pem);

        if ($secret === null) {
            $secret = self::parsePkcs8Pem($pem);
        }

        if ($secret === null) {
            return null;
        }

        return ['secret' => $secret, 'public' => substr($secret, 32, 32)];
    }

    /**
     * Parse the hub legacy key-file dialect with byte-exact fidelity
     * (v0.59 sign-arm split's strict PEM arm).
     *
     * The hub's `extractKeyPair` legacy arm preserved a pre-delegation
     * rule the lenient native parser above deliberately does not carry:
     * the body must survive a STRICT base64 decode of the STANDARD
     * alphabet (no `-`/`_` normalization happens here — url-safe marks
     * are corruption in this dialect), the whitespace tolerance is the
     * hub's literal set (`\r`, `\n`, space) plus whatever the strict
     * decoder itself skips (measured harness: tabs included, both
     * sides agree), the length law
     * is EQUAL-64 (not at-least-64: a 96-byte oversized body is
     * REFUSED, never silently truncated), the marker lines may be absent
     * entirely (bare base64 files stay loadable), and nothing beyond
     * those checks is consulted when the parse succeeds. This method
     * reproduces that posture and reports the verdict in the same
     * `secret`/`public` shape {@see self::parsePem()} uses (at an exact
     * 64-byte body the public slice is provably identical to the hub's
     * open-ended one), so the hub can retire its duplicated body in the
     * successor lane without one observable change to load behavior:
     * null means "throw the legacy message there"; the grammar routing
     * predicate stays hub-side where the custody posture lives.
     *
     * @param string $pem The key-file content in the legacy dialect.
     *
     * @return array{secret: string, public: string}|null Null on any parse failure.
     */
    public static function parsePemByteExact(string $pem): ?array
    {
        $body = trim($pem);
        $body = (string) preg_replace('#-----(BEGIN|END) ' . self::NATIVE_LABEL . '-----#', '', $body);
        $body = str_replace(["\r", "\n", ' '], '', $body);
        $decoded = base64_decode($body, true);

        if ($decoded === false) {
            $decoded = '';
        }

        if (strlen($decoded) !== self::SECRET_KEY_BYTES) {
            return null;
        }

        return ['secret' => $decoded, 'public' => substr($decoded, 32, 32)];
    }

    /**
     * Extracts the 32-byte Ed25519 seed from a PKCS#8 DER blob.
     *
     * The Ed25519 OID must be present before any extraction, so a non-Ed25519
     * PKCS#8 key (RSA, P-256, …) is never silently mis-read as an Ed25519
     * seed. Anchoring on the full `04 22 04 20` framing means a coincidental
     * `04 20` elsewhere cannot be mistaken for the seed marker.
     *
     * @param string $der Raw DER bytes of the PKCS#8 PrivateKeyInfo.
     *
     * @return string|null The 32-byte seed, or null if the structure is not a
     *                     well-formed Ed25519 PKCS#8 key.
     */
    public static function extractSeedFromPkcs8(string $der): ?string
    {
        if (strpos($der, self::OID_ED25519) === false) {
            return null;
        }

        $pos = strpos($der, self::SEED_MARKER);
        if ($pos === false) {
            return null;
        }

        $seed = substr($der, $pos + strlen(self::SEED_MARKER), 32);
        if (strlen($seed) !== 32) {
            return null;
        }

        return $seed;
    }

    /**
     * Builds the RFC 8037 OKP JWK both repos' key managers emit, in order.
     *
     * @param string $publicKey 32-byte raw public key.
     * @param string $kid       Key id to carry (pass the fingerprint, or a retained value).
     * @param string $crv       Curve name; Ed25519 today, parameterized for X25519-style reuse.
     *
     * @return array{kty: string, crv: string, x: string, kid: string, use: string, alg: string}
     */
    public static function toJwk(string $publicKey, string $kid, string $crv = 'Ed25519'): array
    {
        return [
            'kty' => 'OKP',
            'crv' => $crv,
            'x' => Base64Url::encode($publicKey),
            'kid' => $kid,
            'use' => 'sig',
            'alg' => 'EdDSA',
        ];
    }

    /**
     * Deterministic kid fingerprint of a raw public key.
     *
     * base64url(SHA-256(raw public)), optionally truncated to
     * `$prefixBytes` of the digest BEFORE encoding. The two sanctioned shapes
     * (see the class header, E-1): 8 bytes = the server's 11-character wire
     * identity, null = the hub's full 43-character identity.
     *
     * @param string   $publicKey   32-byte raw public key.
     * @param int|null $prefixBytes Digest bytes to keep before encoding, or null for all 32.
     *
     * @return string The base64url fingerprint.
     *
     * @throws InvalidArgumentException If $prefixBytes is outside 1..32.
     */
    public static function fingerprint(string $publicKey, ?int $prefixBytes = null): string
    {
        if ($prefixBytes !== null && ($prefixBytes < 1 || $prefixBytes > 32)) {
            throw new InvalidArgumentException(
                'Ed25519 fingerprint prefix must be between 1 and 32 bytes, got ' . $prefixBytes . '.'
            );
        }

        $digest = hash('sha256', $publicKey, true);

        return Base64Url::encode($prefixBytes === null ? $digest : substr($digest, 0, $prefixBytes));
    }

    /**
     * Parses the native label, covering both in-production body alphabets.
     *
     * @param string $pem The PEM content.
     *
     * @return string|null The 64-byte secret key (first 64 bytes of a longer
     *                     body — server posture kept), or null on failure.
     */
    private static function parseNativePem(string $pem): ?string
    {
        $pattern = '/-----BEGIN ' . preg_quote(self::NATIVE_LABEL, '/') . '-----(.*?)-----END '
            . preg_quote(self::NATIVE_LABEL, '/') . '-----/s';
        if (!preg_match($pattern, $pem, $matches)) {
            return null;
        }

        $base64 = preg_replace('/\s+/', '', $matches[1]);
        if (!is_string($base64)) {
            return null;
        }

        $decoded = base64_decode(strtr($base64, '-_', '+/'), true);

        if ($decoded === false || strlen($decoded) < self::SECRET_KEY_BYTES) {
            return null;
        }

        return substr($decoded, 0, self::SECRET_KEY_BYTES);
    }

    /**
     * Parses a standard PKCS#8 Ed25519 private key (RFC 8410).
     *
     * The seed is expanded via libsodium rather than ext-openssl because
     * PHP's `openssl_pkey_get_details()` does not reliably expose raw Ed25519
     * material across builds, whereas seed expansion is portable and exact.
     * Both sodium calls sit behind {@see self::expandSeedToSecret()} so a
     * build without the extension gets the typed refusal, not a fatal
     * undefined-function error (phlix-shared-023).
     *
     * @param string $pem The PEM content.
     *
     * @return string|null The 64-byte secret key, or null if the PKCS#8 label
     *                     is absent, the DER is malformed, or the key type is
     *                     not Ed25519.
     *
     * @throws RuntimeException If the seed is well-formed but the PHP sodium
     *                          extension is absent.
     */
    private static function parsePkcs8Pem(string $pem): ?string
    {
        $pattern = '/-----BEGIN PRIVATE KEY-----(.*?)-----END PRIVATE KEY-----/s';
        if (!preg_match($pattern, $pem, $matches)) {
            return null;
        }

        $base64 = preg_replace('/\s+/', '', $matches[1]);
        if (!is_string($base64) || $base64 === '') {
            return null;
        }

        // PKCS#8 uses standard (not URL-safe) base64.
        $der = base64_decode($base64, true);
        if ($der === false || strlen($der) < 48) {
            return null;
        }

        $seed = self::extractSeedFromPkcs8($der);
        if ($seed === null || $seed === '') {
            return null;
        }

        return self::expandSeedToSecret(self::hasSodiumSupport(), $seed);
    }

    /**
     * Whether this runtime carries the sodium functions the PKCS#8 arm needs.
     *
     * Overridable-probe idiom: the caller feeds the verdict into
     * {@see self::expandSeedToSecret()} so tests exercise the fail-loud arm
     * without uninstalling the extension (the curl analogue in
     * AbstractArrClient; reached via reflection from the suite).
     */
    private static function hasSodiumSupport(): bool
    {
        return function_exists('sodium_crypto_sign_seed_keypair')
            && function_exists('sodium_crypto_sign_secretkey');
    }

    /**
     * The single guarded door to libsodium: expand a 32-byte PKCS#8 seed
     * into its 64-byte secret key.
     *
     * @param bool             $sodiumSupported Probe verdict ({@see self::hasSodiumSupport()}).
     * @param non-empty-string $seed            32-byte Ed25519 seed (already
     *                                          length-checked by the extractor).
     *
     * @return string The 64-byte libsodium secret key (seed || public).
     *
     * @throws RuntimeException If the extension is absent: install ext-sodium,
     *                          or store keys in the native dialect, which this
     *                          class parses without sodium.
     */
    private static function expandSeedToSecret(bool $sodiumSupported, string $seed): string
    {
        if (!$sodiumSupported) {
            throw new RuntimeException(
                'Ed25519Pem PKCS#8 seed expansion requires the PHP sodium extension'
                    . ' (ext-sodium): install it, or store keys in the native'
                    . ' ED25519 PRIVATE KEY dialect, which parses without sodium.'
            );
        }

        $keypair = sodium_crypto_sign_seed_keypair($seed);

        return sodium_crypto_sign_secretkey($keypair);
    }
}
