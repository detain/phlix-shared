# Changelog

All notable changes to `detain/phlix-shared` are documented here.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.23.0] - 2026-07-20

### Added
- Phase 2B: `metadata.preferred_language`, `metadata.preferred_country`, `metadata.fanart_api_key`
- Phase 2C: `database.pool_size`, `database.timeout`, `relay.reconnect_delay`, `relay.ping_interval`, `hls.segment_seconds`, `hls.max_concurrent_segments`
- Phase 3: `transcoding.segment_max_inflight_global`, `transcoding.segment_cache_max_age`, `transcoding.segment_cache_max_bytes`, `transcoding.stale_job_max_age`
- Phase 4: `subsystem.library_scan_enabled`, `subsystem.plugin_auto_update_enabled`, `subsystem.marker_detection_enabled`, `subsystem.media_asset_jobs_enabled`, `subsystem.similarity_enabled`
- Phase 5: `auth.enabled`, `auth.rate_limit`, `auth.session_lifetime`

## [0.22.0] - 2026-07-20

### Added
- Phase 2A: 7 new transcoding settings — `transcoding.preferred_accelerator`, `transcoding.include_software_fallback`, `transcoding.tone_mapping_mode`, `transcoding.prefer_hdr_output`, `transcoding.max_concurrent_transcodes`, `transcoding.transcode_timeout`, `transcoding.max_concurrent_scan_probes`
- **`schemas/server-settings.schema.json`** — extended with optional UI-metadata keywords:
  `label`, `helpText`, `helpLinks`, `tier`, `secret`, `restart`, `enumLabels`, and
  `optionHelp` so the admin settings UI can render per-option help text and split
  Standard vs Advanced options (Phase 0; commits `0f8747e`–`eda28cd`).
- **`schemas/hub-settings.schema.json`** — new JSON Schema (draft 2020-12) scaffold for hub
  configuration, resolved via the new `SchemaPaths::hubSettings()` path helper (Phase 0;
  commits `0f8747e`–`eda28cd`).
- **`src/Schema/SchemaPaths::hubSettings()`** — new path helper returning the absolute path
  to `schemas/hub-settings.schema.json`, mirroring the existing `serverSettings()` method
  (Phase 0; commits `0f8747e`–`eda28cd`).
- **`tests/Schema/HubSettingsSchemaTest.php`** — new test file covering hub-settings schema
  validation (Phase 0; commits `0f8747e`–`eda28cd`).
- **`tests/Schema/ServerSettingsSchemaTest.php`** — extended with tests for the new
  `label`, `helpText`, `helpLinks`, `tier`, `enumLabels`, and `optionHelp` keywords
  (Phase 0; commits `0f8747e`–`eda28cd`).

### Fixed
- **`@since` version annotation** on affected schema classes corrected (Phase 0;
  commit `eda28cd`).
- **Misleading `@covers` annotations** removed from test files (Phase 0; commit `eda28cd`).

## [0.21.0] - 2026-07-20

### Added
- **`schemas/hub-settings.schema.json`** — new schema for hub configuration, alongside
  `server-settings.schema.json` gaining `help`/`tier` keywords so the admin settings UI can render
  per-option help text and split Standard vs Advanced options (Phase 0).
- **`schemas/server-settings.schema.json`** — `lastfm.*` keys.
- **`schemas/media-item.schema.json`** — five new OPTIONAL, nullable detail-only fields the server now
  emits on `GET /api/v1/media/{id}`: `trailer_url`, `trailer_key`, `trailer_site`, `logo_url`, and
  `still_url` (episodes only). All are absent from the lean list responses and null when unavailable,
  so existing consumers are unaffected.

### Changed
- **Relay protocol docs** — the `HTTP_CANCEL` (`0x12`) frame contract is now documented.
- **`schemas/media-item.schema.json`** & **`schemas/library-query.schema.json`** — the content-rating
  vocabulary is expanded (Phase C) to cover the US TV Parental Guidelines scale alongside the existing
  MPAA film scale. The `rating` enum (media-item) and the `ratings[]` filter enum (library-query) now
  accept `TV-Y, TV-Y7, TV-G, TV-PG, TV-14, TV-MA` in addition to `G, PG, PG-13, R, NC-17, X, UNRATED`.
  `NR` is normalized to `UNRATED` server-side and is deliberately not part of the vocabulary. Purely
  additive — existing consumers using only the film-scale values are unaffected.

## [0.20.0] - 2026-07-12

