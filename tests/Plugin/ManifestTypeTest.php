<?php

/**
 * Manifest Type Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Plugin;

use Phlix\Shared\Plugin\ManifestType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Phlix\Shared\Plugin\ManifestType::class)]
final class ManifestTypeTest extends TestCase
{
    public function test_has_eleven_cases(): void
    {
        $this->assertCount(11, ManifestType::cases());
    }

    /**
     * SHR-S5: the case values mirror, in order, the `type` enum of the
     * bundled schemas/manifest.schema.json (the canonical schema;
     * phlix-server's ManifestSchema validates against it).
     */
    public function test_cases_mirror_the_bundled_schema_type_enum(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/schemas/manifest.schema.json');
        $this->assertIsString($source);
        $schema = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($schema);
        /** @var array{properties: array{type: array{enum?: mixed}}} $schema */
        $typeEnum = $schema['properties']['type']['enum'] ?? null;
        $this->assertIsArray($typeEnum);

        $this->assertSame(
            $typeEnum,
            array_map(static fn (ManifestType $case): string => $case->value, ManifestType::cases())
        );
    }

    /**
     * @return array<string, array{0: string, 1: ManifestType}>
     */
    public static function caseProvider(): array
    {
        return [
            'metadata-provider'   => ['metadata-provider', ManifestType::MetadataProvider],
            'subtitle-provider'   => ['subtitle-provider', ManifestType::SubtitleProvider],
            'auth-provider'       => ['auth-provider', ManifestType::AuthProvider],
            'library-type'        => ['library-type', ManifestType::LibraryType],
            'notifier'            => ['notifier', ManifestType::Notifier],
            'scrobbler'           => ['scrobbler', ManifestType::Scrobbler],
            'tuner'               => ['tuner', ManifestType::Tuner],
            'transcoder-hook'     => ['transcoder-hook', ManifestType::TranscoderHook],
            'ui-theme'            => ['ui-theme', ManifestType::UiTheme],
            'arr-integration'     => ['arr-integration', ManifestType::ArrIntegration],
            'analytics-sink'      => ['analytics-sink', ManifestType::AnalyticsSink],
        ];
    }

    #[DataProvider('caseProvider')]
    public function test_value_round_trip(string $value, ManifestType $expected): void
    {
        $this->assertSame($value, $expected->value);
        $this->assertSame($expected, ManifestType::from($value));
    }

    public function test_tryFrom_returns_null_for_unknown_value(): void
    {
        $this->assertNull(ManifestType::tryFrom('not-a-real-type'));
    }
}
