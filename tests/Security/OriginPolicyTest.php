<?php

/**
 * Security: OriginPolicy unit suite.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Security;

use Phlix\Shared\Security\OriginPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The vector corpus below was captured against BOTH parents' live
 * implementations (phlix-server `Auth\CookiePolicy` + phlix-hub
 * `Hub\CookiePolicy`) and cross-checked for three-way agreement over a
 * 3,026-vector sweep (originIsSameSite × Referer/Origin/Host/belt full
 * combinations × null-Host leg × custody arms — harness
 * refactor-exec-1119/scratch/o1-harness.php): zero divergences, so every
 * pinned expectation below is simultaneously the server answer, the hub
 * answer and the shared answer. DESIGN O-1 therefore records NO law
 * conflicts for this kernel — the two disclosed structural divergences
 * (custody parameterization, the `(string)`-cast Host arms) are
 * semantically empty, both pinned here.
 */
#[CoversClass(OriginPolicy::class)]
final class OriginPolicyTest extends TestCase
{
    /**
     * @return array<string, array{0: string|null, 1: bool}>
     */
    public static function forwardedProtoProvider(): array
    {
        return [
            'missing' => [null, false],
            'empty' => ['', false],
            'plain https' => ['https', true],
            'case-folded HTTPS' => ['HTTPS', true],
            'first hop wins (chain https,http)' => ['https, http', true],
            'first hop wins (chain http,https)' => ['http, https', false],
            'padded http' => [' http ', false],
            'plain http' => ['http', false],
            'zero' => ['0', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forwardedProtoProvider')]
    public function test_httpsForwardedValue_firstHopLaw(?string $value, bool $expected): void
    {
        self::assertSame($expected, OriginPolicy::httpsForwardedValue($value));
    }

    public function test_httpsForwarded_readsTheHeadersMap_caseInsensitively(): void
    {
        self::assertTrue(OriginPolicy::httpsForwarded(['X-Forwarded-Proto' => 'https']));
        self::assertTrue(OriginPolicy::httpsForwarded(['x-forwarded-proto' => 'HTTPS, http']));
        self::assertFalse(OriginPolicy::httpsForwarded(['X-Forwarded-Proto' => 'http']));
        self::assertFalse(OriginPolicy::httpsForwarded([]));
        self::assertFalse(OriginPolicy::httpsForwarded(['Host' => 'example.com']));
        // The map leg and the string leg agree on every real key form (the
        // sweep proved map==direct on the whole forwarded corpus).
        self::assertSame(
            OriginPolicy::httpsForwardedValue('https, http'),
            OriginPolicy::httpsForwarded(['x-FORWARDED-proto' => 'https, http']),
        );
    }

    /**
     * @return array<string, array{0: string|null, 1: bool}>
     */
    public static function localhostHostProvider(): array
    {
        return [
            'localhost' => ['localhost', true],
            'LOCALHOST case-folded' => ['LOCALHOST', true],
            'localhost with port' => ['localhost:8080', true],
            'loopback v4 + port' => ['127.0.0.1:80', true],
            'bracketed v6' => ['[::1]', true],
            'bracketed v6 + port' => ['[::1]:8080', true],
            // The list names '::1', but a wire Host uses the bracket form
            // (RFC 7230); a bare unbracketed '::1' parses hostless and so
            // matches nothing — the parents' shared law, pinned.
            'bare v6 unbracketed matches nothing' => ['::1', false],
            'suffix attack' => ['localhost.evil.com', false],
            'prefix attack' => ['evil-localhost', false],
            'foreign host' => ['example.com', false],
            'empty' => ['', false],
            'missing' => [null, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('localhostHostProvider')]
    public function test_localhostHost_exactNameAfterFold(?string $host, bool $expected): void
    {
        self::assertSame($expected, OriginPolicy::localhostHost($host));
    }

    /**
     * @return array<string, array{0: string, 1: string|null, 2: bool}>
     */
    public static function sameSiteOriginProvider(): array
    {
        return [
            'bare match' => ['https://example.com', 'example.com', true],
            'origin default vs explicit request port' => ['https://example.com', 'example.com:443', true],
            'explicit origin port vs default request' => ['https://example.com:443', 'example.com', true],
            'non-default port pair' => ['https://example.com:8443', 'example.com:8443', true],
            'port mismatch' => ['https://example.com', 'example.com:8443', false],
            'http default pair' => ['http://example.com', 'example.com', true],
            'http explicit 80 vs default' => ['http://example.com:80', 'example.com', true],
            'https origin on :80 host matches literally' => ['https://example.com:80', 'example.com:80', true],
            'host case-folded (LENIENT axis)' => ['https://example.com', 'EXAMPLE.COM', true],
            'both folded' => ['HTTP://EXAMPLE.COM', 'example.com', true],
            'scheme default port differs from request port' => ['https://localhost', 'localhost:3000', false],
            'loopback v6 bracketed pair' => ['http://[::1]:3000', '[::1]:3000', true],
            'origin bracketed vs host bare v6 folds equal' => ['https://[::1]:3000', '::1:3000', true],
            'device scheme app://' => ['app://example.com', 'example.com', false],
            'device scheme capacitor://' => ['capacitor://localhost', 'localhost', false],
            'file:// vault' => ['file://', 'example.com', false],
            'suffix attack' => ['https://evil-example.com', 'example.com', false],
            'empty origin' => ['', 'example.com', false],
            'empty host' => ['https://example.com', '', false],
            'unparseable origin' => ['ht tp://x', 'example.com', false],
            'schemeless origin' => ['//example.com', 'example.com', false],
            // Origin userinfo is invisible to the host limb (parse_url
            // extracts host only) — both parents' behavior, pinned.
            'userinfo origin matches host' => ['https://user:pass@example.com', 'example.com', true],
            'path suffix on origin ignored' => ['https://example.com/path', 'example.com', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sameSiteOriginProvider')]
    public function test_originIsSameSite_lenientIssuanceLaw(string $origin, ?string $host, bool $expected): void
    {
        self::assertSame($expected, OriginPolicy::originIsSameSite($origin, $host));
    }

    public function test_originIsSameSite_nullOriginIsAlwaysFalse(): void
    {
        // The parents' signature takes a non-null string; the kernel's
        // nullable arm documents null as "no origin" — never same-site.
        self::assertFalse(OriginPolicy::originIsSameSite(null, 'example.com'));
    }

    public function test_originIsSameSite_nullHostMatchesNothing(): void
    {
        foreach (['https://example.com', 'http://localhost:3000'] as $origin) {
            self::assertFalse(OriginPolicy::originIsSameSite($origin, null), $origin);
        }
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function stateChangingMethodProvider(): array
    {
        return [
            'POST' => ['POST', true],
            'PUT' => ['PUT', true],
            'DELETE' => ['DELETE', true],
            'PATCH' => ['PATCH', true],
            'lowercase post is not a verb the HTTP layer sends' => ['post', false],
            'GET' => ['GET', false],
            'HEAD' => ['HEAD', false],
            'OPTIONS' => ['OPTIONS', false],
            'empty' => ['', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stateChangingMethodProvider')]
    public function test_stateChangingMethod_closedExactCaseVerbList(string $method, bool $expected): void
    {
        self::assertSame($expected, OriginPolicy::stateChangingMethod($method));
    }

    /**
     * @return array<string, array{0: string|null, 1: bool|null}>
     */
    public static function secFetchBeltProvider(): array
    {
        return [
            'missing header → no signal' => [null, null],
            'empty → no signal' => ['', null],
            'cross-site → decided refuse' => ['cross-site', false],
            'padded case-folded Cross-Site → decided refuse' => [' Cross-Site ', false],
            'same-origin → no signal' => ['same-origin', null],
            'none → no signal' => ['none', null],
            'junk → no signal (belt rejects only the exact promise)' => ['junk', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('secFetchBeltProvider')]
    public function test_secFetchBelt_isTriState(?string $site, ?bool $expected): void
    {
        self::assertSame($expected, OriginPolicy::secFetchBelt($site));
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: string|null, 3: string|null, 4: bool}>
     */
    public static function strictOriginLawProvider(): array
    {
        return [
            'matching origin accepted' => ['https://hub.example', null, 'hub.example', null, true],
            'belt convicts despite matching origin (arm 1, belt-first order)' => ['https://hub.example', null, 'hub.example', 'cross-site', false],
            'belt normalizes case+padding' => ['https://hub.example', null, 'hub.example', ' CROSS-SITE', false],
            'same-origin belt leaves the verdict to the arms' => ['https://hub.example', null, 'hub.example', ' same-origin ', true],
            'none belt leaves the verdict to the arms' => ['https://hub.example', null, 'hub.example', 'none', true],
            // STRICT axis: raw case-sensitive host equality (M-SERVER-76
            // law) — contrast with the lenient axis pins above.
            'host case differs → refuse' => ['https://hub.example', null, 'HUB.Example', null, false],
            'origin case differs → refuse' => ['https://Hub.Example', null, 'hub.example', null, false],
            'explicit 443 vs bare host → both-or-neither port law' => ['https://hub.example:443', null, 'hub.example', null, false],
            'bare origin vs :443 host → refuse' => ['https://hub.example', null, 'hub.example:443', null, false],
            'matching explicit ports' => ['https://hub.example:8443', null, 'hub.example:8443', null, true],
            'opaque-origin null → refuse even with good referer (M-SERVER-76)' => ['null', 'https://hub.example/x', 'hub.example', null, false],
            'present-but-empty Origin skips its arm; no Referer reaches the accept tail (pinned quirk, both parents)' => ['', null, 'hub.example', null, true],
            'empty Origin with matching Referer accepted (ratified quirk)' => ['', 'https://hub.example/x', 'hub.example', null, true],
            'empty Origin with foreign Referer refused' => ['', 'https://other.example/x', 'hub.example', null, false],
            'no origin no referer → fail-closed' => [null, null, 'hub.example', null, false],
            'origin without host → refuse' => ['https://hub.example', null, null, null, false],
            'schemeless origin host parses pathless → refuse' => ['hub.example', null, 'hub.example', null, false],
            'unparseable origin → refuse' => ['ht tp://x', null, 'hub.example', null, false],
            'origin that parses hostless → refuse' => ['http://:80', null, 'hub.example', null, false],
            'suffix attack origin → refuse' => ['https://hub.example.evil', null, 'hub.example', null, false],
            'prefix attack origin → refuse' => ['https://evil-hub.example', null, 'hub.example', null, false],
            'referer-only arm matching accepted' => [null, 'https://hub.example/next', 'hub.example', null, true],
            'schemeless referer refused' => [null, 'hub.example/next', 'hub.example', null, false],
            'port-mismatched referer refused' => ['https://hub.example', 'https://hub.example:443/next', 'hub.example:443', null, false],
            'userinfo in strict origin still names the host (parse symmetry, pinned)' => ['https://user:pass@hub.example', null, 'hub.example', null, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('strictOriginLawProvider')]
    public function test_stateChangingRequestOriginAllowed(
        ?string $origin,
        ?string $referer,
        ?string $host,
        ?string $belt,
        bool $expected
    ): void {
        self::assertSame($expected, OriginPolicy::stateChangingRequestOriginAllowed($origin, $referer, $host, $belt));
    }

    public function test_theTwoAxesAreDeliberatelyNotCollapsible(): void
    {
        // The class law: ISSUANCE (lenient) and REQUEST (strict) must differ
        // on at least case and default-port normalization. If a future edit
        // ever makes these two verdicts agree here, the guard has been
        // silently loosened — exactly the M-SERVER-76 class of mistake.
        self::assertTrue(OriginPolicy::originIsSameSite('https://example.com', 'EXAMPLE.COM'));
        self::assertFalse(OriginPolicy::stateChangingRequestOriginAllowed('https://example.com', null, 'EXAMPLE.COM', null));
        self::assertTrue(OriginPolicy::originIsSameSite('https://example.com', 'example.com:443'));
        self::assertFalse(OriginPolicy::stateChangingRequestOriginAllowed('https://example.com', null, 'example.com:443', null));
    }

    /**
     * @return array<string, array{0: bool, 1: string|null, 2: string|null, 3: string|null, 4: string|null, 5: string}>
     */
    public static function custodyModeProvider(): array
    {
        return [
            'device marker wins over everything' => [true, 'rok', null, null, null, OriginPolicy::MODE_BEARER],
            'device marker wins over same-site origin' => [false, 'windows', 'https://hub.example', 'hub.example', null, OriginPolicy::MODE_BEARER],
            'foreign origin → device class' => [false, null, 'https://evil.example', 'hub.example', null, OriginPolicy::MODE_BEARER],
            'device webview scheme → device class' => [false, null, 'app://hub.example', 'hub.example', null, OriginPolicy::MODE_BEARER],
            'same-site origin → browser class' => [false, null, 'https://hub.example', 'hub.example', null, OriginPolicy::MODE_COOKIE],
            'case-folded same-site origin classifies cookie-side (lenient axis)' => [false, null, 'https://Hub.Example', 'hub.example', null, OriginPolicy::MODE_COOKIE],
            'no signals at all → bearer (legacy JSON posture)' => [false, null, null, null, null, OriginPolicy::MODE_BEARER],
            'empty origin alone is no signal' => [false, null, '', null, null, OriginPolicy::MODE_BEARER],
            'sec-fetch same-origin alone → browser class' => [false, null, null, null, 'same-origin', OriginPolicy::MODE_COOKIE],
            'sec-fetch cross-site alone is no browser signal' => [false, null, null, null, 'cross-site', OriginPolicy::MODE_BEARER],
            // Server-only form-era limb: the hub delegates false and its
            // verdicts are reproduced exactly (divergence #1, lossless —
            // the sweep row 'no signals at all' is the same input pair
            // giving cookie under the server's form path and bearer for
            // the hub, pinned both ways below).
            'form-era browser with no headers → cookie (server limb)' => [true, null, null, null, null, OriginPolicy::MODE_COOKIE],
            'form-era false with no headers → bearer (hub limb equivalence)' => [false, null, null, null, null, OriginPolicy::MODE_BEARER],
            'belt does not feed custody (classification axis)' => [false, null, 'https://hub.example', 'hub.example', 'cross-site', OriginPolicy::MODE_COOKIE],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('custodyModeProvider')]
    public function test_custodyMode_classifiesCustodyFromHeaders(
        bool $formEraBrowser,
        ?string $device,
        ?string $origin,
        ?string $host,
        ?string $belt,
        string $expected
    ): void {
        self::assertSame($expected, OriginPolicy::custodyMode($formEraBrowser, $device, $origin, $host, $belt));
    }

    public function test_modeConstants_mirrorContractsCookieMode(): void
    {
        self::assertSame('cookie', OriginPolicy::MODE_COOKIE);
        self::assertSame('bearer', OriginPolicy::MODE_BEARER);
    }
}
