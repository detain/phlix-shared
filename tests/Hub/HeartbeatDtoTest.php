<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Hub;

use InvalidArgumentException;
use Phlix\Shared\Hub\HeartbeatDto;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Hub\HeartbeatDto
 */
final class HeartbeatDtoTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function full(): array
    {
        return [
            'serverId' => 'srv-uuid',
            'version' => '0.11.0',
            'timestamp' => 1700000000,
            'uptimeSeconds' => 3600,
            'activeSessions' => 2,
            'activeTranscodes' => 1,
            'hostnameCandidates' => ['10.0.0.5'],
            'libraries' => [['library_id' => 'lib-1', 'library_name' => 'Movies']],
        ];
    }

    public function test_round_trip(): void
    {
        $dto = HeartbeatDto::fromPayload(self::full());
        $this->assertSame(self::full(), $dto->toPayload());
    }

    public function test_library_round_trip(): void
    {
        $payload = self::full();
        $dto = HeartbeatDto::fromPayload($payload);

        $output = $dto->toPayload();
        $this->assertIsArray($output['libraries']);
        $this->assertCount(1, $output['libraries']);
        $this->assertSame('lib-1', $output['libraries'][0]['library_id']);
        $this->assertSame('Movies', $output['libraries'][0]['library_name']);
    }

    public function test_strict_libraries_throws_on_missing_library_id(): void
    {
        $payload = self::full();
        $payload['libraries'] = [['library_name' => 'Movies']];

        $this->expectException(InvalidArgumentException::class);
        HeartbeatDto::fromPayload($payload, strictLibraries: true);
    }

    public function test_strict_libraries_throws_on_empty_library_id(): void
    {
        $payload = self::full();
        $payload['libraries'] = [['library_id' => '', 'library_name' => 'Movies']];

        $this->expectException(InvalidArgumentException::class);
        HeartbeatDto::fromPayload($payload, strictLibraries: true);
    }

    public function test_strict_libraries_throws_on_missing_library_name(): void
    {
        $payload = self::full();
        $payload['libraries'] = [['library_id' => 'lib-1']];

        $this->expectException(InvalidArgumentException::class);
        HeartbeatDto::fromPayload($payload, strictLibraries: true);
    }

    public function test_strict_libraries_throws_on_empty_library_name(): void
    {
        $payload = self::full();
        $payload['libraries'] = [['library_id' => 'lib-1', 'library_name' => '']];

        $this->expectException(InvalidArgumentException::class);
        HeartbeatDto::fromPayload($payload, strictLibraries: true);
    }

    public function test_lenient_libraries_skips_missing_library_id(): void
    {
        $payload = self::full();
        $payload['libraries'] = [
            ['library_id' => 'lib-1', 'library_name' => 'Movies'],
            ['library_name' => 'TV Shows'],
        ];

        $dto = HeartbeatDto::fromPayload($payload, strictLibraries: false);
        $output = $dto->toPayload();

        /** @var list<array{library_id: string, library_name: string}> $libraries */
        $libraries = $output['libraries'];
        $this->assertCount(1, $libraries);
        $this->assertSame('lib-1', $libraries[0]['library_id']);
    }

    public function test_lenient_libraries_skips_missing_library_name(): void
    {
        $payload = self::full();
        $payload['libraries'] = [
            ['library_id' => 'lib-1', 'library_name' => 'Movies'],
            ['library_id' => 'lib-2'],
        ];

        $dto = HeartbeatDto::fromPayload($payload, strictLibraries: false);
        $output = $dto->toPayload();

        /** @var list<array{library_id: string, library_name: string}> $libraries */
        $libraries = $output['libraries'];
        $this->assertCount(1, $libraries);
        $this->assertSame('lib-1', $libraries[0]['library_id']);
    }

    public function test_lenient_libraries_skips_non_array_entry(): void
    {
        $payload = self::full();
        $payload['libraries'] = [
            ['library_id' => 'lib-1', 'library_name' => 'Movies'],
            'not-an-array',
        ];

        $dto = HeartbeatDto::fromPayload($payload, strictLibraries: false);
        $output = $dto->toPayload();

        /** @var list<array{library_id: string, library_name: string}> $libraries */
        $libraries = $output['libraries'];
        $this->assertCount(1, $libraries);
        $this->assertSame('lib-1', $libraries[0]['library_id']);
    }

    public function test_strict_libraries_throws_on_non_array_entry(): void
    {
        $payload = self::full();
        $payload['libraries'] = [
            ['library_id' => 'lib-1', 'library_name' => 'Movies'],
            'not-an-array',
        ];

        $this->expectException(InvalidArgumentException::class);
        HeartbeatDto::fromPayload($payload, strictLibraries: true);
    }

    public function test_missing_serverId_throws(): void
    {
        $payload = self::full();
        unset($payload['serverId']);

        $this->expectException(InvalidArgumentException::class);
        HeartbeatDto::fromPayload($payload);
    }

    public function test_non_int_uptime_throws(): void
    {
        $payload = self::full();
        $payload['uptimeSeconds'] = 'oops';

        $this->expectException(InvalidArgumentException::class);
        HeartbeatDto::fromPayload($payload);
    }
}
