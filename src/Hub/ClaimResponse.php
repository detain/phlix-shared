<?php

declare(strict_types=1);

namespace Phlix\Shared\Hub;

use InvalidArgumentException;

/**
 * Hub → Server response to {@see ClaimRequest}.
 *
 * Master plan §6 step 2.
 *
 * @package Phlix\Shared\Hub
 * @since 0.2.0
 */
final class ClaimResponse
{
    /**
     * @param string $claimCode   Human-friendly code like "ABCD-1234" the operator pastes on the hub portal.
     * @param int    $expiresIn   Seconds the claim code is valid (master plan says 600).
     * @param string $claimId     UUID — opaque token the server stores so it can poll claim status.
     * @param string $hubBaseUrl  Where the server should send heartbeats once enrolled.
     */
    public function __construct(
        public readonly string $claimCode,
        public readonly int $expiresIn,
        public readonly string $claimId,
        public readonly string $hubBaseUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws InvalidArgumentException When a required field is missing or wrong-typed.
     */
    public static function fromPayload(array $payload): self
    {
        $claimCode = self::requireString($payload, 'claimCode');
        $claimId = self::requireString($payload, 'claimId');
        $hubBaseUrl = self::requireString($payload, 'hubBaseUrl');
        $expiresIn = self::requireInt($payload, 'expiresIn');

        return new self(
            claimCode: $claimCode,
            expiresIn: $expiresIn,
            claimId: $claimId,
            hubBaseUrl: $hubBaseUrl,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'claimCode' => $this->claimCode,
            'expiresIn' => $this->expiresIn,
            'claimId' => $this->claimId,
            'hubBaseUrl' => $this->hubBaseUrl,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function requireString(array $payload, string $key): string
    {
        if (!array_key_exists($key, $payload)) {
            throw new InvalidArgumentException(sprintf('ClaimResponse "%s" is required.', $key));
        }
        $value = $payload[$key];
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('ClaimResponse "%s" must be a string.', $key));
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function requireInt(array $payload, string $key): int
    {
        if (!array_key_exists($key, $payload)) {
            throw new InvalidArgumentException(sprintf('ClaimResponse "%s" is required.', $key));
        }
        $value = $payload[$key];
        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf('ClaimResponse "%s" must be an integer.', $key));
        }
        return $value;
    }
}
