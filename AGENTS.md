# AGENTS.md — detain/phlix-shared

Agent brief for the `phlix-shared` package. This package is **pure
interfaces and value objects** by charter; the one sanctioned exception is
the `Arr/` namespace's *arr HTTP clients (see the **Zero I/O** rule below).
Keep everything else that way.

## Conventions

- **PHP 8.3+**. Use modern features (readonly properties, enums,
  first-class callable syntax, etc.) where they aid clarity.
- **`declare(strict_types=1);`** at the top of every PHP file.
- **PSR-12** coding standard, enforced by phpcs.
- **PSR-4 autoload** — `Phlix\Shared\` → `src/`,
  `Phlix\Shared\Tests\` → `tests/`. Namespaces mirror directories.
- **Static analysis bar:** PHPStan level 9 and Psalm errorLevel 1, both
  green from day 1. No baselines.
- **Zero I/O.** No filesystem reads, no network, no DB, no logging
  side-effects. Interfaces and DTOs only.
  > **Exception — `Arr/` namespace.** The `src/Arr/` tree (ten files plus
  > its `Transport/` seam) is exempt from the zero-I/O charter:
  > `AbstractArrClient` and its four concrete clients (`SonarrClient`,
  > `RadarrClient`, `ProwlarrClient`, `BazarrClient`) — driven by
  > `CurlArrTransport` when no transport is injected — perform real
  > HTTP/cURL calls, and `TrashGuidesProvider` performs real network
  > fetches. The tree's remaining members (`ArrClientInterface`,
  > `Transport/ArrTransportInterface`, `ArrClientFactory`, `SyncResult`,
  > `SecretRedactor`) are its interface, wiring, DTO, and helper parts and
  > perform no I/O themselves. This is the sole I/O exception to the
  > zero-I/O policy. Both `phlix-server` and the
  > `phlix-hub` daemon need Sonarr/Radarr/Prowlarr/Bazarr integration; keeping
  > it in `phlix-shared` avoids duplicating HTTP wiring across repos. A future
  > refactor may extract these into a dedicated `phlix-arr-client` package.
  > Note: `TrashGuidesProvider` additionally performs a filesystem
  > `include`/read (it looks for an optional `config/trash_guides.php`
  > override — resolving inside this package's own root, hence only reachable
  > by editing the vendor tree; consumers get the hardcoded fallback URLs), so
  > this exception also covers local file reads — not just HTTP/cURL.
  > See the full developer guide
  > [`arr-clients.md`](../phlix-docs/docs/dev/arr-clients.md)
  > (also published at
  > [`docs/dev/arr-clients.md`](https://github.com/detain/phlix-docs/blob/main/docs/dev/arr-clients.md))
  > in `detain/phlix-docs`.
- **Zero Workerman dependency.** This package must remain consumable
  outside the Workerman runtime.
- **Framework-neutral PSRs only.** The runtime dependency surface is
  exactly the four PSR packages `composer.json` requires: `psr/clock`,
  `psr/container`, `psr/event-dispatcher`, and `psr/log`. Adding
  anything else needs explicit sign-off in a plan step.
  (`psr/event-dispatcher` is the declarative member: no class in `src/`
  imports it — PSR-14 dispatch of these event DTOs is structural — and
  it is kept as a deliberate consumer-convenience typing declaration,
  not oversight; see the README Requirements note.)
- **PHPDoc on every public class and method.** `@package`, `@since`,
  parameter and return tags as appropriate. Static analysers depend on
  it.

## Async Patterns

**Async patterns do not apply to this package.** As a pure interfaces/DTOs
package with zero I/O by design, there are no async concerns within `phlix-shared`
itself. The `Arr/` namespace HTTP clients perform real I/O but delegate actual
async implementation to the consuming application (typically `phlix-server`
which uses `workerman/http-client` with cooperative wait).

For async HTTP patterns, see `phlix-server`'s `MetadataHttpClient`,
`Hub\HttpClient`, and `S3Client` which demonstrate the workerman/http-client
cooperative wait pattern with callback-based requests.

## Layout

```text
src/
  Arr/       # Phlix\Shared\Arr\* — *arr HTTP clients + Transport/ seam
             #   (the sole zero-I/O exception; see the rule above)
  Auth/      # Phlix\Shared\Auth\* — token, identity, claim types
  Console/   # Phlix\Shared\Console\* — machine-readable --json CLI
             #   envelope string kernels (v0.57 wave)
  Database/  # Phlix\Shared\Database\* — shared MySQL kernels (v0.57
             #   wave): transaction-mutex lease/state machine, error
             #   classification + retry verdicts, portable upsert syntax,
             #   row maps, write results, migration checksums, UUID v4
  Events/    # Phlix\Shared\Events\* — PSR-14 event DTOs (Auth/Library/
             #   Playback); the manifest event aliases live in
             #   Plugin/EventNameMap, not in this namespace
  Http/      # Phlix\Shared\Http\* — HTTP wire-law kernels (v0.57 wave):
             #   {error,code} envelope, status reason phrases, static-file
             #   deny policy, trusted-proxy client-IP resolution,
             #   Sec-WebSocket-Protocol handshake
  Hub/       # Phlix\Shared\Hub\* — phlix ↔ phlix-hub protocol types
  Metadata/  # Phlix\Shared\Metadata\* — metadata-source plugin contract
  Metrics/   # Phlix\Shared\Metrics\* — read-side metrics repository
             #   contract (v0.57 wave)
  Plugin/    # Phlix\Shared\Plugin\* — lifecycle, manifest, types, and
             #   the EventNameMap alias↔FQCN table
  RateLimit/ # Phlix\Shared\RateLimit\* — TTL-windowed attempt counter:
             #   contract + worker-local bounded implementation (v0.57
             #   wave)
  Relay/     # Phlix\Shared\Relay\* — channel-mux + HTTP-over-relay frames
  Runtime/   # Phlix\Shared\Runtime\* — runtime-hook allowlist delivery
             #   failure vocabulary (v0.57 wave)
  Schema/    # Phlix\Shared\Schema\* — SchemaPaths for the bundled schemas/
  Security/  # Phlix\Shared\Security\* — Hmac compute()/verify() primitive
             #   (timing-safe sha256 colon-hex seam; server webhooks adopt it
             #   for the legacy sha256= arm — see README), plus JWT,
             #   Ed25519 PEM, PKCE, Base64Url and origin-policy helpers
  Settings/  # Phlix\Shared\Settings\* — settings-column value codec,
             #   typed value ↔ persisted text (v0.57 wave)
  Subtitle/  # Phlix\Shared\Subtitle\* — subtitle source contract + DTOs
  Support/   # Phlix\Shared\Support\* — shared runtime utilities: env
             #   readers with bool laws, Clock, Backoff, Json, plus the
             #   PayloadAssert trait for wire DTOs
  Updates/   # Phlix\Shared\Updates\* — core-update check result +
             #   remote version-marker fetcher contract (v0.57 wave)
  Version.php
