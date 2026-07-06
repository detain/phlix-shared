# AGENTS.md — detain/phlix-shared

Agent brief for the `phlix-shared` package. This package is **pure
interfaces and value objects**. Keep it that way.

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
  > **Exception — `Arr/` namespace.** The eight classes under `src/Arr/`
  > (`ArrClientInterface`, `ArrClientFactory`, `SonarrClient`,
  > `RadarrClient`, `ProwlarrClient`, `BazarrClient`, `SyncResult`,
  > `TrashGuidesProvider`) perform real HTTP/cURL calls. This is the sole
  > I/O exception to the zero-I/O policy. Both `phlix-server` and the
  > `phlix-hub` daemon need Sonarr/Radarr/Prowlarr/Bazarr integration; keeping
  > it in `phlix-shared` avoids duplicating HTTP wiring across repos. A future
  > refactor may extract these into a dedicated `phlix-arr-client` package.
  > Note: `TrashGuidesProvider` additionally performs a filesystem
  > `include`/read (it looks for an optional `config/trash_guides.php`
  > override), so this exception also covers local file reads — not just
  > HTTP/cURL.
  > See the full developer guide
  > [`arr-clients.md`](../phlix-docs/docs/dev/arr-clients.md)
  > (also published at
  > [`docs/dev/arr-clients.md`](https://github.com/detain/phlix-docs/blob/main/docs/dev/arr-clients.md))
  > in `detain/phlix-docs`.
- **Zero Workerman dependency.** This package must remain consumable
  outside the Workerman runtime.
- **Framework-neutral PSRs only.** `psr/container` and
  `psr/event-dispatcher` are the only third-party runtime deps. Adding
  anything else needs explicit sign-off in a plan step.
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

## Layout (intended, fills in across v0.2.x)

```
src/
  Plugin/    # Phlix\Shared\Plugin\* — lifecycle, manifest, types
  Events/    # Phlix\Shared\Events\* — event name constants & DTOs
  Auth/      # Phlix\Shared\Auth\* — token, identity, claim types
  Hub/       # Phlix\Shared\Hub\* — phlix ↔ phlix-hub protocol types
  Arr/       # Phlix\Shared\Arr\* — typed array helpers (PHPStan-friendly)
  Version.php
tests/
  (mirror of src/ — PHPUnit 10)
```

In v0.1.0 only `src/Version.php` and `tests/VersionTest.php` exist.
Subdirectories materialise as B.3 lands real interfaces.

## Layout rationale

See `plans/expansion/b.1-shared-design.md` in
[`detain/phlix`](https://github.com/detain/phlix) for the full WHAT
MOVES WHERE table and the design rationale for the namespace split.
Do not re-litigate that design here — propose changes in a new plan
step against `detain/phlix` if needed.

## Before committing

1. `composer install` resolves clean.
2. `./vendor/bin/phpunit` green.
3. `./vendor/bin/phpstan analyze --no-progress` green.
4. `./vendor/bin/phpcs --standard=PSR12 src/` clean.
5. `./vendor/bin/psalm --no-progress` clean.
6. `composer validate --strict` clean.
7. `composer audit --no-dev` no advisories.

If any tool emits warnings, fix the code — do not add to a baseline.

## Versioning

[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Bump the
`Phlix\Shared\Version::VERSION` constant in lockstep with the git tag
and the `CHANGELOG.md` heading.
