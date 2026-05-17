# AGENTS.md — detain/phlex-shared

Agent brief for the `phlex-shared` package. This package is **pure
interfaces and value objects**. Keep it that way.

## Conventions

- **PHP 8.3+**. Use modern features (readonly properties, enums,
  first-class callable syntax, etc.) where they aid clarity.
- **`declare(strict_types=1);`** at the top of every PHP file.
- **PSR-12** coding standard, enforced by phpcs.
- **PSR-4 autoload** — `Phlex\Shared\` → `src/`,
  `Phlex\Shared\Tests\` → `tests/`. Namespaces mirror directories.
- **Static analysis bar:** PHPStan level 9 and Psalm errorLevel 1, both
  green from day 1. No baselines.
- **Zero I/O.** No filesystem reads, no network, no DB, no logging
  side-effects. Interfaces and DTOs only.
- **Zero Workerman dependency.** This package must remain consumable
  outside the Workerman runtime.
- **Framework-neutral PSRs only.** `psr/container` and
  `psr/event-dispatcher` are the only third-party runtime deps. Adding
  anything else needs explicit sign-off in a plan step.
- **PHPDoc on every public class and method.** `@package`, `@since`,
  parameter and return tags as appropriate. Static analysers depend on
  it.

## Layout (intended, fills in across v0.2.x)

```
src/
  Plugin/    # Phlex\Shared\Plugin\* — lifecycle, manifest, types
  Events/    # Phlex\Shared\Events\* — event name constants & DTOs
  Auth/      # Phlex\Shared\Auth\* — token, identity, claim types
  Hub/       # Phlex\Shared\Hub\* — phlex ↔ phlex-hub protocol types
  Arr/       # Phlex\Shared\Arr\* — typed array helpers (PHPStan-friendly)
  Version.php
tests/
  (mirror of src/ — PHPUnit 10)
```

In v0.1.0 only `src/Version.php` and `tests/VersionTest.php` exist.
Subdirectories materialise as B.3 lands real interfaces.

## Layout rationale

See `plans/expansion/b.1-shared-design.md` in
[`detain/phlex`](https://github.com/detain/phlex) for the full WHAT
MOVES WHERE table and the design rationale for the namespace split.
Do not re-litigate that design here — propose changes in a new plan
step against `detain/phlex` if needed.

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
`Phlex\Shared\Version::VERSION` constant in lockstep with the git tag
and the `CHANGELOG.md` heading.
