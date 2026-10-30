# detain/phlix-shared

[![CI](https://github.com/detain/phlix-shared/actions/workflows/ci.yml/badge.svg)](https://github.com/detain/phlix-shared/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/phlix-shared/graph/badge.svg)](https://codecov.io/gh/detain/phlix-shared)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen)](https://phpstan.org/)
[![Psalm](https://img.shields.io/badge/Psalm-level%201-brightgreen)](https://psalm.dev/)
[![Code style](https://img.shields.io/badge/code%20style-PSR--12-blueviolet)](https://www.php-fig.org/psr/psr-12/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Shared interfaces, DTOs, event names, and protocol types used by both
[`detain/phlix-server`](https://github.com/detain/phlix-server) (the media server)
and [`detain/phlix-hub`](https://github.com/detain/phlix-hub) (the multi-server hub).
Composer-installable, PHP 8.3+, zero I/O by charter — pure interfaces and
value objects, with one sanctioned exception: the `Arr\*` namespace ships
real (blocking cURL) *arr HTTP clients; see [`AGENTS.md`](AGENTS.md).

## Status

**v0.52.0** (`Phlix\Shared\Version::VERSION`). `schemas/server-settings.schema.json` carries 84 keys (51 `restart: true`, 33 `restart: false`); `schemas/hub-settings.schema.json` carries 18 keys in lockstep with `phlix-hub`'s `HubSettingsRepository::ALLOWED_KEYS`. Cumulative surface:

- `Phlix\Shared\Plugin\{LifecycleInterface, ConfigurableInterface, Manifest, ManifestType, ManifestValidationError, EventNameMap}`
- `Phlix\Shared\Events\{AbstractEvent, Playback\*, Library\*, Auth\*}` — 12 event DTOs.
- `Phlix\Shared\Auth\{JwtClaims, ProviderInterface, AuthResult, UserInfo}`
- `Phlix\Shared\Metadata\MetadataSourceInterface` — the typed contract a metadata-source plugin
  implements (`sourceName()`, `supportedMediaTypes()`, `search()`, `getDetails()`, `getImages()`) so
  the server's `SourceRegistry` can register/deregister it on plugin enable/disable without the old
  `method_exists`/FQCN-sniffing convention (0.15.0+).
- `Phlix\Shared\Subtitle\{SubtitleSourceInterface, SubtitleCandidate, SubtitleFile, Exception\QuotaExceeded}` —
  the subtitle analogue of `MetadataSourceInterface`: `getName()`, `getPriority()`, the
  `searchByPath()`/`searchByHash()`/`searchByImdbId()` fan-out returning `SubtitleCandidate` values, and the
  quota-consuming `download()` that may throw `QuotaExceeded` (0.42.0+).
- `Phlix\Shared\Hub\{ClaimRequest, ClaimResponse, ServerInfoDto, HeartbeatDto, LibraryRef}`
- `Phlix\Shared\Relay\{RelayFrameType, RelayWireCodecInterface, RelayFrame}` — channel-mux protocol (0.5+);
  plus `Relay\{RelayHttpRequest, RelayHttpRequestHead, RelayHttpRequestChunk, RelayHttpRequestCodec}` and
  `Relay\{RelayHttpResponseHead, RelayHttpResponseChunk, RelayHttpResponseCodec}` —
  HTTP-over-relay request/response envelopes + `HEAD→BODY*→END` chunk framing (0.10.0+).
- `Phlix\Shared\Arr\{ArrClientInterface, ArrClientFactory, AbstractArrClient, BazarrClient, ProwlarrClient, RadarrClient, SonarrClient, SyncResult, SecretRedactor, TrashGuidesProvider}` —
  *arr HTTP clients with their factory, shared base, sync value object, redactor and
  TrashGuides provider; `Arr\Transport\{ArrTransportInterface, CurlArrTransport}` is the pluggable
  wire layer underneath.
- `Phlix\Shared\Security\Hmac` — constant-time HMAC sign/verify primitive (webhooks, relay tokens).
- `Phlix\Shared\Support\PayloadAssert` — the presence/type assertion trait every wire DTO in
  `Hub\`/`Auth\` parses through.
- `Phlix\Shared\Schema\SchemaPaths` — pure path resolver for the bundled `schemas/` files (0.7.0+).
- `Phlix\Shared\Version` — the single `VERSION` constant consumers pin and report.

### Bundled schemas

The package ships the canonical JSON files used by both phlix-server and the
admin SPA under `schemas/` (resolve their absolute paths via the dedicated
`Phlix\Shared\Schema\SchemaPaths` accessors — `serverSettings()`, `hubSettings()`,
`webhookEvents()`, `mediaItem()`, `libraryQuery()` — and via `SchemaPaths::dir()`
for the files without a dedicated accessor, such as `manifest.schema.json`):

- `schemas/manifest.schema.json` — JSON Schema (draft 2020-12) for plugin manifests,
  loaded at runtime by `phlix-server`'s `Phlix\Plugins\Manifest\ManifestSchema` validator (0.6.0+).
  Per-setting `label` and `description` are permitted, and `integer`/`boolean` are accepted as
  aliases of `int`/`bool` in the setting `type` enum (0.9.1+). An optional per-setting `tier`
  (`standard`|`advanced`) is accepted on plugin settings fields so a `plugin.json` declaring it
  passes install-time manifest validation (0.43.0+).
- `schemas/hub-settings.schema.json` — JSON Schema (draft 2020-12) for the editable hub
  settings (`/api/v1/me/hub-settings`), resolved via `SchemaPaths::hubSettings()` (0.22.0+).
  Its property keys must stay in lockstep with `phlix-hub`'s
  `HubSettingsRepository::ALLOWED_KEYS` — that constant is what the hub settings controller
  enumerates, and the schema supplies only the render metadata for those keys (0.24.0+).
  The lockstep is guarded from this side by `tests/Schema/HubSettingsSchemaTest.php`,
  whose drift test parses the LIVE constant out of a hub checkout — found via the
  `PHLIX_HUB_REPO` env pointer (a broken pointer fails the run; it never silently
  skips) or a `phlix-hub` sibling directory of this checkout — and skips only when no
  hub checkout exists at all (shared CI takes that skip; the test's frozen mirror and
  the hub's own reverse guard cover the keys there).
- `schemas/server-settings.schema.json` — JSON Schema (draft 2020-12) for the editable
  server settings (`/api/v1/admin/settings`); phlix-server derives its writable allow-list
  from this schema and the admin SPA renders the settings form from it (0.7.0+).
  Extended with optional UI-metadata keywords (`label`, `helpText`, `helpLinks`, `tier`,
  `enumLabels`, `optionHelp`, `secret`, `restart`) so the admin settings UI can render
  per-option help text and split Standard vs Advanced options (0.22.0+).
  Their normative semantics — requiredness, shape, and the verified consumer effect of
  each keyword — are specified in-repo by the keyword-semantics table at the top of
  `tests/Schema/SettingsSchemaAssertions.php`, whose assertions are that table's
  executable enforcement; no external plan document is needed to author or review a key.

  **Key-naming contract (0.25.0+).** Every property key must be a dotted path that
  `Phlix\Admin\SettingsRepository::getDefault()` can resolve: a LEADING RUN of segments
  names a config file under the consuming repo's `config/`, and the remaining segments
  index into that file's returned array. The file part may span subdirectories — the
  longest matching file path wins, so `scrobblers.trakt.client_id` resolves
  `config/scrobblers/trakt.php` in preference to a same-named flat file. Every file
  segment is jailed to `/^[A-Za-z0-9_-]+$/`, so no key can escape `config/`. A key that
  does not resolve renders an empty control and can never take effect, so no such key may
  be declared.
  The `trakt.*` keys deliberately use a FLAT prefix backed by `phlix-server`'s
  `config/trakt.php` re-export shim rather than the nested `scrobblers.trakt.*` form:
  `trakt.*` overrides are already persisted in live `server_settings` tables and
  `TraktOperatorConfig::SETTING_KEY_MAP` reads those exact keys, so renaming them would
  orphan real rows (0.25.0+).
  Adds `matching.noise_suffixes` (array of strings; the admin-extensible match-title noise list —
  0.13.0+) and `metadata.provider_priority` (object: media type → ordered array of source names;
  defaults `movie`/`series` = `["tmdb","imdb"]`, `anime` = `["anidb","myanimelist","tvdb","fanart","local"]`)
  + `metadata.genres_mode` (enum `first`|`union`, default `first`) for the per-media-type metadata
  source-priority editor (0.14.0+).
  Adds `metadata.overwrite_existing` (`boolean`, `group: metadata`, `tier: standard`, 0.41.0+),
  `subtitles.provider_priority` (a single flat ordered array of subtitle-source names, default
  `["opensubtitles"]`, read by `SubtitleFetchService`/`SubtitleSourceRegistry::byPriority()` — 0.44.0+),
  and `dlna.allowed_cidrs` (array, default `[]`) + `dlna.restrict_to_lan` (boolean, default `true`),
  both `tier: advanced` / `group: subsystem`, read live by `DlnaAllowlistMiddleware` to gate the
  unauthenticated DLNA browse/stream endpoints (0.45.0+).
  Adds `transcoding.segment_format` (enum `mpegts`|`fmp4`, `group: transcoding`, `tier: advanced`,
  `restart: false`), which is what makes the segment container settable over the admin API — the
  `enum` mirrors phlix-server's `EncodeSettings::SEGMENT_FORMATS` and is read by
  `EncodeSettings::segmentFormat()` at encode time (0.49.0+); its `default`, `enumLabels`,
  `optionHelp`, `helpText`, and `description` now describe `fmp4` as the shipped container and
  `mpegts` as the supported rollback (0.49.1+).

  **`restart` flags (0.46.0–0.48.0 audit).** Every one of the then-72 keys was traced to
  its consumer (the audit window held 72 — v0.46.0 = v0.47.0 = v0.48.0 = v0.48.1; the 73rd,
  `transcoding.segment_format`, arrived with the 0.49.0 addition named just above); keys
  added since each carry an explicit flag too. As of 0.52.0 the schema holds
  84 keys: 51 carry `restart: true` and 33 carry `restart: false`. `restart: true` means the
  admin **Restart server** control (a graceful SIGUSR2 reload that cycles workers, re-runs
  `onWorkerStart`, and rebuilds DI containers) — required for any value captured at worker
  start, container build, or route build. The `restart: false` keys resolve through
  `SettingsRepository::getEffective()` at use time and are genuinely live.
- `schemas/webhook-events.json` — data catalog of the wire-truth webhook event types for the
  admin SPA webhook picker. Note: this file is currently a subset — the server's
  `Phlix\Webhooks\WebhookEventCatalog` and the `phlix-ui` picker deliberately lead it today
  (the `user.*` events the server already emits are not yet named here; widening this catalog
  is owed in this package). Distinct from the plugin PSR-14 events in `EventNameMap` (0.7.0+).
- `schemas/library-query.schema.json` — JSON Schema (draft 2020-12) for the query parameters of
  the movie-list browse API (`GET /api/v1/media`); drives `ItemRepository::query()` and the
  admin SPA browse page (0.8.0+). Adds the `libraryId`/`parentId`/`topLevel` scoping parameters
  for series→season→episode navigation (0.9.0+).
- `schemas/media-item.schema.json` — JSON Schema (draft 2020-12) for a single media item returned
  by the browse API; flattens `metadata_json` into stable top-level fields and always includes
  `poster_url` (0.8.0+). The `type` enum gains `season` alongside `parent_id`, `season_number`,
  `episode_number`, and `episode_title` for the series hierarchy (0.9.0+).

The PSR-14 dispatcher wiring (Tukio) and the schema validators stay in
`phlix-server` and consume this package via Composer.

## Requirements

- PHP `^8.3` — CI-verified on PHP 8.3 and 8.4 (phpcs, phpstan and phpunit
  each run both legs; the psalm gate is 8.3-only because the toolchain's
  locked vimeo/psalm 5.x declares a `~8.3.0` ceiling); declared compatible
  below PHP 9.0.
- Composer 2.x
- `psr/clock ^1.0`
- `psr/container ^2.0`
- `psr/event-dispatcher ^1.0`
- `psr/log ^3.0`

`psr/event-dispatcher` is deliberately declarative: no class in `src/`
imports a PSR-14 interface — dispatching the plain event DTOs under
`Phlix\Shared\Events\` is structurally valid PSR-14 usage — and the
require is kept as a consumer-convenience typing declaration so packages
wiring a dispatcher resolve the contract through this package's own
dependency set (keep-by-decision, not oversight; see
`Phlix\Shared\Events\AbstractEvent`).

The package has zero framework dependencies — no Workerman, no Monolog,
no Smarty. It is intended to be safely required by any PHP 8.3+ codebase,
with one caveat: the `Arr\*` HTTP clients are real network code (their
default transport needs `ext-curl`, or inject an `ArrTransportInterface`;
see [`AGENTS.md`](AGENTS.md)).

## Installation

`detain/phlix-shared` is published on Packagist:

```bash
composer require detain/phlix-shared:^0.52
```

## Related repositories

- [`detain/phlix-server`](https://github.com/detain/phlix-server) — the Phlix media server (consumes this package).
- [`detain/phlix-hub`](https://github.com/detain/phlix-hub) — the multi-server hub + reverse-tunnel relay.

## Development

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

CI enforces every line above, including the `tests/` phpcs gate. The
`composer` scripts `test`, `stan`, `cs`, `cs-tests` and `psalm` alias the
vendor-binary lines.

⚠ The last step replaces `composer audit --no-dev`. **Never audit with a
development-dependency exclusion:** `--no-dev` drops every `require-dev` package
from the audited set, and it hid CVE-2026-67434 (HIGH, OS command injection) in
`squizlabs/php_codesniffer` — a `require-dev` package — so CI reported SUCCESS
against a vulnerable lock. `scripts/security-audit-check.php` audits the whole
lock, labels each finding `[require]` / `[require-dev]`, and prints the number of
packages it examined so a truncated audit cannot pass as a clean one. See
[`AGENTS.md`](AGENTS.md) for the full policy.

The `phpunit` job in [`.github/workflows/ci.yml`](.github/workflows/ci.yml) uploads
`./coverage.xml` to both Codecov and Codacy.

## License

MIT — see [`LICENSE`](LICENSE).