### Added
- **`Phlix\Shared\Relay\RelayHttpRequestHead`** / **`RelayHttpRequestCodec`** /
  **`RelayHttpRequestChunk`** — chunked relay request bodies. Published as a tagged release so
  phlix-server and phlix-hub can consume it from Packagist instead of a local path repository.
  (Release cut from master; this entry was written retroactively in 0.21.0.)

## [0.19.0] - 2026-07-10

### Added
- **`schemas/manifest.schema.json`** — plugin settings entries now accept two optional
  field-help keys: **`link`** (a `format: uri` URL where the operator obtains the value — e.g. an
  API-key signup or docs page) and **`link_text`** (optional anchor text). The settings-entry
  schema is `additionalProperties: false`, so a plugin declaring these keys previously failed
  manifest validation (`plugin.enable`/install "manifest is invalid"); they are now first-class,
  letting a plugin ship a "where to get this" link in its own `plugin.json` (rendered by the admin
  configure form in phlix-ui ≥ 0.79.0). Consumed server-side by `SettingsMasker::schema()`.

## [0.18.0] - 2026-07-10

### Added
- **`Phlix\Shared\Plugin\ConfigurableInterface`** — optional contract a plugin entry class
  implements to receive its persisted `settings_json` map from the host. The host loader
  instantiates entry classes through its PSR-11 container, so their constructors must stay
  autowirable (they cannot take the settings array or a bare `string $apiKey` as a required
  parameter — the container cannot guess those values and resolution fails). Plugins now keep an
  all-optional constructor and receive configured values through `configure(array $settings): void`,
  which the loader calls once between construction and `LifecycleInterface::onEnable()`. Implementing
  the interface is optional; a plugin that needs no configuration omits it.

## [0.16.0] - 2026-07-06

### Fixed
- **`Phlix\Shared\Auth\JwtClaims::isExpired()`** — an earlier same-day commit (`40bf16d`,
  "Fix blocking I/O and add micro-optimizations") mistakenly changed the default `$now` from
  `time()` to `(int) (hrtime(true) / 1_000_000_000)`. `hrtime(true)` is a **monotonic** clock
  measured from an arbitrary reference point (e.g. system boot), not Unix epoch time, so it is
  not comparable to the `exp` claim (which is epoch seconds). This made every token appear
  expired (or never-expired, depending on uptime) and broke CI (`JwtClaimsTest`). Reverted to
  `time()`, with a docblock explaining why `hrtime()` must never be used here.
- **`Phlix\Shared\Arr\TrashGuidesProvider::deriveVersionFromUrl()`** fallback — the same commit
  switched the `'unknown-' . time()` fallback version label to use `hrtime(true)` as well. Unlike
  the internal cache-TTL bookkeeping (which is a self-consistent monotonic duration check and is
  fine), this value is a human-facing/loggable "version" string, so it needs real wall-clock time
  to be meaningful across process restarts. Reverted to `time()`.

### Changed
- **`Phlix\Shared\Arr\Transport\CurlArrTransport`** now reuses a single static `cURL` handle
  across requests (`curl_reset()` between calls) instead of calling `curl_init()`/`curl_close()`
  on every request, avoiding repeated handle-setup overhead. This transport remains documented as
  blocking/CLI-and-test-only; it is never invoked from inside the Workerman event loop.
- **`Phlix\Shared\Arr\TrashGuidesProvider`** cache-TTL bookkeeping (`$cacheTimestamp` in
  `getQualityProfiles()`/`getCustomFormats()`/`getVersion()`/`ensureCacheValid()`) now uses
  `hrtime(true)` (nanosecond monotonic time) instead of `time()`. This is a self-consistent
  duration calculation (both the stored timestamp and the comparison point come from the same
  clock), so monotonic time is safe and slightly cheaper here — unlike the `JwtClaims`/version
  cases above, which compare against externally-supplied wall-clock values.
- **`Phlix\Shared\Arr\SecretRedactor::redact()`** now builds the secrets/replacements arrays up
  front and calls the array form of `str_replace()` once, instead of looping and calling
  `str_replace()` once per secret.
- **`Phlix\Shared\Schema\SchemaPaths::dir()`** now caches the computed `dirname(__DIR__, 2) .
  '/schemas'` path in a static `non-empty-string` property instead of recomputing it on every
  call.

### Added
- **`Phlix\Shared\Support\PayloadAssert::optionalInt()`** and **`optionalBool()`** — new optional
  (default-falling-back) assertion helpers, mirroring the existing `optionalString()`/
  `requireInt()`/`requireBool()` shape, for DTOs that need a validated-but-optional int/bool
  payload key.

