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
value objects, with one sanctioned exception: bundled I/O is confined to the
`Arr\*` namespace — the blocking cURL `CurlArrTransport` default of the *arr
HTTP clients, plus `TrashGuidesProvider`'s own blocking stream fetches and its
optional `config/trash_guides.php` local read; see [`AGENTS.md`](AGENTS.md) (in the git checkout; it is excluded from the Composer archive).

## Status

**v0.59.0** (`Phlix\Shared\Version::VERSION`). Schema censuses (`tests/VersionTest.php` pins these figures against the schema files, this paragraph, and the tag): `schemas/server-settings.schema.json` carries 87 keys (51 `restart: true`, 36 `restart: false`), byte-identical between the working tip and the v0.59.0 tag; `schemas/hub-settings.schema.json` carries 20 keys at the working tip — in lockstep with `phlix-hub`'s `HubSettingsRepository::ALLOWED_KEYS` — up from 18 at v0.59.0 tag time: the `auth.cookie.*` pair added after the cut ships in the next release. Cumulative surface:

- `Phlix\Shared\Plugin\{LifecycleInterface, ConfigurableInterface, Manifest, ManifestType, ManifestValidationError, EventNameMap}`
- `Phlix\Shared\Events\{AbstractEvent, Playback\*, Library\*, Auth\*}` — 12 event DTOs.
- `Phlix\Shared\Auth\{JwtClaims, ProviderInterface, AuthResult, UserInfo, SessionFamily, FamilyRefusalReason, GrantConsume, FamilyGrace, PasswordHasher}`
  — `SessionFamily` is the claim/type/grace vocabulary (its `TYPE_*` constants alias `JwtClaims`);
  `FamilyRefusalReason`/`GrantConsume` are the session-family rotation enums and `FamilyGrace`
  the pure within-grace arithmetic; `PasswordHasher` is the argon2id SSOT seam with the
  timing-burn dummy hash and burn-then-false `verify()` built in (0.56.0+).
- `Phlix\Shared\Metadata\MetadataSourceInterface` — the typed contract a metadata-source plugin
  implements (`sourceName()`, `supportedMediaTypes()`, `search()`, `getDetails()`, `getImages()`) so
  the server's `SourceRegistry` can register/deregister it on plugin enable/disable without the old
  `method_exists`/FQCN-sniffing convention (0.15.0+).
- `Phlix\Shared\Subtitle\{SubtitleSourceInterface, SubtitleCandidate, SubtitleFile, Exception\QuotaExceeded}` —
  the subtitle analogue of `MetadataSourceInterface`: `getName()`, `getPriority()`, the
  `searchByPath()`/`searchByHash()`/`searchByImdbId()` fan-out returning `SubtitleCandidate` values, and the
  quota-consuming `download()` that may throw `QuotaExceeded` (0.42.0+).
- `Phlix\Shared\Hub\{ClaimRequest, ClaimResponse, ServerInfoDto, HeartbeatDto, LibraryRef}`
- `Phlix\Shared\Relay\{RelayFrameType, RelayWireCodecInterface, RelayFrame, FrameCodec, FrameCodecWireAdapter, SyncPlayDialect}` — channel-mux protocol (0.5.1+);
  `FrameCodec` is the canonical binary frame encoder (encode legs + hello/hello-ack JSON shapes; 0.56.0+);
  `FrameCodecWireAdapter` object-wraps those statics behind `RelayWireCodecInterface`, with `decode()`
  failing loudly by design — inbound decoding stays repo-local (server skip-oracle vs hub throw-on-unknown);
  `SyncPlayDialect` is the canonical `syncplay_*` wire message-type catalog (shipped in v0.55.0);
  plus `Relay\{RelayHttpRequest, RelayHttpRequestHead, RelayHttpRequestChunk, RelayHttpRequestCodec}` and
  `Relay\{RelayHttpResponseHead, RelayHttpResponseChunk, RelayHttpResponseCodec}` —
  HTTP-over-relay request/response envelopes + `HEAD→BODY*→END` chunk framing (0.10.0+).
