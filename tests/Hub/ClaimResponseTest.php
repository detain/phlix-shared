<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Hub;

use InvalidArgumentException;
use Phlix\Shared\Hub\ClaimResponse;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Hub\ClaimResponse
 */
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
}
