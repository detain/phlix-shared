<?php

/**
 * Secret Redactor — legacy Arr name (class_alias shim).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Arr;

/**
 * BC alias: the kernel was promoted to {@see \Phlix\Shared\Security\SecretRedactor}
 * by S-shared-10 (phlix-shared-010). The four concrete *arr clients — the
 * same-namespace callers that spell the old name — resolve through this alias
 * to the canonical class byte-identically; the shared base has imported the
 * canonical Security name directly since the promotion, so every call-site
 * reaches the one class either way. The exact-value kernel here is the
 * same code that has shipped under this name since 0.11.0, now with the
 * pattern arms added at the canonical home. New consumers should import the
 * Security name; this one is kept for compatibility and roster hygiene
 * (S-shared-15).
 *
 * @package Phlix\Shared\Arr
 * @since 0.11.0
 */
class_alias('Phlix\Shared\Security\SecretRedactor', 'Phlix\Shared\Arr\SecretRedactor');