- `Phlix\Shared\Arr\{ArrClientInterface, ArrClientFactory, AbstractArrClient, BazarrClient, ProwlarrClient, RadarrClient, SonarrClient, SyncResult, TrashGuidesProvider}` —
  *arr HTTP clients with their factory, shared base, sync value object and
  TrashGuides provider; `Arr\Transport\{ArrTransportInterface, CurlArrTransport}` is the pluggable
  wire layer underneath. The redactor that used to sit in this namespace was
  promoted to `Phlix\Shared\Security\SecretRedactor` (S-shared-10); the old
  `Arr\SecretRedactor` name stays importable through a `class_alias` shim.
- `Phlix\Shared\Security\Hmac` — the shared `compute()`/`verify()` seam for timing-safe
  HMAC-SHA256 in the `sha256:<hex>` colon-hex format, plus an additive envelope-separator
  arm (`SEPARATOR_EQUALS` serves the webhook legacy `sha256=<hex>` shape; the colon default
  keeps every existing byte identical). Consumer status: adopted by phlix-server webhooks for
  the legacy `sha256=<hex>` arms — `WebhookEvent::getSignature()` signs and
  `WebhookEvent::verify()` accepts the legacy header through `Hmac::compute()`/`Hmac::verify()`
  with `SEPARATOR_EQUALS`. Not yet migrated: the timestamped V2 (`t=<unix>,v1=<hex>`) arms,
  which still call raw `hash_hmac()`, and plugin-manifest checks, which remain a
  trusted-digest allowlist, not a keyed HMAC (there is no `sign()` method).
- `Phlix\Shared\Security\{Base64Url, Pkce, Jwt, JwtTokenParts, JwtException, CredentialDigest, OriginPolicy, Ed25519Pem}` —
  the estate security kernels cut from the server/hub duplicates (0.56.0+): strict/lax base64url
  codec; the hub S256-only PKCE guard set plus a verifier generator; an HS256 JWT mint/verify
  with the `alg`/`typ`-before-signature pin and frozen attack-vector corpus; the sha256
  credential-digest seam (never for user passwords); the CSRF-origin brain kernel (pure
  forwarded/proto/localhost/same-site/verb/sec-fetch guards and `custodyMode`, proven
  zero server/hub divergence); and Ed25519 PEM/JWK primitives with the kid fingerprint
  parameterized per emitting shape. Consumer delegation to these is the S1/S2 adoption waves.
- `Phlix\Shared\Security\SecretRedactor` — the secret-redaction kernel (0.11.0, promoted
  from `Phlix\Shared\Arr` at S-shared-10). `redact()` replaces exact secret values
  longest-first; the pattern arms `redactUri()` (credential-named query values +
  `scheme://user:pass@` userinfo) and `redactBearer()` (entropy-gated
  `Bearer`/`Basic`/`token`-shaped values) collapse secret-SHAPED material even when
  the caller does not hold the value; every arm also layers the exact-value kernel
  over a variadic `...$secrets`. The old `Arr\SecretRedactor` name is a `class_alias`
  to this class and remains importable.
- `Phlix\Shared\Support\PayloadAssert` — the presence/type assertion trait used by the wire DTOs
  that parse payloads through it: `ClaimRequest`, `ClaimResponse`, `ServerInfoDto`,
  `HeartbeatDto` and `JwtClaims`. `Hub\LibraryRef` is the deliberate manual-validation exception
  (its `fromPayload()` rejects empty strings inline); `Auth\UserInfo` is a constructor value
  object that parses no payload at all.
