<?php

/**
 * HookDeliveryException.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Runtime;

/**
 * Thrown when the runtime-hook allowlist delivery cannot be proven applied.
 *
 * Exact twin lift (v0.57 leg 0.12, M-25 / C-18): phlix-server
 * `src/Server/Runtime/HookDeliveryException.php` (@since 1.2.4, S433) and
 * phlix-hub `src/Runtime/HookDeliveryException.php` (@since 1.3.0) — diff
 * proven namespace/prose only.
 *
 * S433 exists because every check available before it
 * (`Coroutine::getOptions()` and the mask arithmetic pinned by
 * `SwooleRuntimeTest`) reported SUCCESS whether or not the allowlist had
 * physically landed. So BOTH failure shapes are loud: a detected mismatch,
 * and an inconclusive probe. A check that cannot decide must never be
 * scored as a pass — the same "gate that proves nothing" defect class S146
 * removed from the CI jobs.
 */
final class HookDeliveryException extends \RuntimeException
{
}
