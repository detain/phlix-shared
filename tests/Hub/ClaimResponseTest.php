<?php

/**
 * Claim Response Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Hub;

use InvalidArgumentException;
use Phlix\Shared\Hub\ClaimResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Phlix\Shared\Hub\ClaimResponse::class)]
final class ClaimResponseTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function full(): array
    {
        return [
            'claimCode' => 'ABCD-1234',
            'expiresIn' => 600,
            'claimId' => 'claim-uuid',
            'hubBaseUrl' => 'https://hub.example.com',
        ];
    }

    public function test_round_trip(): void
    {
        $dto = ClaimResponse::fromPayload(self::full());
        $this->assertSame(self::full(), $dto->toPayload());
    }

    public function test_missing_claimCode_throws(): void
    {
        $payload = self::full();
        unset($payload['claimCode']);

        $this->expectException(InvalidArgumentException::class);
        ClaimResponse::fromPayload($payload);
    }

    public function test_non_int_expiresIn_throws(): void
    {
        $payload = self::full();
        $payload['expiresIn'] = 'oops';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ClaimResponse "expiresIn" must be an integer.');
        ClaimResponse::fromPayload($payload);
    }

    #[DataProvider('provideEmptyRequiredStrings')]
    public function test_empty_required_string_throws(string $field): void
    {
        $payload = self::full();
        $payload[$field] = '';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ClaimResponse "' . $field . '" must be a non-empty string.');
        ClaimResponse::fromPayload($payload);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideEmptyRequiredStrings(): array
    {
        return [
            'claimCode' => ['claimCode'],
            'claimId' => ['claimId'],
            'hubBaseUrl' => ['hubBaseUrl'],
        ];
    }

    #[DataProvider('provideValidHubBaseUrls')]
    public function test_valid_hubBaseUrl_is_accepted(string $url): void
    {
        $payload = self::full();
        $payload['hubBaseUrl'] = $url;

        $this->assertSame($url, ClaimResponse::fromPayload($payload)->hubBaseUrl);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideValidHubBaseUrls(): array
    {
        return [
            'https' => ['https://hub.example.com'],
            'https trailing slash' => ['https://hub.example.com/'],
            'https with base path' => ['https://example.com/hub'],
            'http dev default' => ['http://localhost:8800'],
            'https ipv4 port' => ['https://203.0.113.7:8443'],
            'https ipv6' => ['https://[2001:db8::1]:8443'],
            'uppercase scheme' => ['HTTPS://hub.example.com'],
        ];
    }

    #[DataProvider('provideInvalidHubBaseUrls')]
    public function test_invalid_hubBaseUrl_throws(string $url): void
    {
        $payload = self::full();
        $payload['hubBaseUrl'] = $url;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'ClaimResponse "hubBaseUrl" must be an absolute http(s) URL with a valid host'
            . ' and no userinfo, query or fragment.',
        );
        ClaimResponse::fromPayload($payload);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvalidHubBaseUrls(): array
    {
        return [
            'no scheme' => ['hub.example.com'],
            'scheme-relative' => ['//hub.example.com'],
            'ftp' => ['ftp://hub.example.com'],
            'file' => ['file:///etc/passwd'],
            'javascript' => ['javascript:alert(1)'],
            'gopher' => ['gopher://hub.example.com:70/_x'],
            'scheme only' => ['https://'],
            'userinfo' => ['https://user:pw@hub.example.com'],
            'userinfo host confusion' => ['https://hub.example.com@evil.example'],
            'query' => ['https://hub.example.com/?next=evil'],
            'fragment' => ['https://hub.example.com/#x'],
            'port zero' => ['https://hub.example.com:0'],
            'underscore host' => ['https://bad_host.example'],
            'leading space' => [' https://hub.example.com'],
            'embedded newline' => ["https://hub.example.com/\nX-Injected: 1"],
            'backslash host' => ['https://hub.example.com\\@evil.example'],
        ];
    }
}