- `Phlix\Shared\Support\{EnvConfig, Json, Clock, Backoff}` — the shared runtime helpers (0.56.0+):
  pure `getenv` readers with the bool/opt-out word laws (no shell probes, no logging
  side-effects — charter-pinned deltas from the server original); the strict JSON seam
  (unconditional throw on encode, null-vs-rethrow discrimination on inbound decode); the
  monotonic-free epoch clocks with the `unixMs()` round law and a pure `msFromUnixSeconds()`
  fixture seam; and pure reconnect-math `stepped()`/`jittered()` backoff with an injectable rng.
- `Phlix\Shared\Database\{Uuid, WriteResult, UpsertSyntax, MysqlError, MigrationChecksum}` —
  the estate database kernels cut from the server/hub twins (0.57.0+): the CSPRNG v4
  mint with the published `V4_PATTERN` and the `randomHex()` entropy-law seam; the
  write-outcome predicate (`'0'`-means-success trap documented as law) with the hub's
  row-count fold; the portable upsert rewriter (`ON DUPLICATE KEY UPDATE` alias form
  rewritten to `VALUES(col)` on MariaDB/pre-8.0.19); the consolidated MySQL
  driver-code detector (named wire vocabulary, wire-authoritative-over-text parse);
  and the SV-4.9 migration-ledger checksum kernel — its committed 174-file
  corpus-equality fixture proves kernel ≡ both repos' normalizers (the consumer
  real-DB ledger rehearsal legs stay per-repo).
- `Phlix\Shared\Database\{MysqlTxLockState, TxEnd, MysqlRetryPolicy, MysqlRetryVerdict, TxLease}` —
  the transaction-mutex machine family (0.58.0+): `MysqlTxLockState` is the
  Option-B ledger SSOT extracted from the server/hub TXMUTEX-A union (holder
  identity, nesting, the one-shot poison mark with cleared-on-reconnect, the
  cancel-checked acquire, and the clear-holder-before-push `handOff()` law)
  with `TxEnd`'s total end-claim verdict; `MysqlRetryPolicy`/`MysqlRetryVerdict`
  are the statement-failure decision table (gone-in-transaction never replays
  and poisons; gone-in-autocommit replays exactly once; 1213 rolls back the
  whole transaction; 1205 keeps it usable); and `TxLease` binds one coroutine
  to the ledger — state, coroutine id, park seam and push seam bound once —
  carrying the single begin ceremony the server and hub landed identically,
  while the end ceremonies stay per-repo by design. The lock channel itself
  stays consumer-side; the family is pure state and decision, zero I/O.
- `Phlix\Shared\Settings\SettingsCodec` — the settings-column value codec (0.57.0+):
  `decode()` byte-identical to both repos' private ancestors; `encode()` ships the
  hub's strict `JSON_THROW_ON_ERROR` posture (the server silent variant is the
  disclosed divergence ending at S1 1.8 adoption); plus the pure `effective()`
  decision arm and the `clampValue()` reader primitive.
- `Phlix\Shared\RateLimit\{RateLimiterInterface, RateLimitState, RateLimiter, RateLimitException}` —
  the auth-surface attempt-counter family (0.57.0+): bounded in-memory engine with
  expired-sweep-then-soonest-reset eviction and injectable clock; the hub-superset
  exception (`MESSAGE_PREFIX` wire copy, rounded-up minutes `retryAfterMessage()`);
  the hub-only `multiWorkerNotice()` copy-verbatim advisory (emission stays consumer
  glue). `DbRateLimiter`/`RateLimitProfiles` deliberately stay per-repo.
- `Phlix\Shared\Http\{ErrorEnvelope, ReasonPhrases, StaticPathPolicy, TrustedProxyResolver, WebSocketHandshake}` —
  the HTTP kernel family (0.57.0+): the `{error, code}`-plus-additive body law with
  the 429/503 shape builders; the hub strict-empty reason law with a byte-faithful
  copy of the vendored Workerman `PHRASES` table; the `php`/`map`/`tpl` static-deny
  vocabulary in the hub's public predicate shape; the SV-4.15 trusted-proxy/XFF
  resolver; and the `Sec-WebSocket-Protocol` string kit (bearer offer/token/echo-line
  kernels — carrier policy intentionally stays per-repo). Adoption status: the
  family is INERT — zero imports in either host's committed `src/` tree, and both
  hosts still run their own per-repo copies of every shape here. Migrating the
  server onto the strict-empty resolver is the named-`'0'`-bug fix, and that bug
  is consequently STILL LIVE (`src/Server/Workerman/BodylessResponse.php` `?:`):
  the cure ships with the pending consumer wave, not with this package.
