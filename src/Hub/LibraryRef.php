<?php

/**
 * Library Ref.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Hub;

use InvalidArgumentException;

/**
 * Typed library reference for HeartbeatDto.
 *
 * @package Phlix\Shared\Hub
 * @since 0.2.0
 */
final readonly class LibraryRef
{
    /**
     * @param string $libraryId   Unique library identifier.
     * @param string $libraryName Human-readable library name.
     */
    public function __construct(
        public string $libraryId,
        public string $libraryName,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws InvalidArgumentException When library_id or library_name is missing, empty, or wrong-typed.
     */
    public static function fromPayload(array $payload): self
    {
        $libraryId = $payload['library_id'] ?? null;
        if (!is_string($libraryId) || $libraryId === '') {
            throw new InvalidArgumentException('LibraryRef "library_id" must be a non-empty string.');
        }

        $libraryName = $payload['library_name'] ?? null;
        if (!is_string($libraryName) || $libraryName === '') {
            throw new InvalidArgumentException('LibraryRef "library_name" must be a non-empty string.');
        }

        return new self($libraryId, $libraryName);
    }

    /**
     * @return array{library_id: string, library_name: string}
     */
    public function toPayload(): array
    {
        return [
            'library_id' => $this->libraryId,
            'library_name' => $this->libraryName,
        ];
    }
}
