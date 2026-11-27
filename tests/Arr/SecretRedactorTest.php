<?php

/**
 * Secret Redactor Alias Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Arr;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Phlix\Shared\Security\SecretRedactor;
use ReflectionClass;

/**
 * Alias-era arms for the legacy `Phlix\Shared\Arr\SecretRedactor` name.
 *
 * The behavior pins for the redaction kernel itself live in the canonical
 * {@see \Phlix\Shared\Tests\Security\SecretRedactorTest}; this file proves
 * only what S-shared-10 (phlix-shared-010) promised backwards-compatibility
 * consumers: the old FQCN still exists, resolves to the canonical class, and
 * routes calls byte-identically through the alias. The string-literal class
 * references are deliberate — after the promotion the OLD name is created by
 * `class_alias`, so it must be addressed by string, not by static import.
 *
 * @package Phlix\Shared\Tests\Arr
 * @since 0.11.0
 */
#[CoversClass(SecretRedactor::class)]
class SecretRedactorTest extends TestCase
{
    /**
     * The legacy FQCN as it appeared to callers before the S-shared-10 move.
     *
     * Only reachable as a string: the Arr-side name exists via a class_alias()
     * registration, so PHPStan sees no symbol at that address and would bind
     * any class-string<...> tag (or literal-folded read) to a non-existent
     * class. Reflection consumers therefore read it through legacyName(),
     * whose declared `string` return keeps it opaque until class_exists()
     * narrows it back to class-string at the use site.
     */
    private const LEGACY_NAME = 'Phlix\Shared\Arr\SecretRedactor';

    private function legacyName(): string
    {
        return self::LEGACY_NAME;
    }

    public function testTheLegacyArrNameStillExists(): void
    {
        $this->assertTrue(
            class_exists(self::LEGACY_NAME),
            'S-shared-10 promised Arr\SecretRedactor remains importable via class_alias.'
        );
    }

    public function testTheLegacyNameResolvesToTheCanonicalClass(): void
    {
        $legacy = $this->legacyName();
        if (!class_exists($legacy)) {
            $this->fail('The legacy FQCN must exist via the S-shared-10 class_alias shim.');
        }

        $this->assertSame(
            SecretRedactor::class,
            (new ReflectionClass($legacy))->getName(),
            'The alias must name the canonical Security kernel, not a duplicate.'
        );
    }

    public function testRedactRoutesThroughTheLegacyNameByteIdentically(): void
    {
        /** @var class-string<SecretRedactor> $legacy */
        $legacy = $this->legacyName();

        $message = 'Connection failed: API key abc123secret was rejected';
        $this->assertSame(
            'Connection failed: API key *** was rejected',
            $legacy::redact($message, 'abc123secret')
        );

        // Longest-first law reachable through the alias exactly as before.
        $this->assertSame(
            'Error with key *** and token *** for ***',
            $legacy::redact(
                'Error with key SECRETKEY and token abc123secret for KEY',
                'abc123secret',
                'KEY',
                'SECRETKEY'
            )
        );
    }
}