- `Phlix\Shared\Relay\LivenessWindows` — the 3x liveness-margin law made
  executable (C-09/HB-0.1): a value object over ping-cadence/reap-window pairs whose
  `assertMargin()` refuses mis-coupled tunings at boot instead of reaping ghosts.
- `Phlix\Shared\Updates\{CoreUpdateStatus, VersionMarkerFetcherInterface}`,
  `Phlix\Shared\Metrics\MetricsRepositoryInterface`,
  `Phlix\Shared\Runtime\HookDeliveryException`,
  `Phlix\Shared\Console\JsonEnvelope` — the exact-twin lifts (0.57.0+): the
  updates-marker pair (S74/S75; camelCase admin-parser key order, fork-free marker
  fetch contract); the metrics read-side interface (S1/S4; window defaults pinned);
  the hook-delivery failure marker (S433: both failure shapes loud, an inconclusive
  check is never a pass); and the CLI `--json` envelope string kernels (key order,
  list normalization, throw-on-error; the Symfony-bound trait glue and
  `JSON_OUTPUT_MARKER` stay per-repo).
- `Phlix\Shared\Hub\HostGrammar` — promoted from an internal detail to a public
  stable surface at 0.57.0 (M-34): its host/URL shape law is now contractually
  consumer-facing and reshaping it is a major-bump event; the promotion status is
  pinned by a reflection suite that reddens if the old `@internal` marker returns.

- `Phlix\Shared\Schema\SchemaPaths` — pure path resolver for the bundled `schemas/` files (0.7.0+).
- `Phlix\Shared\Version` — the single `VERSION` constant consumers pin and report.

### Bundled schemas

The package ships the canonical JSON files used by both phlix-server and the
admin SPA under `schemas/` (resolve their absolute paths via the dedicated
`Phlix\Shared\Schema\SchemaPaths` accessors — `serverSettings()`, `hubSettings()`,
`webhookEvents()`, `mediaItem()`, `libraryQuery()`, `manifest()` — with
`SchemaPaths::dir()` reserved for the directory itself):

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
  added since each carry an explicit flag too. As of 0.53.0 the schema held 84 keys (51 true /
  33 false); the cookie-program trio (`auth.cookie.enabled`, `auth.cookie.insecure`,
  `auth.ws_query_token` — all live reads) shipped in v0.55.0 — that release's schema is
  byte-identical to the current one — bringing it to 87 keys: 51 carry
  `restart: true` and 36 carry `restart: false`. `restart: true` means the
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

## Adding a server settings key (runbook)

The schemas are this repo's whole job; touching one means touching its test
locks in the same commit. `tests/Schema/ServerSettingsSchemaTest.php` holds
**three** locks, and every one of them fails loudly with guidance text —
this runbook is those steps written down before you hit them:

1. **Schema JSON.** Add the property to `schemas/server-settings.schema.json`.
   The key must be a dotted path resolvable by the server's
   `SettingsRepository::getDefault()` (leading run = a `config/` file, longest
   file path wins, every file segment jailed to `/^[A-Za-z0-9_-]+$/` — see the
   Key-naming contract above). Give it `label`, `helpText`, `group`, `tier`,
   and an **explicit** `restart` (`true`/`false` — the unset count is pinned
   at 0); mark credentials `secret: true`.
