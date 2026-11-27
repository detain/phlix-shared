<?php

/**
 * Ed25519 Pem Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Security;

use InvalidArgumentException;
use Phlix\Shared\Security\Ed25519Pem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Seed-vector corpus built on RFC 8032 test vector 1 (the canonical fixed
 * Ed25519 seed): seed 9d61b19d…7f60 expands to public key d75a9801…511a.
 * Every literal pinned below was captured from that key, so the kid shapes
 * are frozen against an independent reference, not against this code.
 */
#[CoversClass(\Phlix\Shared\Security\Ed25519Pem::class)]
final class Ed25519PemTest extends TestCase
{
    /** @var string RFC 8032 vector 1 seed (32 raw bytes). */
    private const SEED = '9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60';

    /** @var string The public key of seed SEED (32 raw bytes, hex). */
    private const PUBLIC = 'd75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a';

    /** @var string Full base64url(SHA-256(raw public)) — the hub wire shape (43 chars). */
    private const FINGERPRINT_FULL = 'If4x36FUomFia_hUBG_SJxt77UtqvkWqWId-9H-XIbk';

    /** @var string base64url(first 8 digest bytes) — the server wire shape (11 chars). */
    private const FINGERPRINT_8 = 'If4x36FUomE';

    public function test_parse_of_build_round_trips_the_reference_key(): void
    {
        // M-10 mandate: round-trip fixtures land FIRST — the parse leg of a
        // key this class just built must return it byte-for-byte.
        $parsed = Ed25519Pem::parsePem(Ed25519Pem::buildPem(self::secretKey()));

        $this->assertIsArray($parsed);
        $this->assertSame(self::secretKey(), $parsed['secret']);
        $this->assertSame(self::bytes(self::PUBLIC), $parsed['public']);
    }