tests/
  (mirror of src/ — PHPUnit 12; attributes, static data providers)
```

## Layout rationale

The split is charter-driven: each top-level namespace is exactly the
contract one consumer surface needs, and nothing more. `Auth/`, `Hub/`
and `Relay/` mirror the server↔hub wire (token/claim types, protocol
DTOs, tunnel and HTTP-over-relay envelopes); `Plugin/` and `Events/`
are the plugin-facing surface (lifecycle, manifest, PSR-14 event DTOs
and the `Plugin/EventNameMap` alias table); `Metadata/` and `Subtitle/`
hold the two plugin source contracts the server's registries consume;
`Arr/` is the sole sanctioned I/O exception (*arr clients behind the
pluggable transport seam — see the rule above); `Console/`, `Database/`,
`Http/`, `Metrics/`, `RateLimit/`, `Runtime/`, `Settings/` and `Updates/`
carry the v0.57 consolidation wave's shared server/hub kernels (CLI JSON
envelope, MySQL transaction/retry/upsert state, HTTP wire laws, the
metrics read contract, rate limiting, hook-delivery failures, the
settings value codec, update-check results); `Schema/`, `Security/` and
`Support/` are shared helpers (schema-path resolution, the
HMAC/JWT/Ed25519/PKCE seams, and the env/clock/backoff/JSON utilities
plus the wire-DTO assertion trait); `Version.php` is the single version
marker consumers pin. A new helper belongs in the namespace of the
contract it serves — if it serves none of them, it likely belongs in the
consumer's own repo.

The historical WHAT MOVES WHERE table and full design rationale live in
`plans/expansion/b.1-shared-design.md` in
[`detain/phlix`](https://github.com/detain/phlix); that repo is not part
of the estate checkouts, so the summary above is what a contributor can
actually act on. Do not re-litigate that design here — propose changes
in a new plan step against `detain/phlix` if needed.

## Verify before committing

1. `composer install` resolves clean (contributors need `ext-curl` — a `require-dev` entry for the `CurlArrTransport` tests).
2. `./vendor/bin/phpunit` green.
3. `./vendor/bin/phpstan analyze --no-progress` green.
4. `./vendor/bin/phpcs --standard=PSR12 src/` clean.
5. `./vendor/bin/phpcs --standard=phpcs-tests.xml` clean (the tests/ gate CI enforces).
6. `./vendor/bin/psalm --no-progress` clean.
7. `composer validate --strict` clean.
8. `php scripts/security-audit-check.php` no advisories.

If any tool emits warnings, fix the code — do not add to a baseline.

⚠ **Never audit with a development-dependency exclusion.** The audit step (item 8,
`scripts/security-audit-check.php`) used to read `composer audit --no-dev`, and that flag hid CVE-2026-67434 (HIGH, OS command
injection) in `squizlabs/php_codesniffer` from CI for as long as the advisory
existed: `php_codesniffer` is a `require-dev` package, so the gate reported
SUCCESS against a vulnerable lock. `scripts/security-audit-check.php` (S246)
audits the whole lock, labels each finding `[require]`/`[require-dev]`, and
prints the number of packages it examined. An advisory that genuinely cannot be
actioned goes under `config.audit.ignore` in `composer.json` with a written
reason, where the gate reports it as IGNORED — there is no baseline file.

## Versioning

[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Bump the
`Phlix\Shared\Version::VERSION` constant in lockstep with the git tag,
the `CHANGELOG.md` heading, and the README status/install stamps (the
`**v0.x.y**` line and the `composer require detain/phlix-shared:^0.x`
example — the caret below 1.0 does not admit the next minor, so a stale
install line silently pins consumers to the old release).

## CHANGELOG entry style (forward-only)

New entries take the settled house form: a category label at the start of a
line, a colon, then the substance as the paragraph itself —
`Added: <what shipped, why it exists, and the consumer-visible consequence>`
— one paragraph per change, kept flat under the version heading. The other
labels in the same family are `Changed:`, `Fixed:`, `Tests:` and `Docs:`;
pick the one that matches what the entry actually is. Drifted shapes are
rejected going forward: asterisk-bulleted category entries, and the stray
"Also this cut:" lead-in (phlix-shared-041).

This is forward guidance only, never a retro rewrite. Under the
verbatim-history rule the released sections stay exactly as shipped —
older cuts use Keep-a-Changelog sub-headings with hyphen bullets, and the
`CALIBER_LEARNINGS.md` schema-entry note describes that historical shape.
Neither is touched; where the two disagree, this section is the ritual
authority for future cuts. Should a human-facing `CONTRIBUTING.md` ever
land in this repo, it cross-references here rather than restating the form.

<!-- caliber:managed:pre-commit -->
## Before Committing

**IMPORTANT:** Before every git commit, you MUST ensure Caliber syncs agent configs with the latest code changes.

First, check if the pre-commit hook is already installed:
```bash
grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
```

- If **hook-active**: the hook handles sync automatically — just commit normally. Tell the user: "Caliber will sync your agent configs automatically via the pre-commit hook."
- If **no-hook**: run Caliber manually before committing:
  1. Tell the user: "Caliber: Syncing agent configs with your latest changes..."
  2. Run: `caliber refresh && git add CALIBER_LEARNINGS.md AGENTS.md .agents/ 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