2. **Roster lock.** Add the key's row to `ServerSettingsSchemaTest::propertyProvider()`
   (`'key' => ['key', '<json-type>']`) and bump the `assertCount(N, $actual)`
   in `test_schema_has_exactly_the_expected_property_keys` — the roster test
   asserts the schema declares exactly the documented keys, set-equality plus count.
3. **Pin lock.** Add the key's full-value row to `ServerSettingsSchemaTest::pinTable()`:
   type / default / bounds / enum / restart / secret, each verified to reproduce
   deployed behavior before it ships (the `schemaPinProvider()` data-provider test
   checks the schema against this table, and
   `test_pin_table_covers_exactly_the_schema_properties` set-equality refuses a
   schema key without a pin row — its throw message is the deliberate "add the
   rows deliberately" guidance).
4. **Naming/keyword laws.** The remaining pins (first-segment config-file name,
   forbidden key namespaces, secret-credential list, label/help presence,
   enum-option documentation, numeric bounds presence) run per key and per
   header; read their failure messages, do not widen them.
5. **Consumer rule.** Keys are deleted through `CONSUMERLESS_KEY_DENYLIST`
   (deletion record; a deny-listed key must not be re-added). A new key must
   therefore have a real consumer in `phlix-server` reading the effective value —
   adding schema surface nothing reads is how key-count theater starts.
6. **Hub keys differ.** For `schemas/hub-settings.schema.json`, the property
   keys must stay set-equal with `phlix-hub`'s `HubSettingsRepository::ALLOWED_KEYS`
   (see Bundled schemas above); the two-sided drift guard is
   `tests/Schema/HubSettingsSchemaTest.php` (live via a hub checkout or the
   `PHLIX_HUB_REPO` pointer; CI runs the frozen mirror leg).
7. **Verify + release.** `./vendor/bin/phpunit`, `phpstan analyze`, both phpcs
   legs, `./vendor/bin/psalm --no-progress`, `composer validate --strict` and
   `php scripts/security-audit-check.php` — all green in the same commit. This
   runbook lists the schema-relevant gates; the complete eight-command list is
   in AGENTS.md "Verify before committing". Release is lockstep:
   `Version::VERSION` = CHANGELOG heading = annotated git tag
   (AGENTS.md "Versioning"); consumers then bump their `^0.x` pin.

## Requirements

- PHP `^8.3` — CI-verified on PHP 8.3 and 8.4 (phpcs, phpstan, psalm and
  phpunit each run both legs; PHP 8.5 is not in the matrix because the
  toolchain's locked vimeo/psalm 6.x declares a `~8.4.0` ceiling); declared
  compatible below PHP 9.0.
- Composer 2.x
- `psr/clock ^1.0`
- `psr/container ^2.0`
- `psr/event-dispatcher ^1.0`
- `psr/log ^3.0`
- Optional (`suggest`): `ext-sodium` — needed only to load standard RFC 8410
  PKCS#8 Ed25519 private keys through `Security\Ed25519Pem` (the native
  `ED25519 PRIVATE KEY` dialect and seed-only extraction parse without it;
  an extension-less build gets a named `RuntimeException`, never an
  undefined-function fatal).
- Contributors only (`require-dev`): `ext-curl` — the test suite exercises `CurlArrTransport`.

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
see [`AGENTS.md`](AGENTS.md) in the git checkout).

## Installation

`detain/phlix-shared` is published on Packagist:

```bash
composer require detain/phlix-shared:^0.59
```

## Package layout

