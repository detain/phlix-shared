# Contributing to `detain/phlix-shared`

A human on-ramp: the gate ritual this repo runs for agents, restated for
people. The binding authority is [`AGENTS.md`](AGENTS.md) in the git
checkout — this file mirrors its final heading structure (Conventions →
the charter below, "Verify before committing" → the eight commands,
"Versioning", "CHANGELOG entry style") rather than competing with it.

## The charter in one breath

- The package is **pure interfaces and value objects** by charter; the one
  sanctioned exception is the `src/Arr/` tree (*arr HTTP clients behind a
  pluggable transport seam). Everything else keeps zero I/O — no
  filesystem, no network, no DB, no logging side-effects.
- **PHP 8.3+**, `declare(strict_types=1);` in every PHP file, **PSR-12**,
  and PSR-4 autoload (`Phlix\Shared\` → `src/`,
  `Phlix\Shared\Tests\` → `tests/`).
- **Static-analysis bar:** PHPStan level 9 and Psalm errorLevel 1, both
  green. No baselines — if a tool emits warnings, fix the code.
- **Zero Workerman dependency**, and the runtime dependency surface is
  exactly the four PSR packages `composer.json` requires; adding anything
  else needs explicit sign-off in a plan step.
- PHPDoc on every public class and method — the static analysers depend
  on it.

## Verify before committing

Contributors need `ext-curl` (a `require-dev` entry — the suite exercises
`CurlArrTransport`). In a checkout, run the eight gates — the same list as
the "Verify before committing" ritual in [`AGENTS.md`](AGENTS.md) and the
`Development` block of [`README.md`](README.md):

```bash
composer install
./vendor/bin/phpunit
./vendor/bin/phpstan analyze --no-progress
./vendor/bin/phpcs --standard=PSR12 src/
./vendor/bin/phpcs --standard=phpcs-tests.xml
./vendor/bin/psalm --no-progress
composer validate --strict
php scripts/security-audit-check.php
```

The `composer` scripts `test`, `stan`, `cs`, `cs-tests` and `psalm` alias
the vendor-binary lines. The default phpunit run excludes the opt-in
`network` group — the live helpLinks probes — declared in
[`phpunit.xml`](phpunit.xml); run those deliberately with
`./vendor/bin/phpunit --group network`.

CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) runs the same
gates: the phpunit/phpstan/phpcs/psalm legs execute on PHP 8.3 and 8.4,
while the Composer Validation and Security Audit jobs are 8.3-only on
purpose (version-independent lock corpora). Enforcement is error-level,
with three known leniencies: the psalm leg adds `--show-info=false`,
`composer validate` adds `--no-check-publish`, and the `tests/` phpcs gate
sets `ignore_warnings_on_exit` in [`phpcs-tests.xml`](phpcs-tests.xml).

⚠ **Never audit with a development-dependency exclusion.** `composer audit
--no-dev` once hid a HIGH OS-command-injection CVE in a `require-dev`
package behind a green gate. [`scripts/security-audit-check.php`](scripts/security-audit-check.php)
audits the whole lock and prints the corpus it examined. Keep the
`composer-audit` job the last one in the workflow and never add
`continue-on-error:` or a condition to its audit step:
[`tests/Support/SecurityAuditCheckTest.php`](tests/Support/SecurityAuditCheckTest.php)
parses the workflow file and reddens on each of those edits.

## Versioning

[Semantic Versioning](https://semver.org/spec/v2.0.0.html), moved in
lockstep: the `Phlix\Shared\Version::VERSION` constant, the git tag, the
`CHANGELOG.md` `## [X.Y.Z]` heading, and the README stamps — the
`**vX.Y.Z**` status line and the `composer require detain/phlix-shared:^X.Y`
install example (below 1.0 the caret does not admit the next minor, so a
stale install line silently pins adopters to the old release).
[`tests/VersionTest.php`](tests/VersionTest.php) machine-pins the
CHANGELOG-heading and README-stamp halves of that lockstep (the git tag
moves with the release ritual itself).

## CHANGELOG entries

The settled forward-only house form lives in AGENTS.md, "CHANGELOG entry
style (forward-only)" — it is deliberately cross-referenced here, never
restated, per that section's own instruction. Released sections are
verbatim history: corrections take the form of a new entry, not a rewrite.

## What ships to consumers (and why this file exists)

`composer.json`'s `archive.exclude` drops the agent- and history-facing
files from the distributed package — `AGENTS.md`, `CALIBER_LEARNINGS.md`
and `CHANGELOG.md` among them — so a packagist install would otherwise
carry no contribution path at all. This file is **not** in that list: it
ships by default, the human on-ramp the git-tree authority cannot reach.
Depth beyond these gates:

- [`README.md`](README.md) — usage, the bundled-schema census, and the
  "Adding a server settings key (runbook)" walk-through.
- The developer pages under
  [`detain/phlix-docs/docs/dev`](https://github.com/detain/phlix-docs/tree/master/docs/dev)
  (`shared-schemas.md`, `arr-clients.md`, `relay-protocol.md`).
- [`AGENTS.md`](AGENTS.md) in the git checkout — the binding ritual for
  agents, including its Caliber-managed config-sync sections, which have
  no human counterpart.

MIT — see [`LICENSE`](LICENSE).
