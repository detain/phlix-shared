<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Http;

use Phlix\Shared\Http\ReasonPhrases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * ReasonPhrases Test.
 *
 * Characterization pins for v0.57 leg 0.10 M-17. Canonical source:
 * phlix-hub `src/Http/BodylessResponse.php:114-119` (A-12 strict-empty law:
 * `''` falls back exactly like an unset reason; an explicit `'0'` SURVIVES).
 * The server twin at `src/Server/Workerman/BodylessResponse.php:109` uses
 * `?:` — the named bug; consumer migration to this kernel IS the disclosed
 * fix, so a red-first pin here must lock the HUB arms.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
#[CoversClass(ReasonPhrases::class)]
final class ReasonPhrasesTest extends TestCase
{
    public function testUnsetReasonFallsBackToTheTable(): void
    {
        self::assertSame('Not Found', ReasonPhrases::resolve(null, 404));
        self::assertSame('Too Many Requests', ReasonPhrases::resolve(null, 429));
        self::assertSame('Internal Server Error', ReasonPhrases::resolve(null, 500));
    }

    public function testEmptyStringReasonFallsBackLikeAnUnsetOne(): void
    {
        // The hub's documented strict-empty arm ('' must NOT render as empty).
        self::assertSame('OK', ReasonPhrases::resolve('', 200));
        self::assertSame('Service Unavailable', ReasonPhrases::resolve('', 503));
    }

    public function testExplicitZeroReasonSurvives(): void
    {
        // The named-bug fix pin: `?:` (server twin) would replace this with
        // the table phrase; the hub law keeps the caller's literal.
        self::assertSame('0', ReasonPhrases::resolve('0', 200));
    }

    public function testCustomReasonAlwaysWins(): void
    {
        self::assertSame('Weird Reason', ReasonPhrases::resolve('Weird Reason', 499));
    }

    public function testUnknownStatusWithoutReasonResolvesToEmptyPhrase(): void
    {
        self::assertSame('', ReasonPhrases::resolve(null, 599));
        self::assertSame('', ReasonPhrases::resolve('', 723));
    }

    public function testPhrasesTableCarriesTheWireRelevantEntries(): void
    {
        self::assertSame('Too Many Requests', ReasonPhrases::PHRASES[429]);
        self::assertSame('Service Unavailable', ReasonPhrases::PHRASES[503]);
        self::assertSame('OK', ReasonPhrases::PHRASES[200]);
        self::assertSame('Not Found', ReasonPhrases::PHRASES[404]);
        self::assertSame('Bad Request', ReasonPhrases::PHRASES[400]);
        self::assertSame('Unauthorized', ReasonPhrases::PHRASES[401]);
        self::assertSame('Forbidden', ReasonPhrases::PHRASES[403]);
        self::assertSame('I\'m a teapot', ReasonPhrases::PHRASES[418]);
    }

    public function testTableShapeCensusValuesAreNeverEmpty(): void
    {
        // The typed const carries the key/value types; the observable law to
        // pin is the vendor-copy census: non-trivial map, no empty phrases,
        // and the exact 63-entry size of the vendored Workerman PHRASES map
        // at the provenance tip we copied from (r1 cure: keep the count in
        // the pin, not in prose — a donor-map drift must redden consciously).
        foreach (ReasonPhrases::PHRASES as $status => $phrase) {
            self::assertNotSame('', $phrase, (string) $status);
        }
        self::assertCount(63, ReasonPhrases::PHRASES);
    }

    public function testLegacyElvisGrammarCharacterizedAsTheBugThisKernelFixes(): void
    {
        // Executed contrast (M-17 divergence pin, characterization stamp):
        // the OLD server grammar resolved the reason as
        // `$reason ?: $defaultPhrase`, and PHP's elvis swallows the
        // string '0' — a caller that legitimately said '0' got the table
        // phrase instead. The old arm is emulated inline here so the delta
        // is measured, not asserted; the shared kernel's strict-empty law
        // (ReasonPhrases::resolve) keeps '0' verbatim. The emulation is a
        // parameterised closure so the elvis arm runs on real inputs.
        $legacyGrammar = static fn(string $reason): string => $reason ?: 'Internal Server Error';
        self::assertSame('Internal Server Error', $legacyGrammar('0'), 'Elvis must swallow "0" — the bug class.');

        self::assertSame('0', ReasonPhrases::resolve('0', 500), 'Shared kernel keeps the explicit "0".');
    }
}