The split is charter-driven: each top-level namespace under `src/` is
exactly the contract one consumer surface needs, and nothing more.
`Auth/`, `Hub/` and `Relay/` mirror the server↔hub wire (token and claim
types, protocol DTOs, tunnel and HTTP-over-relay envelopes); `Plugin/` and
`Events/` are the plugin-facing surface (lifecycle, manifest, PSR-14 event
DTOs whose short manifest event aliases map in `Plugin/EventNameMap`);
`Metadata/` and `Subtitle/` hold the two plugin source contracts the
server's registries consume; `Arr/` is the sole sanctioned I/O exception
(*arr clients behind a pluggable transport seam); `Console/`, `Database/`,
`Http/`, `Metrics/`, `RateLimit/`, `Runtime/`, `Settings/` and `Updates/`
carry the v0.57 consolidation wave's shared server/hub kernels (CLI JSON
envelope, MySQL transaction/retry/upsert state, HTTP wire laws, the
metrics read contract, rate limiting, hook-delivery failures, the settings
value codec, update-check results); `Schema/`, `Security/` and `Support/`
are shared helpers (schema-path resolution, the HMAC/JWT/Ed25519/PKCE
seams, and the env/clock/backoff/JSON utilities plus the wire-DTO
assertion trait); `Version.php` is the single version marker consumers
pin. A new helper belongs in the namespace of the contract it serves — if
it serves none of them, it likely belongs in the consumer's own repo. (The
longer contributor-facing version, including the layout tree, lives in
[`AGENTS.md`](AGENTS.md) in the git checkout; the historical
WHAT-MOVES-WHERE table in `plans/expansion/b.1-shared-design.md` of
[`detain/phlix`](https://github.com/detain/phlix) is history-only and not
part of the estate checkouts.)

## Consumer examples

The three highest-friction surfaces — *arr client construction, the
HTTP-over-relay framing sequence, and the settings value codec — as
copy-pasteable snippets mirroring the tested call shapes in `src/`.
[`tests/ReadmeExamplesTest.php`](tests/ReadmeExamplesTest.php) enforces the
pairing: it greps every call shape below out of this file, resolves every
`use` line against the autoloader, and pins the reflection arity of each
constructor and static method the snippets lean on — so an API rename cannot
land without these examples being updated in the same commit.

### *arr clients: factory construction

`ArrClientFactory` builds clients from a config array: each section carries
`url` / `api_key` / `enabled`, a disabled section yields `null` from the
matching `create*()`, and an enabled one with an empty `api_key` also returns
`null` after warning through the optional logger:

```php
use Phlix\Shared\Arr\ArrClientFactory;

$factory = new ArrClientFactory([
    'sonarr' => ['url' => 'http://sonarr.local:8989', 'api_key' => 'sonarr-api-key', 'enabled' => true],
    'radarr' => ['url' => 'http://radarr.local:7878', 'api_key' => 'radarr-api-key', 'enabled' => true],
]);

$sonarr = $factory->createSonarrClient();        // ?SonarrClient
$radarr = $factory->createRadarrClient($logger); // ?RadarrClient; $logger is an optional ?Psr\Log\LoggerInterface

$profiles = $radarr?->getQualityProfiles();      // this is the first line that performs real HTTP
```

Without a second constructor argument the factory hands clients the bundled
blocking `CurlArrTransport` — strictly the right shape for CLI scripts and test
rigs, and elsewhere a tolerated worker STALL (up to the request timeout, per
call) rather than a prohibited construction: phlix-hub's DI builds the factory
transport-less today, so hub workers run exactly that default. Cooperative
event-loop consumers (Workerman/Webman hosts and the hub alike) should inject
one `ArrTransportInterface` into the factory and every client it builds
shares it; the factory's class docblock carries the full posture truth.

### *arr clients: the transport seam

The seam is one method: raw `Name: value` header strings in, shaped
`['status' => int, 'body' => string]` out. Implementing it over your own
event-loop HTTP client is what keeps the `Arr\*` clients cooperative on
Workerman/Swoole runtimes. This example answers canned bytes, so it runs
hermetically — no network:

