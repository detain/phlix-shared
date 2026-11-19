<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Http;

use Phlix\Shared\Http\StaticPathPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * StaticPathPolicy Test.
 *
 * Characterization pins for v0.57 leg 0.10 M-18 (A-21). The deny list and
 * predicate are the estate's one static-deny vocabulary, mirrored from
 * phlix-server `src/Server/Workerman/HttpHandler.php` (`DENIED_STATIC_EXTENSIONS`
 * :107, inlined strict predicate :717, R20-03) and phlix-hub
 * `src/Application.php` (typed const :154 declared byte-coordinated with the
 * server, public static `isDeniedStaticPath()` :176-183, M-HUB/85c06de1).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
#[CoversClass(StaticPathPolicy::class)]
final class StaticPathPolicyTest extends TestCase
{
    public function testDeniedListIsTheByteCoordinatedTriple(): void
    {
        // A-21: one deny vocabulary on both HTTP daemons — php raw source,
        // map = Vite sourcesContent, tpl = Smarty email template sources.
        $denied = StaticPathPolicy::DENIED_STATIC_EXTENSIONS;

        // Membership + shape (the @var list<string> typing covers order).
        sort($denied);
        self::assertSame(['map', 'php', 'tpl'], $denied);
    }

    public function testExtensionOfReturnsRawExtension(): void
    {
        self::assertSame('php', StaticPathPolicy::extensionOf('/srv/public/index.php'));
        self::assertSame('PHP', StaticPathPolicy::extensionOf('/srv/public/A.PHP'));
        self::assertSame('map', StaticPathPolicy::extensionOf('/srv/public/assets/app/index-DaB12cd3.js.map'));
        self::assertSame('js', StaticPathPolicy::extensionOf('/srv/public/assets/app/index-DaB12cd3.js'));
        self::assertSame('', StaticPathPolicy::extensionOf('/srv/public/README'));
        self::assertSame('', StaticPathPolicy::extensionOf(''));
    }

    public function testDeniesEachVocabularyExtension(): void
    {
        self::assertTrue(StaticPathPolicy::isDeniedStaticPath('/public/index.php'));
        self::assertTrue(StaticPathPolicy::isDeniedStaticPath('/public/assets/app/index-DaB12cd3.js.map'));
        self::assertTrue(StaticPathPolicy::isDeniedStaticPath('/public/templates/emails/newsletter.tpl'));
        self::assertTrue(StaticPathPolicy::isDeniedStaticPath('/public/assets/app/bundle.js.MaP'));
    }

    public function testDenialIsCaseInsensitive(): void
    {
        // strtolower law on both twins: a case trick cannot smuggle a source.
        self::assertTrue(StaticPathPolicy::isDeniedStaticPath('/public/A.PHP'));
        self::assertTrue(StaticPathPolicy::isDeniedStaticPath('/public/templates/EMAILS/Newsletter.TPL'));
    }

    public function testAllowsOrdinaryClientAssets(): void
    {
        self::assertFalse(StaticPathPolicy::isDeniedStaticPath('/public/index.html'));
        self::assertFalse(StaticPathPolicy::isDeniedStaticPath('/public/assets/app/index-DaB12cd3.js'));
        self::assertFalse(StaticPathPolicy::isDeniedStaticPath('/public/robots.txt'));
        self::assertFalse(StaticPathPolicy::isDeniedStaticPath('/public/favicon.png'));
    }

    public function testPathlessAndEmptyShapesAreNeverDenied(): void
    {
        // extensionOf('') === '' — empty is not a vocabulary member.
        self::assertFalse(StaticPathPolicy::isDeniedStaticPath(''));
        self::assertFalse(StaticPathPolicy::isDeniedStaticPath('/srv/public/README'));
    }

    public function testPredicateMatchesTheServerInlineForm(): void
    {
        // Differential pin against the literal server expression (R20-03 was
        // inlined there): same answer on a corpus of shapes.
        foreach (['/a.php', '/a.PHP', '/a.js.map', '/a.tpl', '/a.js', '/a', ''] as $path) {
            $legacy = in_array(
                strtolower((string) pathinfo($path, PATHINFO_EXTENSION)),
                ['php', 'map', 'tpl'],
                true,
            );
            self::assertSame($legacy, StaticPathPolicy::isDeniedStaticPath($path), $path);
        }
    }
}