## [0.15.0] - 2026-06-30

### Added
- **`Phlix\Shared\Metadata\MetadataSourceInterface`** (Feature 3, Step 3.5a) — first-class, typed
  contract a metadata-source plugin implements so the server's source registry (Step 3.5b) can
  register/deregister it on plugin enable/disable **without** the brittle `method_exists()` /
  FQCN-sniffing convention used today. Declares `sourceName(): string` (the canonical priority-map
  identity — e.g. `anidb`/`myanimelist`/`tmdb`, the value that appears in `metadata.provider_priority`
  and a resolved record's `source`), `supportedMediaTypes(): list<string>` (the media-type slugs the
  registry indexes the source under), and the lookup triad `search()` → `getDetails()` / `getImages()`
  mirroring the host's existing provider-driving shape. Implementations must be non-blocking on a
  resident-memory (Workerman) host. Ships the interface + a contract test only; the server-side
  `SourceRegistry` and the anidb/myanimelist conversion are Step 3.5b.

## [0.14.0] - 2026-06-29

### Added
- **`metadata.provider_priority` server-settings schema key** (Feature 3, Step 3.3a) — per-media-type
  ordered metadata source priority. A JSON object keyed by media type (`movie`, `series`, `anime`, …)
  whose values are ordered arrays of source-name strings (the `sourceOrder` fed to
  `PriorityFieldResolver`). `additionalProperties` allows arbitrary media-type keys; an absent type
  falls back to the server config default. Default map: `movie`/`series` → `["tmdb","imdb"]`,
  `anime` → `["anidb","myanimelist","tvdb","fanart","local"]`. Group `metadata`.
- **`metadata.genres_mode` server-settings schema key** (Feature 3, Step 3.3a) — string enum
  `["first","union"]` (default `first`) controlling whether the genres field takes the first
  non-empty source or the union of all sources (`PriorityFieldResolver` `genresMode`). Group `metadata`.

## [0.13.0] - 2026-06-29

### Added
- **`matching.noise_suffixes` server-settings schema key** (Feature 13, Step 13.3a) — an array of
  strings: the admin-extensible list of trailing "noise" phrases (`Directors Cut`, `UNCUT & UNRATED`,
  `YIFY`, `DC`, …) stripped from a filename-derived title before metadata matching. A replace-not-merge
  override; an empty array falls back to the server's code defaults. Group `matching`. Consumed by
  phlix-server's shared `TitleSuffixStripper` (movie + series parsers).

## [0.12.0] - 2026-06-29

### Fixed
- **Version.php sync** — `Phlix\Shared\Version::VERSION` was left at `0.11.0` after the v0.11.1
  tag; corrected to `0.12.0` to keep the constant in lockstep with the git tag.

## [0.11.1] - 2026-06-29

### Added
- **`Hub\LibraryRef` DTO** (findings B3/F3) — new `LibraryRef::fromPayload(array): self` factory
  that validates the `name`, `version`, and `url` fields of a library entry. `HeartbeatDto`
  now exposes `libraries: LibraryRef[]` (strict typed array) alongside the legacy
  `mixed` `libraries` property for a safe migration path.
- **`Hub\PayloadAssert` trait** (finding CQ3) — extracted `requireString()`, `requireInt()`,
  `requireBool()`, `requireArray()` and `requireArrayOfStrings()` helpers for consistent,
  typed payload validation across DTOs. Replaces scattered `is_*()` + exception-throw patterns.
- **`Events\Abstraction\AbstractEvent` PSR-20 `ClockInterface` seam** (findings B6/F5) — the
  abstract event now accepts an optional `?ClockInterface $clock` constructor parameter; when
  provided the event uses it for `getTimestamp()`, allowing deterministic time in tests and
  alternate time sources in non-system-clock contexts. Additive, BC-safe.
- **`Hub\Manifest::chunkBodyIterator(): \Generator`** (Phase 4 remaining) — streams a
  Manifest's body in fixed-size chunks without loading it all into memory, for large payloads.
- **`Hub\Hmac` helper** (Phase 4 remaining) — `Hmac::compute(string $algo, string $data, string
  $key): string` utility with constant-time comparison support.
- **`Events\EventNameMap` memoization** (Phase 4 remaining) — `EventNameMap::get()` now
  caches results, avoiding repeated string comparisons on hot paths.