```php
use Phlix\Shared\Arr\RadarrClient;
use Phlix\Shared\Arr\Transport\ArrTransportInterface;

$transport = new class implements ArrTransportInterface {
    public function request(string $method, string $url, array $headers, ?string $body): array
    {
        return ['status' => 200, 'body' => '[]'];
    }
};

// Ctor law: (baseUrl, apiKey, ?logger, timeout, ?transport, connectTimeout).
// Non-http(s) base URLs and API keys carrying line breaks throw
// RuntimeException here, before any request is built.
$client = new RadarrClient('http://radarr.local:7878', 'radarr-api-key', null, 30, $transport);
$profiles = $client->getQualityProfiles(); // decoded from the canned '[]' above
```

### HTTP-over-relay: the `HEAD -> BODY* -> END` sequence

One relayed message is a HEAD payload (tag + JSON head), zero or more BODY
payloads (tag + raw bytes — `chunkBody()` splits at `MAX_BODY_CHUNK`, and an
empty body yields no BODY frames), then one END payload. `decode()` answers
a typed `RelayHttpRequestChunk` (`kind` plus `head`/`body`) per payload; the
response direction is the mirror through the `RelayHttpResponse*` trio:

```php
use Phlix\Shared\Relay\RelayHttpRequestChunk;
use Phlix\Shared\Relay\RelayHttpRequestCodec;
use Phlix\Shared\Relay\RelayHttpRequestHead;
use Phlix\Shared\Relay\RelayHttpResponseChunk;
use Phlix\Shared\Relay\RelayHttpResponseCodec;
use Phlix\Shared\Relay\RelayHttpResponseHead;

$requestBody = '{"name":"Films"}';

$payloads = array_merge(
    [RelayHttpRequestCodec::encodeHead(new RelayHttpRequestHead('POST', '/api/v1/libraries', '', ['Content-Type' => 'application/json']))],
    RelayHttpRequestCodec::chunkBody($requestBody),
    [RelayHttpRequestCodec::encodeEnd()]
);

$method = null;
$reassembled = '';
$complete = false;
foreach ($payloads as $payload) {
    $chunk = RelayHttpRequestCodec::decode($payload);
    if ($chunk->kind === RelayHttpRequestChunk::KIND_HEAD) {
        $method = $chunk->head?->method;  // RelayHttpRequestHead properties: method/path/query/headers
    } elseif ($chunk->kind === RelayHttpRequestChunk::KIND_BODY) {
        $reassembled .= $chunk->body;
    } else {
        $complete = true;                 // RelayHttpRequestChunk::KIND_END
    }
}
// $method === 'POST', $reassembled === $requestBody, $complete === true.

$responseBody = '{"id":1}';
$responsePayloads = array_merge(
    [RelayHttpResponseCodec::encodeHead(new RelayHttpResponseHead(200, ['Content-Type' => 'application/json'], null))],
    RelayHttpResponseCodec::chunkBody($responseBody), // $bodyLength null = streaming: length unknown at HEAD time
    [RelayHttpResponseCodec::encodeEnd()]
);

$receivedStatus = null;
$receivedBody = '';
foreach ($responsePayloads as $responsePayload) {
    $responseChunk = RelayHttpResponseCodec::decode($responsePayload);
    if ($responseChunk->kind === RelayHttpResponseChunk::KIND_HEAD) {
        $receivedStatus = $responseChunk->head?->status;
    } elseif ($responseChunk->kind === RelayHttpResponseChunk::KIND_BODY) {
        $receivedBody .= $responseChunk->body;
    }
}
```

Envelope-level framing (putting these payloads into `RelayFrame`s under the
64 KB cap) is `FrameCodec`'s job; and in the request direction a received
head must be parsed with `RelayHttpRequestHead::fromJsonGated()` — never the
ungated `fromJson()` — before it is trusted, because the head carries
caller-supplied headers. The reassembled request must then clear
`RelayHttpRequest::assertSafe()`.

### Settings value codec

The persisted-text round-trip. The `$valueType` argument is exactly the
settings-schema family `string|int|bool|float|json`; anything outside that
vocabulary throws `InvalidArgumentException` in both directions — an unknown
type is a programmer error or a corrupt stored row, never silently passed
through. The `json` arms are strict: corrupt text raises `JsonException`
rather than yielding a silent `null`:

