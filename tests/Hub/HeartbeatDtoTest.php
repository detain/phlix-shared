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
        ];
    }

    public function test_round_trip(): void
    {
        $dto = HeartbeatDto::fromPayload(self::full());
        $this->assertSame(self::full(), $dto->toPayload());
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
