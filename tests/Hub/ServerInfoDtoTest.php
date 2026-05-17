<?php

declare(strict_types=1);

namespace Phlex\Shared\Tests\Hub;

use InvalidArgumentException;
use Phlex\Shared\Hub\ServerInfoDto;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlex\Shared\Hub\ServerInfoDto
 */
final class ServerInfoDtoTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function full(): array
    {
        return [
            'serverId' => 'srv-uuid',
            'userId' => 'usr-uuid',
            'serverName' => "Alice's NAS",
            'version' => '0.11.0',
            'lastSeenAt' => 1700000000,
            'status' => ServerInfoDto::STATUS_ONLINE,
            'hostnameCandidates' => ['10.0.0.5'],
            'relayActive' => true,
        ];
    }

    public function test_round_trip(): void
    {
        $dto = ServerInfoDto::fromPayload(self::full());
        $this->assertSame(self::full(), $dto->toPayload());
    }

    public function test_lastSeenAt_null_round_trip(): void
    {
        $payload = self::full();
        $payload['lastSeenAt'] = null;
        $dto = ServerInfoDto::fromPayload($payload);
        $this->assertNull($dto->lastSeenAt);
    }

    public function test_non_int_lastSeenAt_throws(): void
    {
        $payload = self::full();
        $payload['lastSeenAt'] = 'oops';

        $this->expectException(InvalidArgumentException::class);
        ServerInfoDto::fromPayload($payload);
    }

    public function test_missing_relayActive_throws(): void
    {
        $payload = self::full();
        unset($payload['relayActive']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relayActive');
        ServerInfoDto::fromPayload($payload);
    }

    public function test_non_string_hostnameCandidates_entry_throws(): void
    {
        $payload = self::full();
        $payload['hostnameCandidates'] = ['ok', 42];

        $this->expectException(InvalidArgumentException::class);
        ServerInfoDto::fromPayload($payload);
    }
}