    public function test_round_trip_holds_for_random_keypairs(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $pair = sodium_crypto_sign_keypair();
            $secret = sodium_crypto_sign_secretkey($pair);
            $public = sodium_crypto_sign_publickey($pair);

            $parsed = Ed25519Pem::parsePem(Ed25519Pem::buildPem($secret));

            $this->assertIsArray($parsed);
            $this->assertSame($secret, $parsed['secret']);
            $this->assertSame($public, $parsed['public']);
        }
    }

    public function test_buildPem_emits_the_server_native_dialect_byte_identical(): void
    {
        // The written format is the server's (SV-4.16: the writer stayed the
        // native base64url label, 64-char line wrapping). Freezing the exact
        // bytes here is what makes delegation behavior-neutral.
        $secret = self::secretKey();
        $body = rtrim(strtr(base64_encode($secret), '+/', '-_'), '=');

        $expected = "-----BEGIN ED25519 PRIVATE KEY-----\n"
            . implode("\n", str_split($body, 64)) . "\n"
            . "-----END ED25519 PRIVATE KEY-----\n";

        $this->assertSame($expected, Ed25519Pem::buildPem($secret));
    }

    public function test_parsePem_accepts_the_hub_plain_base64_dialect(): void
    {
        // The hub wrote the same label around STANDARD base64 (padding and
        // '+' '/' included). The native reader tolerates that dialect because
        // the URL-translate is a no-op on plain base64 and strict decode
        // accepts padding — pinned so the hub delegate keeps loading its own
        // on-disk keys after adoption.
        $pem = "-----BEGIN ED25519 PRIVATE KEY-----\n"
            . base64_encode(self::secretKey()) . "\n"
            . "-----END ED25519 PRIVATE KEY-----\n";

        $parsed = Ed25519Pem::parsePem($pem);

        $this->assertIsArray($parsed);
        $this->assertSame(self::secretKey(), $parsed['secret']);
        $this->assertSame(self::bytes(self::PUBLIC), $parsed['public']);
    }

    public function test_parsePem_accepts_a_standard_pkcs8_ed25519_key(): void
    {
        // RFC 8410 PKCS#8 PrivateKeyInfo: SEQUENCE { INTEGER 0,
        // SEQUENCE { OID 1.3.101.112 }, OCTET STRING(04 22) OCTET
        // STRING(04 20) seed }.
        $der = hex2bin('302e020100300506032b657004220420') . self::bytes(self::SEED);
        $pem = "-----BEGIN PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PRIVATE KEY-----\n";

        $parsed = Ed25519Pem::parsePem($pem);

        $this->assertIsArray($parsed);
        $this->assertSame(self::secretKey(), $parsed['secret']);
        $this->assertSame(self::bytes(self::PUBLIC), $parsed['public']);
    }

    public function test_buildPem_refuses_non_sixty_four_byte_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Ed25519Pem::buildPem(random_bytes(63));
    }

    public function test_parsePem_returns_null_for_garbage_and_wrong_key_types(): void
    {
        $this->assertNull(Ed25519Pem::parsePem('not a pem at all'));
        $this->assertNull(Ed25519Pem::parsePem(
            "-----BEGIN ED25519 PRIVATE KEY-----\nINVALID\n-----END ED25519 PRIVATE KEY-----\n"
        ));
        // A too-short native body (the server-load gate: < 64 bytes is invalid).
        $this->assertNull(Ed25519Pem::parsePem(
            "-----BEGIN ED25519 PRIVATE KEY-----\n" . base64_encode('short') . "\n-----END ED25519 PRIVATE KEY-----\n"
        ));
    }

    public function test_native_arm_takes_the_first_64_bytes_of_an_overlong_body(): void
    {
        // Server law kept: the native reader accepts strlen >= 64 and reads
        // the first 64 bytes. The hub's exact-64 rejection was incidental,
        // not a security boundary — disclosed in the class docblock, pinned
        // here so the posture is a decision, not an accident.
        $padded = self::secretKey() . str_repeat("\x00", 8);
        $pem = "-----BEGIN ED25519 PRIVATE KEY-----\n"
            . base64_encode($padded) . "\n"
            . "-----END ED25519 PRIVATE KEY-----\n";

        $parsed = Ed25519Pem::parsePem($pem);

        $this->assertIsArray($parsed);
        $this->assertSame(self::secretKey(), $parsed['secret']);
    }

    public function test_extractSeedFromPkcs8_positive_and_negatives(): void
    {
        $der = hex2bin('302e020100300506032b657004220420') . self::bytes(self::SEED);
        $this->assertSame(self::bytes(self::SEED), Ed25519Pem::extractSeedFromPkcs8($der));

        // No Ed25519 OID: never mis-read as an Ed25519 seed. (An RSA
        // AlgorithmIdentifier prefix plus unrelated bytes — the OID must be
        // genuinely absent, so nothing from a valid Ed25519 blob is reused.)
        $rsaLike = hex2bin('30820122020100300d06092a864886f70d0101010500') . str_repeat("\x00", 40);
        $this->assertNull(Ed25519Pem::extractSeedFromPkcs8($rsaLike));

        // OID present but seed marker absent.
        $noMarker = hex2bin('300506032b657004') . str_repeat("\x00", 40);
        $this->assertNull(Ed25519Pem::extractSeedFromPkcs8($noMarker));

        // Marker present but truncated tail (< 32 seed bytes).
        $truncated = substr($der, 0, 45);
        $this->assertNull(Ed25519Pem::extractSeedFromPkcs8($truncated));
    }

    public function test_fingerprint_server_shape_literal_is_frozen(): void
    {
        // E-1: emitted kid bytes of neither repo may change in this wave.
        $this->assertSame(
            self::FINGERPRINT_8,
            Ed25519Pem::fingerprint(self::bytes(self::PUBLIC), Ed25519Pem::PREFIX_BYTES_SERVER)
        );
        $this->assertSame(11, strlen(Ed25519Pem::fingerprint(self::bytes(self::PUBLIC), 8)));
    }

    public function test_fingerprint_hub_shape_literal_is_frozen(): void
    {
        $this->assertSame(
            self::FINGERPRINT_FULL,
            Ed25519Pem::fingerprint(self::bytes(self::PUBLIC))
        );
        $this->assertSame(43, strlen(Ed25519Pem::fingerprint(self::bytes(self::PUBLIC), null)));
    }

    public function test_fingerprint_is_the_raw_digest_under_the_named_bases(): void
    {
        $raw = hash('sha256', self::bytes(self::PUBLIC), true);

        $this->assertSame(rtrim(strtr(base64_encode(substr($raw, 0, 8)), '+/', '-_'), '='), Ed25519Pem::fingerprint(self::bytes(self::PUBLIC), 8));
        $this->assertSame(rtrim(strtr(base64_encode($raw), '+/', '-_'), '='), Ed25519Pem::fingerprint(self::bytes(self::PUBLIC), null));
    }

    public function test_fingerprint_refuses_out_of_range_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Ed25519Pem::fingerprint(self::bytes(self::PUBLIC), 33);
    }

    public function test_toJwk_pins_the_ordered_member_map_both_repos_emit(): void
    {
        // Both getPublicKeyJwk()/jwkFor() build the same six members in the
        // same order; the JWKS consumers key on 'kid' and 'x', so order is
        // cosmetic today but the map is a wire-adjacent shape — pinned.
        $this->assertSame([
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'x' => rtrim(strtr(base64_encode(self::bytes(self::PUBLIC)), '+/', '-_'), '='),
            'kid' => self::FINGERPRINT_8,
            'use' => 'sig',
            'alg' => 'EdDSA',
        ], Ed25519Pem::toJwk(self::bytes(self::PUBLIC), self::FINGERPRINT_8));
    }

    public function test_toJwk_crv_override_changes_only_crv(): void
    {
        $jwk = Ed25519Pem::toJwk(self::bytes(self::PUBLIC), 'k1', 'X25519');

        $this->assertSame('X25519', $jwk['crv']);
        $this->assertSame('OKP', $jwk['kty']);
        $this->assertSame('k1', $jwk['kid']);
    }

    /**
     * v0.59 bank row 6b: the byte-exact arm is the hub legacy parser's
     * posture, reproduced without one observable delta (differential
     * grid pinned at gates/item6-differential.log, 20 PEM vectors,
     * zero divergence). The interesting pins are the PAIRS where this
     * arm and the lenient native parse diverge BY DESIGN: equal-64
     * instead of at-least-64 (oversized refused, not truncated), and
     * no url normalization (url-safe bodies are corruption here).
     */
    public function testParsePemByteExactAcceptsTheLegacyStandardDialect(): void
    {
        $secret = self::secretKey();
        $pem = "-----BEGIN ED25519 PRIVATE KEY-----\n"
            . base64_encode($secret) . "\n-----END ED25519 PRIVATE KEY-----\n";

        $parsed = Ed25519Pem::parsePemByteExact($pem);

        $this->assertNotNull($parsed);
        $this->assertSame($secret, $parsed['secret']);
        $this->assertSame(substr($secret, 32), $parsed['public']);
    }

    public function testParsePemByteExactKeepsBareMarkerlessFilesLoadable(): void
    {
        // The hub never demanded marker lines; a bare base64 body is a
        // loadable file there, and must stay loadable through shared.
        $secret = self::secretKey();

        $parsed = Ed25519Pem::parsePemByteExact(base64_encode($secret));

        $this->assertNotNull($parsed, 'bare base64 files stay loadable, as pre-delegation');
        $this->assertSame($secret, $parsed['secret']);
    }

    public function testParsePemByteExactRefusesOversizedWhereTheLenientArmTruncates(): void
    {
        // The divergence pair the sign split exists to make explicit:
        // the native server dialect keeps `>= 64` and slices 64 (an
        // oversized body is silently truncated), while the hub law is
        // EQUAL-64 and loud. Same oversized input, opposite verdicts.
        $oversized = "-----BEGIN ED25519 PRIVATE KEY-----\n"
            . base64_encode(random_bytes(96)) . "\n-----END ED25519 PRIVATE KEY-----\n";

        $this->assertNull(Ed25519Pem::parsePemByteExact($oversized), 'equal-64 law: oversized is refused');
        $this->assertNotNull(
            Ed25519Pem::parsePem($oversized),
            'the lenient native arm still truncates - both postures live, by design'
        );
    }

    public function testParsePemByteExactRefusesUrlAlphabetBodiesWithoutNormalization(): void
    {
        // No `-`/`_` translation happens in this arm: url-safe marks in
        // an otherwise-standard body are corruption, exactly as the hub
        // legacy route treated them (the grammar predicate sends real
        // modern folds to the other arm consumer-side).
        $secret = self::secretKey();
        $body = base64_encode($secret);
        $injected = substr($body, 0, 10) . '-' . substr($body, 11);

        $this->assertNull(Ed25519Pem::parsePemByteExact(
            "-----BEGIN ED25519 PRIVATE KEY-----\n" . $injected . "\n-----END ED25519 PRIVATE KEY-----\n"
        ));
    }


    /**
     * phlix-shared-023: a sodium-less build must get the typed refusal,
     * never a fatal undefined-function error mid-parse. The flag is the
     * probe verdict the class feeds into the expansion seam, so the arm is
     * reachable without uninstalling sodium — mirroring the LW-056 ext-curl
     * posture pinned in AbstractArrClientTest.
     */
    public function test_expandSeedToSecret_refuses_loudly_without_sodium(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires the PHP sodium extension \\(ext-sodium\\)/');

        self::invokeSodiumExpansion(false, self::bytes(self::SEED));
    }

    public function test_expandSeedToSecret_positive_arm_is_the_reference_expansion(): void
    {
        // The guard must not change the byte result on a capable runtime:
        // same seed in, same 64-byte secret out as the direct sodium call.
        $expanded = self::invokeSodiumExpansion(true, self::bytes(self::SEED));

        $this->assertSame(self::secretKey(), $expanded);
    }

    public function test_hasSodiumSupport_is_a_static_probe_answering_true_on_host(): void
    {
        // LW-056's third leg shape: the probe exists as a static on the class
        // and reports the host truth, so the fail-loud arm is a decision
        // surface, not a tautology over a missing-extension fixture.
        $probe = new ReflectionMethod(Ed25519Pem::class, 'hasSodiumSupport');

        $this->assertTrue($probe->isStatic());
        $this->assertTrue($probe->invoke(null));
    }

    public function test_sodium_calls_are_confined_to_the_guarded_expansion_seam(): void
    {
        // Wiring census: parsePkcs8Pem reaches libsodium only through the
        // seam and asks the probe first; every other method stays
        // sodium-free, which is what keeps the native dialect and the
        // seed-only extractor the documented escape hatches.
        $expansion = self::methodBody('expandSeedToSecret');
        $this->assertStringContainsString('sodium_crypto_sign_seed_keypair(', $expansion);
        $this->assertStringContainsString('sodium_crypto_sign_secretkey(', $expansion);
        $this->assertStringContainsString('throw new RuntimeException(', $expansion);

        $pkcs8 = self::methodBody('parsePkcs8Pem');
        $this->assertStringContainsString('self::expandSeedToSecret(self::hasSodiumSupport(', $pkcs8);
        $this->assertStringNotContainsString('sodium_crypto_sign', $pkcs8);

        foreach (
            [
                'buildPem',
                'extractSeedFromPkcs8',
                'fingerprint',
                'parseNativePem',
                'parsePem',
                'parsePemByteExact',
                'toJwk',
            ] as $method
        ) {
            $this->assertStringNotContainsString(
                'sodium_crypto_sign',
                self::methodBody($method),
                $method . '() must stay sodium-free (escape hatches load without the extension)'
            );
        }
    }

    /**
     * Fixture bytes for a hex literal, narrowed away from hex2bin's false
     * arm so the level-9 suite sees a plain non-empty string everywhere.
     *
     * @return non-empty-string
     */
    private static function bytes(string $hex): string
    {
        $bytes = hex2bin($hex);
        if ($bytes === false || $bytes === '') {
            throw new InvalidArgumentException('Bad fixture hex: ' . $hex);
        }

        return $bytes;
    }

    /**
     * The 64-byte libsodium secret key (seed || public) of the reference seed.
     */
    private static function secretKey(): string
    {
        return sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(self::bytes(self::SEED)));
    }

    /**
     * Reach the class's private expansion seam exactly as the parse path
     * reaches it, with a chosen probe verdict (reflection, not a fixture
     * that fakes the extension away).
     */
    private static function invokeSodiumExpansion(bool $sodiumSupported, string $seed): string
    {
        $seam = new ReflectionMethod(Ed25519Pem::class, 'expandSeedToSecret');

        $result = $seam->invoke(null, $sodiumSupported, $seed);
        if (!is_string($result)) {
            self::fail('expandSeedToSecret() must return a string, got ' . get_debug_type($result));
        }

        return $result;
    }

    /**
     * Source slice of one Ed25519Pem method: declaration line through its
     * closing brace (the reflection bracket excludes the docblock), so the
     * wiring census greps bodies, not prose.
     */
    private static function methodBody(string $method): string
    {
        $reflection = new ReflectionMethod(Ed25519Pem::class, $method);

        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();
        if (!is_string($file) || !is_int($start) || !is_int($end)) {
            self::fail('No source line range for ' . $method . '()');
        }

        $lines = file($file);
        if (!is_array($lines)) {
            self::fail('Cannot read ' . $file);
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