- **S2/S3 protocol hardening** (findings S2/S3) — scheme validation now rejects non-`https`
  URLs except `localhost`; connect timeout is pinned; secret redaction in `RelayHttpRequest`
  serialisation is improved.

### Changed
- **`Arr\AbstractArrClient` protocol pinning** (findings S2/S3) — outbound requests now
  pin to TLS 1.2+ and validate the host header to prevent redirect bypass.

### Deprecated
- **`Hub\Manifest::toArray(): array`** (findings B5/CQ4) — marked `@deprecated` with a
  tracking note; a final-flip to `toPayload()` will follow in a later release.

### Fixed
- **JSON decode depth** (finding B2) — raised `json_decode()` depth limit from `8` to `512`
  to handle deeply-nested webhook payloads without silent `null` returns.

## [0.11.0] - 2026-06-28

### Added
- **`Relay\RelayHttpRequest` security gate** (findings S1/F1) — the untrusted,
  hub-tunnelled HTTP envelope now self-validates its method and path:
  - `assertSafe(): void` throws `InvalidArgumentException` on an unsafe method or
    path; it is invoked automatically at the end of `fromJson()` so every consumer
    that deserializes the wire envelope inherits the gate. Path rules reject
    missing leading `/`, protocol-relative `//…`, `..` (raw and percent-encoded
    `%2e%2e`), NUL (raw and `%00`), backslash, `://`, an embedded query (`?`) or
    fragment (`#`), and control characters (`< 0x20`). Method must be in
    `ALLOWED_METHODS` (case-insensitive).
  - `ALLOWED_METHODS` constant: `GET HEAD POST PUT PATCH DELETE OPTIONS`.
  - `STRIPPED_HEADERS` constant + `static isForbiddenHeader(string): bool`
    (case-insensitive) + `withoutForbiddenHeaders(): self` — expose the
    trust-bearing inbound header set (`x-phlix-relay-user`, `x-forwarded-for`,
    `authorization`, `cookie`) so the consumer can drop them before forwarding and
    inject the hub-validated owner identity itself. The DTO does NOT silently strip
    `x-phlix-relay-user` — identity injection remains the consumer's responsibility.
  - All changes are additive / backward-compatible; valid requests round-trip
    unchanged. **Consumer follow-up (phlix-server `RelayConsumer::buildRequest()`):**
    stop trusting `x-phlix-relay-user` from the envelope and strip forbidden headers
    via `isForbiddenHeader()` — that paired PR is the actual auth-bypass fix.
- **`Auth\JwtClaims::fromPayloadStrict(array): self`** (finding S4) — strict variant
  of `fromPayload()` that **throws** `InvalidArgumentException` when the `aud` claim is
  absent, instead of silently defaulting it to `AUD_SERVER`. `fromPayload()` keeps the
  v0.10.x backward-compat default for legacy tokens (existing behaviour unchanged);
  consumers can migrate to the strict variant once every issuer emits `aud`. Additive,
  BC-safe.
- **`Arr` async-transport seam** (findings B1/P1, CQ1, F2) — the *arr clients now route
  all HTTP I/O through an injectable transport, so the blocking cURL call lives behind a
  seam and event-loop (Workerman/Webman) consumers can avoid it entirely, honouring the
  package's "zero I/O" charter:
  - New `interface Arr\Transport\ArrTransportInterface` with a single
    `request(string $method, string $url, array $headers, ?string $body): array{status:int, body:string}`
    method. Implementations return the status + raw body (they do NOT throw on non-2xx;
    the client maps status codes).
  - New default `final Arr\Transport\CurlArrTransport` carrying the **blocking** cURL
    behaviour moved out of `AbstractArrClient::request()`. Documented as **CLI/test only**;
    event-loop consumers MUST inject an async, non-blocking transport.
  - `Arr\AbstractArrClient` gains an **optional, appended** constructor parameter
    `?ArrTransportInterface $transport = null`; when null it falls back to
    `new CurlArrTransport($timeout)`, so existing direct instantiation keeps working
    unchanged. All requests are dispatched through the transport — no `curl_exec()` runs
    when a transport is injected.
  - `Arr\ArrClientFactory` gains an optional appended `?ArrTransportInterface $transport`
    constructor parameter, propagated to every client it creates.
  - **`ArrClientInterface` is unchanged** — the transport is a constructor concern only,
    not a new interface method, so this is NOT a breaking change.
  - `composer.json`: the misleading absolute "zero I/O" claim is reconciled — the
    description now states the only bundled network code is the blocking `CurlArrTransport`
    (CLI/test only) and event-loop consumers must inject an async transport. `ext-curl`
    moved from `require` to `require-dev` + `suggest` (needed only for the default cURL
    transport); added a `suggest` for `workerman/http-client` for consumers wiring an
    async transport.
  - **Consumer follow-up (Wave 1+):** phlix-server will add a `workerman/http-client`-backed
    `WorkermanArrTransport` and inject it (via `ArrClientFactory`/direct construction) so
    *arr calls stop stalling the worker; phlix-hub audits its `RequestManager` usage the
    same way. Additive / BC-safe here.

