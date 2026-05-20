<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Plugin;

use Phlix\Shared\Plugin\ManifestType;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Plugin\ManifestType
 */
final class ManifestTypeTest extends TestCase
{
    public function test_has_eleven_cases(): void
    {
        $this->assertCount(11, ManifestType::cases());
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

    /**
     * @dataProvider caseProvider
     */
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