**Valid `caliber refresh` options:** `--quiet` (suppress output) and `--dry-run` (preview without writing). Do not pass any other flags — options like `--auto-approve`, `--debug`, or `--force` do not exist and will cause errors.

**`caliber config`** takes no flags — it runs an interactive provider setup. Do not pass `--provider`, `--api-key`, or `--endpoint`.

If `caliber` is not found, read `.agents/skills/setup-caliber/SKILL.md` and follow its instructions to install Caliber.
<!-- /caliber:managed:pre-commit -->

**Repo note (maintained outside the managed block above — this annotates
around Caliber output, it never reverts it).** This repo ships no
`.agents/` directory: it is in neither the working tree nor `git ls-files`,
so the `caliber refresh && git add CALIBER_LEARNINGS.md AGENTS.md .agents/`
arm above fails on its pathspec and stages nothing (rc=128 here, stderr
suppressed), and for this checkout the two
`.agents/skills/setup-caliber/SKILL.md` mentions (this block and the
Context Sync block below) are satisfied by the tracked path
`.claude/skills/setup-caliber/SKILL.md` instead. The gates to run before a
commit are the eight commands in "Verify before committing" above; this
managed block is Caliber sync guidance only.

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->

<!-- caliber:managed:model-config -->
## Model Configuration

Recommended default: `claude-sonnet-4-6` with high effort (stronger reasoning; higher cost and latency than smaller models).
Smaller/faster models trade quality for speed and cost — pick what fits the task.
Pin your choice (`/model` in Claude Code, or `CALIBER_MODEL` when using Caliber with an API provider) so upstream default changes do not silently change behavior.

<!-- /caliber:managed:model-config -->

<!-- caliber:managed:sync -->
## Context Sync

This project uses [Caliber](https://github.com/caliber-ai-org/ai-setup) to keep AI agent configs in sync across Claude Code, Cursor, Copilot, and Codex.
Configs update automatically before each commit via `caliber refresh`.
If the pre-commit hook is not set up, read `.agents/skills/setup-caliber/SKILL.md` and follow the setup instructions.
<!-- /caliber:managed:sync -->