### Changed
- **`Arr\AbstractArrClient` extraction** (findings CQ2/CQ5) — the four near-identical
  *arr clients (`RadarrClient`, `SonarrClient`, `ProwlarrClient`, `BazarrClient`) now
  extend a shared `abstract class AbstractArrClient` that owns the constructor
  (`baseUrl`/`apiKey`/`logger`/`timeout`), header building, the GET/POST/PUT/DELETE
  cURL methods, and the per-status-code error mapping. Subclasses keep only their
  endpoint-specific methods plus a `protected vendorName(): string` used in error
  messages. **No behaviour change** — still blocking cURL, identical public class
  names, methods, and thrown exceptions/messages; existing tests pass unchanged. This
  is the structural enabler for the F2b async-transport seam (transport injection then
  happens in one place). Internal refactor only — no consumer impact.

### Documentation
- **`Auth\JwtClaims` security & round-trip docs** (findings S4/B4):
  - Class docblock now prominently states `JwtClaims` performs **no signature
    verification** — it is a typed view over an already-decoded/verified payload;
    verifying the JWT signature (and rejecting `alg: none`) is the caller's
    responsibility (server/hub `JwtHandler`).
  - Documents the deliberate `toPayload()` asymmetry: null/empty optionals
    (`nbf`/`jti`/`scope`/`serverId`) are omitted from the array for legacy-decoder
    wire-compat, yet `fromPayload(toPayload($claims)) == $claims` remains lossless
    because `fromPayload()` re-applies the defaults. New round-trip object-equality
    tests cover both the all-fields and minimal-claims cases. No behaviour change.

## [0.10.1] - 2026-06-23

### Added
- **`ServerInfoDto.libraryCount`** (optional `?int`, default `null`) — the number of
  libraries a server last reported via heartbeat (from the hub's `server_libraries`
  cache). Round-trips through `fromPayload()`/`toPayload()`; absent/null tolerated so
  older payloads keep working. Lets the hub's "My Servers" UI show a real library
  count instead of "—".

## [0.10.0] - 2026-06-23

### Added
- **HTTP-over-relay protocol types** so the hub can proxy a browser's HTTP request
  to a paired media server over the existing reverse tunnel (Phase 1 of hub inline
  media browsing):
  - `Relay\RelayFrameType` gains `HTTP_REQUEST = 0x10` (hub → server) and
    `HTTP_RESPONSE = 0x11` (server → hub). The 4-byte frame field carries a
    per-request id; routing is keyed on frame type, so these never collide with
    the low client-channel ids used by raw `DATA` frames.
  - `Relay\RelayHttpRequest` — immutable request envelope (`method`, `path`,
    `query`, `headers`, `body`) carried as the JSON payload of an `HTTP_REQUEST`
    frame; `toJson()` / `fromJson()` (body base64-encoded so arbitrary bytes
    survive).
  - `Relay\RelayHttpResponseHead` — response head (`status`, `headers`,
    `bodyLength|null`) with `toJson()` / `fromJson()`.
  - `Relay\RelayHttpResponseCodec` + `Relay\RelayHttpResponseChunk` — the
    `HEAD → BODY* → END` chunk sub-framing inside an `HTTP_RESPONSE` frame
    payload, so a response larger than one 65535-byte frame streams across
    several frames (`MAX_BODY_CHUNK = 65534`). `bodyLength = null` + `END` keeps
    the framing usable for unknown-length streaming (Phase 3).

## [0.9.1] - 2026-06-20

### Changed
- **`schemas/manifest.schema.json`: allow per-setting `label` and `description`, and accept `integer`/`boolean` as aliases of `int`/`bool`.** Plugin `plugin.json` files set a `label` + `description` on each setting (so the admin "configure plugin" UI can render named, documented fields), but the settings schema set `additionalProperties: false` with only `type`/`required`/`secret`/`default` allowed — so every real plugin manifest (anidb, myanimelist, trakt) failed validation with `additionalProp` errors and the install was rejected (422 "manifest is invalid"). The settings-property schema now permits `label` (string) + `description` (string) and widens the value-`type` enum to also accept `integer`/`boolean`. Backward-compatible (strictly more permissive); the `type` value is UI metadata, not a strict cast.

