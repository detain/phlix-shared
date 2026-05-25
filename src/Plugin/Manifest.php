<?php

declare(strict_types=1);

namespace Phlix\Shared\Plugin;

use JsonException;
use RuntimeException;

/**
 * Immutable value object representing a parsed `plugin.json`.
 *
 * Construction is split into two phases:
 *
 *  1. {@see self::fromJson()} / {@see self::fromArray()} — cheap parsing
 *     that throws {@see RuntimeException} only on hard structural
 *     problems (malformed JSON, non-object root). All other issues,
 *     including unknown {@see ManifestType} values and missing required
 *     fields, are deferred so the caller sees every problem at once via
 *     a separate validator pass.
 *  2. Validation is performed by `Phlix\Plugins\Manifest\ManifestSchema`
 *     in `phlix-server` (which owns the JSON Schema file). The validator
 *     consumes a `Manifest` via {@see self::getRawData()} and
 *     {@see self::getUnknownFields()} and emits a list of
 *     {@see ManifestValidationError}.
 *
 * Unknown top-level fields are accepted at construction time but
 * preserved via {@see self::getUnknownFields()} so the validator can
 * surface them.
 *
 * ## Subclassing
 *
 * This class is **not** `final` so the `phlix-server` deprecation
 * wrapper at `Phlix\Plugins\Manifest` can extend it for one release.
 * Other callers should not subclass — treat the class as effectively
 * final.
 *
 * @package Phlix\Shared\Plugin
 * @since 0.2.0
 */
class Manifest
{
    /**
     * Top-level keys recognised by the schema. Anything else is reported
     * as `unknown_field` by the validator.
     *
     * @var list<string>
     */
    public const array KNOWN_TOP_LEVEL_KEYS = [
        'name',
        'version',
        'phlix_min_server_version',
        'type',
        'entry',
        'events',
        'settings',
        'signature',
    ];

    /**
     * @param string $name Plugin identifier, kebab-case, prefixed `phlix-plugin-`.
     * @param string $version Plugin semver.
     * @param string $phlixMinServerVersion Minimum Phlix server semver.
     * @param string $type Raw type string. Resolve via {@see self::manifestType()}.
     * @param string $entry Fully-qualified entry-class name.
     * @param list<string> $events Manifest event aliases.
     * @param array<string, array{type: string, required?: bool, secret?: bool, default?: mixed}> $settings
     *     Settings schema keyed by setting name.
     * @param string|null $signature `sha256:<hex>` signature or null when unsigned.
     * @param array<string, mixed> $rawData Original decoded array, retained for the validator.
     * @param list<string> $unknownFields Top-level keys not in {@see self::KNOWN_TOP_LEVEL_KEYS}.
     */
    final protected function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $phlixMinServerVersion,
        public readonly string $type,
        public readonly string $entry,
        public readonly array $events,
        public readonly array $settings,
        public readonly ?string $signature,
        protected readonly array $rawData,
        protected readonly array $unknownFields,
    ) {
    }

    /**
     * Parse a JSON-encoded manifest. Throws when the payload cannot
     * become a {@see Manifest} at all.
     *
     * @throws RuntimeException When the JSON is malformed or the decoded root is not an object.
     */
    public static function fromJson(string $json): static
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                'Manifest is not valid JSON: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'Manifest root must be a JSON object, got ' . gettype($decoded) . '.',
            );
        }

        /** @var array<string, mixed> $decoded */
        return static::fromArray($decoded);
    }

    /**
     * Build a {@see Manifest} from an already-decoded array. Performs
     * the minimum coercion needed to populate the readonly properties;
     * full schema validation is opt-in via a separate validator.
     *
     * @param array<string, mixed> $data Decoded manifest payload.
     */
    public static function fromArray(array $data): static
    {
        $rawType = is_string($data['type'] ?? null) ? (string) $data['type'] : '';

        $name = is_string($data['name'] ?? null) ? (string) $data['name'] : '';
        $version = is_string($data['version'] ?? null) ? (string) $data['version'] : '';
        $minVersion = is_string($data['phlix_min_server_version'] ?? null)
            ? (string) $data['phlix_min_server_version']
            : '';
        $entry = is_string($data['entry'] ?? null) ? (string) $data['entry'] : '';

        $events = [];
        if (isset($data['events']) && is_array($data['events'])) {
            /** @var mixed $event */
            foreach ($data['events'] as $event) {
                if (is_string($event)) {
                    $events[] = $event;
                }
            }
        }

        $settings = [];
        if (isset($data['settings']) && is_array($data['settings'])) {
            /** @var mixed $value */
            foreach ($data['settings'] as $key => $value) {
                if (is_string($key) && is_array($value)) {
                    /** @var array{type: string, required?: bool, secret?: bool, default?: mixed} $value */
                    $settings[$key] = $value;
                }
            }
        }

        $signature = null;
        if (array_key_exists('signature', $data) && is_string($data['signature'])) {
            $signature = $data['signature'];
        }

        $unknownFields = [];
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::KNOWN_TOP_LEVEL_KEYS, true)) {
                $unknownFields[] = $key;
            }
        }

        return new static(
            name: $name,
            version: $version,
            phlixMinServerVersion: $minVersion,
            type: $rawType,
            entry: $entry,
            events: $events,
            settings: $settings,
            signature: $signature,
            rawData: $data,
            unknownFields: $unknownFields,
        );
    }

    /**
     * Resolve the typed {@see ManifestType} enum for this manifest, or
     * null when the raw `type` string is not one of the known cases.
     * Callers that need a guaranteed-valid type should run the
     * validator first.
     */
    public function manifestType(): ?ManifestType
    {
        if ($this->type === '') {
            return null;
        }

        return ManifestType::tryFrom($this->type);
    }

    /**
     * Serialise the manifest back to its original decoded shape.
     *
     * Note: this returns the original decoded payload (`$this->rawData`)
     * verbatim — it is NOT a re-serialisation of the mutated typed
     * properties. Any changes made to typed props after construction are
     * not reflected here.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->rawData;
    }

    /**
     * Access the original decoded manifest payload. Used by the
     * `phlix-server` validator (`Phlix\Plugins\Manifest\ManifestSchema`)
     * to feed the JSON Schema engine.
     *
     * @return array<string, mixed>
     *
     * @since 0.2.0
     */
    public function getRawData(): array
    {
        return $this->rawData;
    }

    /**
     * Top-level keys present in the manifest payload that are not part
     * of the recognised schema. Used by the validator to emit
     * `unknown_field` errors.
     *
     * @return list<string>
     *
     * @since 0.2.0
     */
    public function getUnknownFields(): array
    {
        return $this->unknownFields;
    }
}
