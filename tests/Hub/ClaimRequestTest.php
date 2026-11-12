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

    public function test_hostnameCandidates_not_array_throws(): void
    {
        $payload = self::full();
        $payload['hostnameCandidates'] = 'not-an-array';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ClaimRequest "hostnameCandidates" must be a list of strings.');
        ClaimRequest::fromPayload($payload);
    }

    /**
     * @dataProvider provideEmptyRequiredStrings
     */
    public function test_empty_required_string_throws(string $field): void
    {
        $payload = self::full();
        $payload[$field] = '';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ClaimRequest "' . $field . '" must be a non-empty string.');
        ClaimRequest::fromPayload($payload);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideEmptyRequiredStrings(): array
    {
        return [
            'serverName' => ['serverName'],
            'version' => ['version'],
            'protocolVersion' => ['protocolVersion'],
        ];
    }

    public function test_server_url_shaped_hostnameCandidates_are_accepted(): void
    {
        // The live server advertises scheme://host:port URLs (HubClient::
        // getHostnameCandidates + PortForwardService) — the grammar gate must
        // keep decoding them.
        $candidates = [
            'https://media.example.com',
            'http://192.168.1.10:8096',
            'http://phlix.local:8096',
            'http://[fe80::1]:8096',
            'nas.lan',
        ];
        $payload = self::full();
        $payload['hostnameCandidates'] = $candidates;

        $dto = ClaimRequest::fromPayload($payload);
        $this->assertSame($candidates, $dto->hostnameCandidates);
    }

    /**
     * Same grammar as HeartbeatDto (the full matrix lives in
     * HeartbeatDtoTest); pinned here so the claim path cannot drift.
     *
     * @dataProvider provideInvalidHostnameCandidates
     */
    public function test_invalid_hostnameCandidate_throws(string $candidate): void
    {
        $payload = self::full();
        $payload['hostnameCandidates'] = [$candidate];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'ClaimRequest "hostnameCandidates" entry 0 must be a hostname, IP literal, or http(s) URL.',
        );
        ClaimRequest::fromPayload($payload);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvalidHostnameCandidates(): array
    {
        return [
            'empty' => [''],
            'underscore' => ['my_host.lan'],
            'pseudo scheme' => ['javascript:alert(1)'],
            'userinfo' => ['http://user:pw@nas.lan'],
            'ftp scheme' => ['ftp://nas.lan'],
            'embedded space' => ['nas lan'],
        ];
    }

    public function test_publicKeysJwk_key_type_is_delegated_to_the_consumer(): void
    {
        // Adjudicated delegated contract (see class docblock): the DTO
        // gates the JWK's container shape only; key-type/curve/material
        // policy belongs to the consumer that verifies with it (phlix-hub
        // ClaimRequestHandler::validateJwkStructure, OKP/Ed25519 only).
        $payload = self::full();
        $payload['publicKeysJwk'] = ['kty' => 'oct', 'k' => 'c2VjcmV0'];

        $dto = ClaimRequest::fromPayload($payload);
        $this->assertSame(['kty' => 'oct', 'k' => 'c2VjcmV0'], $dto->publicKeysJwk);
    }
}