## [0.9.0] — 2026-06-09

### Added
- `schemas/media-item.schema.json` — series hierarchy fields so the browse API
  can describe a TV/anime tree instead of a flat list:
  - `type` enum gains `season` (alongside the existing `series`/`episode`), so
    the discriminator can carry the full series→season→episode hierarchy.
  - `parent_id` (uuid|null) — the parent media item (episode→season→series);
    null for top-level items (movies, series). Browse surfaces request
    top-level items only so a series library shows shows, not every episode.
  - `season_number` (integer|null, min 0) — from `metadata_json.season`; season
    0 / a null number on a series episode denotes Specials.
  - `episode_number` (integer|null, min 0) — from `metadata_json.episode`;
    orders episodes within a season.
  - `episode_title` (string|null) — per-episode title, distinct from `name`.
- `schemas/library-query.schema.json` — query parameters for the new hierarchy
  navigation (and the previously-undocumented per-library scope):
  - `parentId` (uuid) — fetch the direct children (seasons/episodes) of one
    item for the series detail page.
  - `topLevel` (boolean) — return only items with no parent (movies + series),
    excluding seasons/episodes; ignored when `search` is set so search still
    spans the whole library.
  - `libraryId` (uuid) — documents the existing per-library scope parameter.

## [0.8.0] — 2026-06-01

### Added
- `schemas/library-query.schema.json` — JSON Schema (draft 2020-12) for the
  query parameters of the movie-list browse API (GET /api/v1/media). Covers
  `search`, `genres[]`, `yearFrom`, `yearTo`, `ratings[]`, `actors[]`,
  `sort`, `order`, `limit`, and `offset` — all optional, with `genres[]` and
  `ratings[]` using OR logic across multiple values, year ranges being
  inclusive, and sensible `default`/`minimum`/`maximum`/`maxLength` bounds on
  each field. Consumed by the Phase-B `ItemRepository::query()` implementation
  and the Vue browse page in Phase C.
- `schemas/media-item.schema.json` — JSON Schema (draft 2020-12) for a single
  media item returned by the browse API. Flattens and renormalizes the raw
  `metadata_json` column into stable, consumer-friendly top-level fields:
  `poster_url`, `genres`, `year`, `rating`, `runtime`, `overview`, `actors`,
  `director`, `created_at`, `updated_at`. `poster_url` is always included so
  cards render without additional data fetches. Consumed by the Phase-B API
  serializer and the Phase-C `MediaCard.vue` component.

## [0.7.0] — 2026-05-27

### Added
- `schemas/server-settings.schema.json` — JSON Schema (draft 2020-12) for the
  editable server settings exposed by phlix-server's
  `/api/v1/admin/settings` endpoint. Mirrors
  `Phlix\Server\Http\Controllers\Admin\AdminSettingsController::ALLOWED_KEYS`
  (the single source of truth for the writable allow-list) — 15 dotted setting
  keys with their JSON-Schema type, a `group` annotation, a `description`, and
  numeric `minimum`/`maximum` bounds where meaningful. phlix-server now derives
  its allow-list from this schema and the admin SPA renders the settings form
  from it. Runtime defaults are intentionally not declared here (they live in
  phlix-server `config/*.php` and are returned by the GET endpoint).
- `schemas/webhook-events.json` — canonical data catalog of the webhook event
  types a webhook subscription may select (7 supported user-subscribable types:
  `playback.started`, `playback.ended`, `library.updated`, `download.complete`,
  `recording.started`, `recording.stopped`, `alert`), each grouped and labeled,
  plus the internal `webhook.test` reserved type. Consumed by the admin SPA
  webhook picker and future server-side `events[]` validation. This is a plain
  data document, NOT a JSON Schema, and is DISTINCT from the plugin PSR-14
  events in `Phlix\Shared\Plugin\EventNameMap`. (Actual server-side emission of
  most of these event types is an unfinished backend gap to be wired in a later
  phase.)
- `Phlix\Shared\Schema\SchemaPaths` — pure (zero-I/O) helper that returns the
  absolute paths to the two bundled schema files, so consumers locate them
  inside `vendor/detain/phlix-shared/schemas/` without hardcoding vendor
  strings.

## [0.6.0] — 2026-05-26

