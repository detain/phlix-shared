<?php

/**
 * Server Info Dto Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Hub;

use InvalidArgumentException;
use Phlix\Shared\Hub\ServerInfoDto;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Hub\ServerInfoDto
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
            'libraryCount' => 7,
            'subdomain' => null,
        ];
    }

    public function test_round_trip(): void
    {
        $dto = ServerInfoDto::fromPayload(self::full());
        $this->assertSame(self::full(), $dto->toPayload());
        $this->assertSame(7, $dto->libraryCount);
    }

    public function test_libraryCount_absent_defaults_to_null(): void
    {
        $payload = self::full();
        unset($payload['libraryCount']);
        $dto = ServerInfoDto::fromPayload($payload);
        $this->assertNull($dto->libraryCount);
        $this->assertNull($dto->toPayload()['libraryCount']);
    }

    public function test_non_int_libraryCount_throws(): void
    {
        $payload = self::full();
        $payload['libraryCount'] = 'lots';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('libraryCount');
        ServerInfoDto::fromPayload($payload);
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

    public function test_subdomain_with_value_round_trip(): void
    {
        $payload = self::full();
        $payload['subdomain'] = 'my-server';
        $dto = ServerInfoDto::fromPayload($payload);
        $this->assertSame('my-server', $dto->subdomain);
        $this->assertSame('my-server', $dto->toPayload()['subdomain']);
    }

    public function test_subdomain_absent_defaults_to_null(): void
    {
        $payload = self::full();
        unset($payload['subdomain']);
        $dto = ServerInfoDto::fromPayload($payload);
        $this->assertNull($dto->subdomain);
        $this->assertNull($dto->toPayload()['subdomain']);
    }

    public function test_non_string_subdomain_throws(): void
    {
        $payload = self::full();
        $payload['subdomain'] = 42;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('subdomain');
        ServerInfoDto::fromPayload($payload);
    }

    /**
     * @dataProvider provideMissingRequiredFields
     */
    public function test_missing_required_field_throws(string $field): void
    {
        $payload = self::full();
        unset($payload[$field]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($field);
        ServerInfoDto::fromPayload($payload);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideMissingRequiredFields(): array
    {
        return [
            'serverId' => ['serverId'],
            'userId' => ['userId'],
            'serverName' => ['serverName'],
            'version' => ['version'],
            'status' => ['status'],
        ];
    }

    /**
     * @dataProvider provideInvalidRequiredFieldTypes
     */
    public function test_invalid_required_field_type_throws(string $field, mixed $invalidValue): void
    {
        $payload = self::full();
        $payload[$field] = $invalidValue;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($field);
        ServerInfoDto::fromPayload($payload);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function provideInvalidRequiredFieldTypes(): array
    {
        return [
            'serverId as int' => ['serverId', 123],
            'serverId as array' => ['serverId', ['array']],
            'userId as int' => ['userId', 123],
            'userId as array' => ['userId', ['array']],
            'serverName as int' => ['serverName', 123],
            'serverName as array' => ['serverName', ['array']],
            'version as int' => ['version', 123],
            'version as array' => ['version', ['array']],
            'status as int' => ['status', 200],
            'status as array' => ['status', ['array']],
        ];
    }

    public function test_status_constants_are_defined(): void
    {
        $this->assertSame('online', ServerInfoDto::STATUS_ONLINE);
        $this->assertSame('offline', ServerInfoDto::STATUS_OFFLINE);
        $this->assertSame('claiming', ServerInfoDto::STATUS_CLAIMING);
        $this->assertSame('disabled', ServerInfoDto::STATUS_DISABLED);
    }

    public function test_hostnameCandidates_not_array_throws(): void
    {
        $payload = self::full();
        $payload['hostnameCandidates'] = 42;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ServerInfoDto "hostnameCandidates" must be a list of strings.');
        ServerInfoDto::fromPayload($payload);
    }
}
