<?php

/**
 * Heartbeat Dto Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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

    public function test_hostnameCandidates_not_array_throws(): void
    {
        $payload = self::full();
        $payload['hostnameCandidates'] = 'not-an-array';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HeartbeatDto "hostnameCandidates" must be a list of strings.');
        HeartbeatDto::fromPayload($payload);
    }

    public function test_hostnameCandidates_entry_not_string_throws(): void
    {
        $payload = self::full();
        $payload['hostnameCandidates'] = ['valid', 42, 'also-valid'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HeartbeatDto "hostnameCandidates" must contain only strings.');
        HeartbeatDto::fromPayload($payload);
    }

    public function test_libraries_not_array_throws(): void
    {
        $payload = self::full();
        $payload['libraries'] = 'not-an-array';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HeartbeatDto "libraries" must be a list of objects.');
        HeartbeatDto::fromPayload($payload, strictLibraries: false);
    }

    /**
     * @dataProvider provideEmptyIdentityFields
     */
    public function test_empty_identity_field_throws(string $field): void
    {
        $payload = self::full();
        $payload[$field] = '';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HeartbeatDto "' . $field . '" must be a non-empty string.');
        HeartbeatDto::fromPayload($payload);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideEmptyIdentityFields(): array
    {
        return [
            'serverId' => ['serverId'],
            'version' => ['version'],
        ];
    }

    /**
     * Every shape the estate actually advertises must keep decoding — the
     * server sends `scheme://host:port` URLs (HubClient /
     * PortForwardService), operators and fixtures send bare hosts/IPs.
     *
     * @dataProvider provideValidHostnameCandidates
     */
    public function test_valid_hostnameCandidate_is_accepted(string $candidate): void
    {
        $payload = self::full();
        $payload['hostnameCandidates'] = [$candidate];

        $dto = HeartbeatDto::fromPayload($payload);
        $this->assertSame([$candidate], $dto->hostnameCandidates);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideValidHostnameCandidates(): array
    {
        return [
            'ipv4' => ['10.0.0.5'],
            'ipv4 with port' => ['192.168.1.10:8096'],
            'single-label host' => ['nas'],
            'mdns host' => ['phlix.local'],
            'fqdn with port' => ['nas.lan:8096'],
            'digit-leading label' => ['1password.example.com'],
            'punycode label' => ['xn--bcher-kva.example'],
            'max-length label' => [str_repeat('a', 63) . '.example'],
            'bare ipv6' => ['fe80::1'],
            'bare ipv6 loopback' => ['::1'],
            'bracketed ipv6' => ['[::1]'],
            'bracketed ipv6 with port' => ['[2001:db8::1]:8096'],
            'http url ipv4 port' => ['http://192.168.1.10:8096'],
            'http url mdns port' => ['http://phlix.local:8096'],
            'https url' => ['https://media.example.com'],
            'https url with path' => ['https://media.example.com/phlix/'],
            'https url ipv6' => ['https://[2001:db8::1]:8920'],
            'uppercase scheme and host' => ['HTTP://Media.Example.COM'],
        ];
    }

    /**
     * @dataProvider provideInvalidHostnameCandidates
     */
    public function test_invalid_hostnameCandidate_throws(string $candidate): void
    {
        $payload = self::full();
        $payload['hostnameCandidates'] = ['10.0.0.5', $candidate];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'HeartbeatDto "hostnameCandidates" entry 1 must be a hostname, IP literal, or http(s) URL.',
        );
        HeartbeatDto::fromPayload($payload);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvalidHostnameCandidates(): array
    {
        return [
            'empty' => [''],
            'whitespace' => [' '],
            'embedded space' => ['nas lan'],
            'trailing newline' => ["nas.lan\n"],
            'underscore' => ['my_host.lan'],
            'leading hyphen label' => ['-nas.lan'],
            'trailing hyphen label' => ['nas-.lan'],
            'empty label' => ['nas..lan'],
            'trailing dot' => ['nas.lan.'],
            'over-long label' => [str_repeat('a', 64) . '.example'],
            'over-long host' => [str_repeat('a.', 127) . 'ab'],
            'non-ascii host' => ['bücher.example'],
            'numeric tld (bad ipv4)' => ['1.2.3.999'],
            'leading-zero ipv4' => ['010.0.0.1'],
            'port zero' => ['nas.lan:0'],
            'port out of range' => ['nas.lan:99999'],
            'non-numeric port' => ['nas.lan:http'],
            'pseudo scheme' => ['javascript:alert(1)'],
            'bad bracket literal' => ['[not-ipv6]'],
            'unterminated bracket' => ['[::1'],
            'host with path, no scheme' => ['nas.lan/phlix'],
            'scheme-relative' => ['//nas.lan'],
            'ftp scheme' => ['ftp://nas.lan'],
            'file scheme' => ['file:///etc/passwd'],
            'scheme only' => ['http://'],
            'userinfo' => ['http://user:pw@nas.lan'],
            'query' => ['http://nas.lan?x=1'],
            'fragment' => ['http://nas.lan#x'],
            'url port zero' => ['http://nas.lan:0'],
            'unbracketed ipv6 in url' => ['http://:::1:8096'],
            'url with trailing space' => ['http://nas.lan:8096 '],
            'url bad host' => ['https://bad_host.example'],
        ];
    }
}
