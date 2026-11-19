<?php

/**
 * HostGrammarPromotionTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Hub;

use Phlix\Shared\Hub\HostGrammar;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * v0.57 leg 0.13 (M-34): `Hub\HostGrammar` is promoted from a shared-package
 * implementation detail to a public-stable surface. This suite is the
 * executable stamp of that promotion — it reddens if the marker ever comes
 * back or the public grammar API is reshaped out from under a documented
 * surface. No behaviour changes in this leg; only the docblock flips.
 */
#[CoversClass(HostGrammar::class)]
final class HostGrammarPromotionTest extends TestCase
{
    private const PUBLIC_API = [
        'parseCandidates',
        'isValidCandidate',
        'isValidHttpUrl',
        'isValidHost',
    ];

    public function testPromotedSurfaceCarriesNoInternalMarker(): void
    {
        $docblock = (new ReflectionClass(HostGrammar::class))->getDocComment();

        self::assertIsString($docblock, 'HostGrammar must keep a class docblock to carry the promotion stamp.');
        self::assertStringNotContainsString(
            '@internal',
            $docblock,
            'v0.57 leg 0.13 (M-34) promotes HostGrammar to a public-stable surface; '
            . 'an @internal marker here contradicts the promotion.'
        );
    }

    public function testPromotionStampNamesTheLeg(): void
    {
        $docblock = (new ReflectionClass(HostGrammar::class))->getDocComment();

        self::assertIsString($docblock);
        self::assertStringContainsString('v0.57 leg 0.13', $docblock);
    }

    public function testSurfaceRemainsFinal(): void
    {
        self::assertTrue((new ReflectionClass(HostGrammar::class))->isFinal());
    }

    public function testPublicGrammarApiIsIntact(): void
    {
        $reflection = new ReflectionClass(HostGrammar::class);

        foreach (self::PUBLIC_API as $name) {
            $method = $reflection->getMethod($name);

            self::assertTrue($method->isPublic(), $name . ' must stay public on a promoted surface.');
            self::assertTrue($method->isStatic(), $name . ' must stay a static grammar kernel.');

            $returnType = $method->getReturnType();
            self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        }
    }
}
