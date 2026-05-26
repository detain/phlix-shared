# Changelog

All notable changes to `detain/phlix-shared` are documented here.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
