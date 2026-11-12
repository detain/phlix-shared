<?php

/**
 * Manifest Validation Error.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Plugin;

/**
 * Immutable DTO describing a single validation problem found while
 * checking a parsed {@see Manifest} against the plugin-manifest JSON
 * Schema (bundled here in `schemas/manifest.schema.json`;
 * `phlix-server`'s `Phlix\Plugins\Manifest\ManifestSchema` validates),
 * or against any soft rule, such as "unknown field", that the schema
 * cannot express.
 *
 * `field` is a dotted property path (e.g. `settings.api_key.type`) — a
 * JSON Pointer's `/` separators are rewritten to `.` by the validator —
 * or `''` for the document root. For an `unknown_field` error it is the
 * offending top-level key verbatim. `code` is a short machine identifier
 * (`required`, `pattern`, `enum`, `type`, `unknown_field`, …). `message`
 * is the human-readable description suitable for surfacing in admin UI
 * tooltips.
 *
 * The validator producing these objects lives in `phlix-server` because
 * it depends on a JSON Schema engine; the DTO and the schema file are
 * shared so that other tooling (e.g. `phlix-hub` plugin auditing) can
 * interpret validation results without depending on `phlix-server`.
 *
 * @package Phlix\Shared\Plugin
 * @since 0.2.0
 */
final class ManifestValidationError
{
    /**
     * @param string $field   Dotted path to the offending field, or '' for the root.
     * @param string $code    Stable short identifier (`required`, `enum`, `pattern`, `type`, `unknown_field`, ...).
     * @param string $message Human-readable description.
     */
    public function __construct(
        public readonly string $field,
        public readonly string $code,
        public readonly string $message,
    ) {
    }

    /**
     * @return array{field: string, code: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'code' => $this->code,
            'message' => $this->message,
        ];
    }
}