### Added
- `schemas/manifest.schema.json` — the JSON Schema (draft 2020-12) for plugin
  `plugin.json` manifests, now bundled with the shared package. Previously the
  canonical copy lived in `phlix-server` at `docs/plugins/manifest.schema.json`
  (then briefly in `phlix-docs`); moving it here lets every consumer load it
  via Composer's `vendor/` rather than depending on a sibling docs checkout.
  Phlix-server's `Phlix\Plugins\Manifest\ManifestSchema::resolveSchemaPath()`
  now walks a candidate list and prefers
  `vendor/detain/phlix-shared/schemas/manifest.schema.json`, falling back to
  the legacy in-tree copy so older checkouts don't break.

### Fixed
- `Phlix\Shared\Arr\{BazarrClient,ProwlarrClient,RadarrClient,SonarrClient}` —
  Psalm errors cleaned up across all four clients:
  - `$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE)` — cast at the
    source kills both the `MixedAssignment` warning and the downstream
    `MixedOperand` on string concat in error messages.
  - Dropped the redundant `@var array<mixed, mixed>` docblock over
    `$decoded = json_decode(...)` so `is_array($decoded)` actually narrows the
    type (was tripping `DocblockTypeContradiction`).
  - Added inline `@var array<string, mixed>` at the return sites of public
    methods that surface JSON objects through the generic
    `array<mixed, mixed>` `get()` / `post()` helpers
    (`Bazarr::downloadSubtitle`, `Prowlarr::getIndexerStats`,
    `Radarr::getMovieById` / `addMovie`, `Sonarr::getSeriesById` /
    `getEpisodeFile` / `addSeries`).
  - `@psalm-suppress MixedAssignment` on the two
    `$id = $response['id'] ?? null` reads in `RadarrClient::createCustomFormat`
    and `createQualityProfile` — the `is_numeric($id)` guard immediately
    afterwards makes the mixed value safe.

### Changed
- `composer.json` — dropped the `"version"` field. Tags drive the package
  version now; keeping the field was tripping `composer validate --strict` on
  CI.
- `README.md` — added the standard badge row (CI / PHP 8.3+ / PHPStan level 9
  / Psalm level 1 / PSR-12 / MIT). Fixed the broken `detain/phlix` pointer to
  `detain/phlix-server`. Bumped the status section + installation example
  (HTTPS VCS URL, `^0.6` constraint).

## [0.5.1] - 2026-05-25

### Changed
- **Relay protocol (multi-client / "relay-mux"):** the 4-byte leading field of
  a binary relay frame (`RelayFrame::$seq`) is now defined as a per-client
  **channel id (uint32)** for the client-scoped frame types
  (`CLIENT_CONNECT`, `CLIENT_DISCONNECT`, `DATA`) instead of a per-tunnel
  sequence counter. The tunnel runs over a single reliable WS/TCP stream, so
  no ack/reordering counter is needed and the field is repurposed for
  multiplexing. Tunnel-scoped frames (`HEARTBEAT`, `HELLO_ACK`,
  `DISCONNECTED`, `ERROR`) use channel 0. The binary header layout is
  unchanged — only the semantics of the leading field. This lets the hub and
  server demultiplex multiple concurrent remote clients over one tunnel, fixing
  the previous single-active-client cross-talk limitation. Pre-release change,
  no backward compatibility (no flags/shims).
- `Phlix\Shared\Relay\RelayFrame::channelId()` — new accessor that reads the
  `seq` field as a channel id. Docblocks on `RelayFrame`, `RelayFrameType`, and
  `RelayWireCodecInterface` updated to define the channel-multiplexing contract.

## [0.5.0] — 2026-05-24

### Added
- `Phlix\Shared\Relay\RelayFrameType` — PHP 8.3 backed enum with 8 frame type
  constants (HELLO=0x01 through ERROR=0x08) for the multiplexed WS relay
  protocol.
- `Phlix\Shared\Relay\RelayWireCodecInterface` — interface for encode/decode
  operations on relay frames. Defines `encode()`, `encodeHello()`,
  `encodeHelloAck()`, and `decode()`.
- `Phlix\Shared\Relay\RelayFrame` — immutable value object representing a
  relay frame: `type (RelayFrameType)`, `seq (int)`, `payload (string)`.

