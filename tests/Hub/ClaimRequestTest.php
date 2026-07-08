<?php

/**
 * Claim Request Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Hub;

use InvalidArgumentException;
use Phlix\Shared\Hub\ClaimRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Hub\ClaimRequest
 */
final class ClaimRequestTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function full(): array
    {
        return [
            'serverName' => "Alice's NAS",
            'version' => '0.11.0',
            'publicKeysJwk' => ['keys' => []],
            'hostnameCandidates' => ['10.0.0.5', 'phlix.local'],
            'protocolVersion' => 'v1',
        ];
    }

    public function test_round_trip_preserves_payload(): void
    {
        $dto = ClaimRequest::fromPayload(self::full());
        $this->assertSame(self::full(), $dto->toPayload());
    }

    public function test_missing_serverName_throws(): void
    {
        $payload = self::full();
        unset($payload['serverName']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ClaimRequest "serverName" is required.');
        ClaimRequest::fromPayload($payload);
    }

    public function test_missing_publicKeysJwk_throws(): void
    {
        $payload = self::full();
        unset($payload['publicKeysJwk']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ClaimRequest "publicKeysJwk"');
        ClaimRequest::fromPayload($payload);
    }

    public function test_non_string_hostnameCandidates_entry_throws(): void
    {
        $payload = self::full();
        $payload['hostnameCandidates'] = ['ok', 42];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('hostnameCandidates');
        ClaimRequest::fromPayload($payload);
    }

    public function test_missing_hostnameCandidates_defaults_to_empty(): void
    {
        $payload = self::full();
        unset($payload['hostnameCandidates']);

        $dto = ClaimRequest::fromPayload($payload);
        $this->assertSame([], $dto->hostnameCandidates);
    }
}
