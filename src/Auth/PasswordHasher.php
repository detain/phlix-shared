<?php

/**
 * Password Hasher — estate SSOT for password-quality hashing.
 *
 * Canonical home of the class the server (SRV-4.1, 4719fddb) and hub twins
 * declared as "the estate SSOT contract — the planned phlix-shared
 * consolidation moves this class across unchanged": names, order and
 * parameter shapes of hash/verify/needsRehash are preserved verbatim. The
 * v0.56.0 additions (nullable verify with built-in timing burn, hashOrRehash)
 * widen behavior only at the null seam and on the new method, never the
 * three contract signatures.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Auth;

/**
 * Single source of truth for password-quality hashing (B-06).
 *
 * Before this class every ARGON2ID mint in the estate was a bare
 * `password_hash(..., PASSWORD_ARGON2ID)` call: nine call sites estate-wide,
 * each silently pinning its cost parameters to whatever the running PHP
 * declared as ini defaults at first-mint time, and `password_needs_rehash()`
 * had zero call sites — so a drifted or weaker-than-intended cost was never
 * corrected. This class owns all shapes of the operation:
 *
 *  - one canonical options array ({@see self::ARGON2ID_OPTIONS}) that every
 *    mint passes explicitly and every needsRehash() answer is measured
 *    against;
 *  - the only password_verify() call shape used by first-party code — with
 *    the timing-equalization burn built IN, at the seam itself;
 *  - needsRehash(), consumed by login paths to re-mint a stored credential
 *    to canonical cost on a SUCCESSFUL login — a forward-only correction:
 *    password_verify() always reads the per-hash embedded parameters, so
 *    pre-existing hashes keep verifying until their owner next signs in,
 *    and no migration is needed;
 *  - hashOrRehash(), the rehash-on-login composition (DESIGN M-06 gap c),
 *    opt-in per call site: adoption wiring is an owner row (O-4), the API
 *    ships so no call site ever needs to re-derive the sequence.
 *
 * Placement lesson (B-06, carried per DESIGN M-06): the hub's first burn arm
 * sat inside AuthManager, which left repository-bypass entry points (admin
 * resets, external-identity paths) verifying without it — timing oracle
 * restored at the edges that skipped the manager. The shared law therefore
 * places the burn INSIDE verify(): every caller that can hand a missing or
 * empty stored hash gets the full-cost burn automatically, and no bypass
 * position can forget it. Cost parameters and the fixed dummy hash must
 * change in lockstep, or unknown-user and known-user logins stop costing the
 * same; PasswordHasherTest pins that coupling (DESIGN M-06: the server's
 * fixed precomputed burn hash beats the hub's lazily-minted random dummy —
 * deterministic burn, no password_hash() CPU spike inside a request path).
 *
 * @since 0.56.0
 */
final class PasswordHasher
{
    /**
     * The one canonical ARGON2ID cost set, passed explicitly at every mint.
     *
     * - memory_cost 65536 KiB (64 MiB) per hash — the RFC 9106 lowest
     *   recommended memory for a general-purpose interactive login, and
     *   comfortably inside the 12-core no-GPU boxes this project deploys on.
     * - time_cost 4 — four passes; with memory_cost at 64 MiB this keeps a
     *   single hash/verify at roughly 100 ms, which is the envelope the
     *   login-path timing burn documents and equalizes against.
     * - threads 1 — parallelism buys wall-clock on multicloud hosts but no
     *   security; fixing p=1 makes the mint cost host-independent so the
     *   ~100 ms envelope stays honest, and it is the shape of the existing
     *   TIMING_BURN_DUMMY_HASH and of every hash already minted by this
     *   code on stock PHP.
     *
     * These values deliberately match PHP 8.3's built-in PASSWORD_ARGON2ID
     * defaults (measured on 8.3.6 before the v0.56.0 cut: m=65536, t=4,
     * p=1) so credentials already minted by the old bare calls on standard
     * hosts are canonical from day one — needsRehash() answers false for
     * them and nothing churns. Hashes minted under non-default ini values
     * (or bcrypt/argon2i history) are flagged and re-minted on the owner's
     * next successful login. They are pinned explicitly rather than left to
     * the runtime defaults so a future PHP default change (the 8.4 line
     * already differs) cannot silently drift new mints.
     *
     * @var array{memory_cost: int, time_cost: int, threads: int}
     */
    public const ARGON2ID_OPTIONS = [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 1,
    ];

    /**
     * Argon2id dummy hash (canonical costs — {@see self::ARGON2ID_OPTIONS})
     * for the verify() timing burn. This is the server's fixed precomputed
     * value (UserRepository::TIMING_BURN_DUMMY_HASH, L-1 security audit
     * 2026-09-29) brought across verbatim: a real argon2id hash whose
     * plaintext is unknown by construction — only its cost shape matters.
     * Regenerate it in lockstep with any change to the canonical options;
     * PasswordHasherTest pins the embedded parameters against the SSOT
     * constant.
     */
    public const TIMING_BURN_DUMMY_HASH =
        '$argon2id$v=19$m=65536,t=4,p=1$ajhMV1lZWk0wNUZYMUJJeA'
        . '$GnAZV0jZAc3B24RwsTj5qLoSiy6ITh4BFMMHxFIDdSQ';

    /** Pure static surface — this class is not instantiable. */
    private function __construct()
    {
    }

    /**
     * Mint a password hash with the canonical ARGON2ID cost set.
     *
     * @param string $password the plaintext credential
     *
     * @return string the PHC-format hash, canonical parameters embedded
     */
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, self::ARGON2ID_OPTIONS);
    }

    /**
     * Verify a plaintext candidate against any stored hash shape.
     *
     * password_verify() reads the algorithm and cost parameters embedded in
     * the hash itself, so this accepts canonical mints and every legacy
     * format still present in the users table alike.
     *
     * A null or empty stored hash is the "user not found / no local
     * password" seam: it burns one full-cost verify against the fixed dummy
     * and answers false, making unknown-user and known-user timings
     * indistinguishable without asking callers to remember (B-06 placement
     * law — the burn cannot be bypassed by choosing a different entry
     * point).
     */
    public static function verify(string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            password_verify($password, self::TIMING_BURN_DUMMY_HASH);

            return false;
        }

        return password_verify($password, $hash);
    }

    /**
     * True when $hash was not minted with the canonical cost set (or is not
     * an ARGON2ID hash at all, or is garbage) — the signal for a login path
     * to re-mint the credential forward after a successful verify.
     */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::ARGON2ID_OPTIONS);
    }

    /**
     * The rehash-on-login composition (DESIGN M-06 gap c): verify first,
     * re-mint only on a SUCCESSFUL verify of a non-canonical stored hash.
     *
     * @return array{0: string, 1: bool} the hash to persist (the stored one
     *                                  when nothing changed) and whether it
     *                                  was re-minted
     */
    public static function hashOrRehash(string $password, string $stored): array
    {
        if (!self::verify($password, $stored)) {
            return [$stored, false];
        }

        if (!self::needsRehash($stored)) {
            return [$stored, false];
        }

        return [self::hash($password), true];
    }
}