```php
use Phlix\Shared\Settings\SettingsCodec;

$stored = SettingsCodec::encode(['tmdb', 'imdb'], 'json'); // '["tmdb","imdb"]'
$restored = SettingsCodec::decode($stored, 'json');        // ['tmdb', 'imdb']

SettingsCodec::encode(7, 'int');                        // '7'
SettingsCodec::decode('1', 'bool');                     // true  (the persisted '1'/'0' leg)

// The two pure reader primitives. An override wins whenever it EXISTS, so a
// stored false/0/'' is a real setting and only null falls through to the
// default; the clamp is inclusive on both bounds.
SettingsCodec::effective(null, 'midnight');             // 'midnight'
SettingsCodec::effective('nocturne', 'midnight');       // 'nocturne'
SettingsCodec::clampValue(42, 1, 30);                   // 30
```

Every statement above runs hermetically against a local install — no
transport, no database, no network.

## Related repositories

- [`detain/phlix-server`](https://github.com/detain/phlix-server) — the Phlix media server (consumes this package).
- [`detain/phlix-hub`](https://github.com/detain/phlix-hub) — the multi-server hub + reverse-tunnel relay.
- Usage guides — the developer pages under [`detain/phlix-docs`](https://github.com/detain/phlix-docs/tree/main/docs/dev) (e.g. `shared-schemas.md`, `arr-clients.md`, `relay-protocol.md`).

## Development

Contribution on-ramp for humans: [`CONTRIBUTING.md`](CONTRIBUTING.md)
restates the gates and version ritual below for people and ships inside the
composer package; in the git checkout [`AGENTS.md`](AGENTS.md) remains the
binding authority (it is excluded from the archive).

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

CI runs the gates above — the same list as the "Verify before committing" ritual in
[`AGENTS.md`](AGENTS.md) — at the error level: a failing
phpunit/phpstan/phpcs/psalm/audit run reddens the build. That enforcement is
error-level, not literal, and the known leniencies are: the psalm leg runs
with `--show-info=false` (info-level findings do not redden CI),
`composer validate` runs with `--no-check-publish` (publish-metadata-only
failures do not either), and the `tests/` phpcs gate counts its known
LineLength warnings but exits on errors only (`ignore_warnings_on_exit` in
[`phpcs-tests.xml`](phpcs-tests.xml)). Beyond those gates the convention
prose in this README and in [`AGENTS.md`](AGENTS.md) is upheld by review,
not machine-checked line by line. The `composer` scripts `test`, `stan`,
`cs`, `cs-tests` and `psalm` alias the vendor-binary lines.

The default `phpunit` run excludes the opt-in `network` group — the
helpLinks liveness probes, which make real HTTP requests — so the suite
stays offline and deterministic (the exclusion is declared in
[`phpunit.xml`](phpunit.xml)); run those tests deliberately with
`./vendor/bin/phpunit --group network`. CI runs neither the group nor
anything referencing it.

⚠ The last step replaces `composer audit --no-dev`. **Never audit with a
development-dependency exclusion:** `--no-dev` drops every `require-dev` package
from the audited set, and it hid CVE-2026-67434 (HIGH, OS command injection) in
`squizlabs/php_codesniffer` — a `require-dev` package — so CI reported SUCCESS
against a vulnerable lock. `scripts/security-audit-check.php` audits the whole
lock, labels each finding `[require]` / `[require-dev]`, and prints the number of
packages it examined so a truncated audit cannot pass as a clean one. See
[`AGENTS.md`](AGENTS.md) (in the git checkout) for the full policy.

The `phpunit` job in [`.github/workflows/ci.yml`](.github/workflows/ci.yml) uploads
`./coverage.xml` to both Codecov and Codacy.

## License

MIT — see [`LICENSE`](LICENSE).