### Fixed
- `Phlix\Shared\Arr\{RadarrClient,SonarrClient,BazarrClient,ProwlarrClient}::get()`
  now returns `[]` on an empty HTTP response body instead of throwing
  `RuntimeException('Invalid JSON response from …')`. Matches the existing
  `post()` / `put()` / `delete()` behaviour. *arr APIs legitimately return
  `200 OK` with an empty body for collection endpoints when no rows exist
  (e.g. `GET /api/v3/customformat` on a fresh Radarr install), which used
  to break `CustomFormatSyncer` and any other consumer of `getCustomFormats()`,
  `getMovies()`, `getQueue()`, etc.

### Changed
- `Phlix\Shared\Plugin\EventNameMap::toAlias()` now caches the inverted
  alias map in a private static property instead of calling `array_flip()`
  on every invocation. Behaviour is unchanged; the optimisation matters for
  callers that resolve aliases in tight loops (doc generators, debug
  serialisers).

## [0.4.0] — 2026-05-18

### Added
- `Phlix\Shared\Arr\ArrClientInterface` — common interface for Sonarr/Radarr
  HTTP clients (queue, quality profiles, tags, test-connection).
- `Phlix\Shared\Arr\ArrClientFactory` — factory that instantiates Sonarr/Radarr
  clients from instance config arrays.
- `Phlix\Shared\Arr\SyncResult` — immutable value object returned by sync flows.
- `Phlix\Shared\Arr\SonarrClient` — typed Sonarr v3 HTTP client.
- `Phlix\Shared\Arr\RadarrClient` — typed Radarr v3 HTTP client.
- `Phlix\Shared\Arr\BazarrClient` — typed Bazarr HTTP client.
- `Phlix\Shared\Arr\ProwlarrClient` — typed Prowlarr HTTP client.
- `Phlix\Shared\Arr\TrashGuidesProvider` — fetches TRaSH-Guides quality
  profile + custom format JSON.
- `psr/log` runtime dependency to allow optional PSR-3 loggers on the arr
  clients without pulling phlix-server's concrete `StructuredLogger`.

### Changed
- Arr classes now type-hint `Psr\Log\LoggerInterface` instead of phlix-server's
  `StructuredLogger`, allowing the hub and any other PSR-3 consumer to inject
  its own logger. Required for Step K.1 (arr clients shared between
  phlix-server and phlix-hub) and K.3 (hub-side request fulfillment).

## [0.3.0] — 2026-05-17

### Added
- `Phlix\Shared\Auth\ProviderInterface` — core interface for pluggable external
  authentication providers (OIDC, LDAP, SAML, passkeys). Zero I/O dependencies
  so both phlix-server and phlix-hub can implement providers without pulling in
  server/runtime dependencies.
- `Phlix\Shared\Auth\AuthResult` — immutable value object returned by
  `ProviderInterface::authenticate()`. Captures success/failure, local userId,
  provider externalId, error code, and arbitrary attributes (email, name,
  avatarUrl …).
- `Phlix\Shared\Auth\UserInfo` — immutable value object returned by
  `ProviderInterface::getUserInfo()`. Describes an external identity for
  account linking and profile display.

## [0.2.0] — 2026-05-17

### Added
- `Phlix\Shared\Plugin\LifecycleInterface` — moved from `Phlix\Plugins\Contract\LifecycleInterface` in `phlix-server`.
- `Phlix\Shared\Plugin\{Manifest,ManifestType,ManifestValidationError,EventNameMap}` — moved from `Phlix\Plugins\*` in `phlix-server`. Validator logic stays in `phlix-server` (`Phlix\Plugins\Manifest\ManifestSchema`).
- `Phlix\Shared\Events\{AbstractEvent, Playback\*, Library\*, Auth\*}` — moved from `Phlix\Common\Events\*` in `phlix-server` (the 12 readonly event DTOs). PSR-14 dispatcher wiring stays in `phlix-server`.
- `Phlix\Shared\Auth\JwtClaims` — new value object capturing the Phlix JWT payload shape; consumed by `phlix-hub` starting Phase C.5.
- `Phlix\Shared\Hub\{ClaimRequest,ClaimResponse,ServerInfoDto,HeartbeatDto}` — new placeholder DTOs for the hub claim/heartbeat protocol; consumed by `phlix-hub` starting Phase C.1.
- `Phlix\Shared\Arr\.gitkeep` — namespace reserved for Phase K.1's `Sonarr`/`Radarr`/etc. typed clients.

## [0.1.0] — 2026-05-17

### Added
- Initial release: composer package scaffolding, `Phlix\Shared\Version` marker class, CI workflow.
- Real interfaces and DTOs land in v0.2.0 per `plans/expansion/b.1-shared-design.md` in `detain/phlix`.
