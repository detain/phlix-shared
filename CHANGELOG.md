# Changelog

All notable changes to `detain/phlix-shared` are documented here.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.45.0] - 2026-07-23

Adds the `dlna.allowed_cidrs` (`array`, default `[]`) and `dlna.restrict_to_lan`
(`boolean`, default `true`) keys to `schemas/server-settings.schema.json` so the
admin settings UI can render and edit the DLNA IP allowlist (S50 / updates.md
\#35). Both keys are already consumed server-side by `DlnaAllowlistMiddleware`,
which reads them live via `SettingsRepository::getEffective()` against the
`config/dlna.php` defaults to gate the (unauthenticated) DLNA browse/stream
endpoints — default-deny, LAN-only, with an empty allowlist NEVER meaning
allow-all. Absent a schema entry the settings UI never surfaced them. Both are
`tier: advanced`, `group: subsystem`, and take effect immediately
(`restart: false`).

## [0.44.0] - 2026-07-22

Adds the `subtitles.provider_priority` key to `schemas/server-settings.schema.json`
so the admin settings UI can render and edit it. The key was already consumed
server-side (`SubtitleFetchService`/`SubtitleSourceRegistry::byPriority()` read it
live via `SettingsRepository::getEffective()` against the `config/subtitles.php`
default), but the absence of a schema entry meant the settings UI never surfaced
it. Unlike `metadata.provider_priority` (a per-media-type object), this is a single
flat ordered `array` of subtitle-source names; default `["opensubtitles"]`.

## [0.43.0] - 2026-07-22

Adds the optional `tier` property (`"standard"` | `"advanced"`) to plugin
settings fields in `schemas/manifest.schema.json`. The host already consumes a
per-field `tier` (SettingsMasker / PluginFieldHelp) to drive the Standard/Advanced
settings-UI toggle, but the manifest schema's `additionalProperties: false` on
each settings field rejected it — so a plugin.json that declared `tier` failed
install-time manifest validation. This closes that gap; `tier` remains optional
(the host derives a default from `required` when omitted).

## [0.42.0] - 2026-07-21

Adds the **subtitle-source contract** (Wave 3 / F3), the subtitle analogue of
the existing `Phlix\Shared\Metadata\MetadataSourceInterface`, so a subtitle
plugin (e.g. `phlix-plugin-opensubtitles`) and the host server can compile
against ONE shared type instead of the brittle `method_exists()` / FQCN-sniffing
convention.

New namespace `Phlix\Shared\Subtitle`:

- `SubtitleSourceInterface` — `getName()`, `getPriority()`, and a three-way
  search fan-out (`searchByPath()` PRIMARY / `searchByHash()` / `searchByImdbId()`)
  that returns `list<SubtitleCandidate>` and does NOT consume download quota,
  plus `download(SubtitleCandidate): SubtitleFile`, the on-demand,
  quota-consuming fetch that MAY throw `QuotaExceeded`.
- `SubtitleCandidate` — immutable (`final readonly`) value object describing a
  found-but-not-yet-downloaded subtitle: provider, ISO language, opaque
  `downloadId`, release name, format, plus ranking signals (`matchedBy`,
  `rating`, `downloadCount`, `hearingImpaired`, `fps`).
- `SubtitleFile` — immutable value object for a downloaded subtitle: language,
  format, decoded `content`, provider, `suggestedFilename`, `encoding`, and
  optional release name / hearing-impaired flag, ready to persist to
  `/var/subtitles` and attach as a track.
- `Subtitle\Exception\QuotaExceeded` — a `RuntimeException` carrying optional
  `downloadsRemaining` and `resetTimeUtc` (a `DateTimeImmutable` is normalised
  to an ISO-8601 string) so callers can persist/display quota state.

Zero I/O and no new dependencies (charter-clean). The
`subtitles.provider_priority` SETTING is intentionally NOT added here — it lands
later in the server settings schema, mirroring how `metadata.provider_priority`
is consumed. No settings JSON schema was touched.

## [0.41.0] - 2026-07-21

Adds `metadata.overwrite_existing` (68 -> 69 properties), the single shippable
row of `plan_settings.md` Phase 3. It is a `boolean`, `group: metadata`,
`tier: standard` (an operator-facing behaviour, not an internals knob), and
read-path class **(a) LIVE** with `restart: false`.

`config/metadata.php` is NOT composed into `config/server.php` (which composes
only ffmpeg/hub/relay/livetv/theme_music/newsletter/artwork/metrics), so the
consumer reads the effective value via `SettingsRepository::getEffective()` —
exactly the `artwork.download_enabled` / `scanner.ignore_patterns` pattern.
phlix-server consumes it through a new `MetadataOverwritePolicy` (a direct
mirror of `ArtworkDownloadPolicy`) at ONE decision point,
`LibraryMetadataMatcher::shouldSkipOverwrite()`, wired through the three
(re)resolve entry points `matchItem()` / `matchSeries()` / `applyMatchResolved()`.
Those three gate EVERY `array_merge($existing, $resolved)` metadata-overwrite
site in the class (movie, series, season, episode, and all four interactive
apply branches), so a subset cannot silently drift.

`default: true` is load-bearing: at the default the matcher does
`array_merge($existing, $resolved)` on every refresh exactly as before, so
behaviour is byte-for-byte identical to today (plan §4 rule 7). The setting
only does anything when an admin turns it OFF, at which point an item that has
ALREADY been resolved (`metadata_refreshed_at` present) is skipped WHOLESALE on
a forced rescan or interactive apply — there is no per-field provenance in the
pipeline, so "don't overwrite" can only mean a whole-item skip, mirroring the
existing manual-override short-circuit in `matchItem()`. Items never yet
resolved are still enriched. The helpText states the consequence an operator
must not get wrong: hand corrections survive, but genuinely-stale metadata will
not refresh until the switch is turned back on or the item is cleared.

## [0.40.0] - 2026-07-21

Adds `metrics.enabled`, `theme_music.enabled` and `theme_music.source`
(65 -> 68 properties). All three are read-path class **(b) RESTART** and ship
`restart: true`; all three are `advanced` tier. These are the three shippable
rows of `plan_settings.md` Phase 2 — the other five candidates were adjudicated
and REJECTED, with evidence recorded in the plan.

`metrics.enabled` resolves to `config/metrics.php`, which `config/server.php`
composes at `:91`. `MetricsServicesProvider` reads `$appConfig['metrics']` at
`:159` and captures the flag BY VALUE into the `MetricsCollector` factory
closure, so it is frozen into the container at build time and genuinely cannot
apply live — hence `restart: true` rather than a flag that lies. The
consequence is real: `MetricsCollector` guards all four `record*()` methods on
`$this->enabled`. The help text states the two things an operator will get
wrong otherwise — that this counts ALL HTTP traffic including media streaming
(so the numbers are dominated by playback segment requests), and that it is a
DIFFERENT switch from `stats.enabled`.

`theme_music.enabled` / `theme_music.source` resolve to
`config/theme_music.php`, composed at `config/server.php:67`.
`MediaServicesProvider:133` reads `$appConfig['theme_music']` and captures the
array into the `ThemeMusicConfig` factory at `:375`; the values are consumed by
`ThemeMusicResolver::resolveForItem()` through `isActive()` and
`allowsPlexFallback()`, reached from `LibraryMetadataMatcher:1698`. That
matcher has exactly ONE construction path and receives the resolver through an
EXPLICITLY NAMED `constructorParameter` — not an optional parameter PHP-DI
would silently skip.

**Correction to `plan_settings.md`:** the plan classified both `theme_music`
keys as class (d) NOT REACHABLE because of the raw `@include` at
`Application.php:3628`. That include is real, but its only caller is
`Application.php:3604`, inside `if ($this->container === null)` at `:3593` —
and `Application::__construct()` (`:87`) takes a NON-NULLABLE
`ContainerInterface`. The branch is dead code, the provider is the live read
path, and the class is (b). No include needed fixing.

The help text for both states the non-obvious operator-facing fact: theme
lookup happens at METADATA-MATCH time, so turning it off does not remove themes
already found (`LibraryMetadataMatcher:1712` only assigns when the resolver
returns non-null), and turning it on does not retroactively add themes to an
existing library.

## [0.39.0] - 2026-07-21

Adds `artwork.download_enabled` and `scanner.ignore_patterns` (63 -> 65
properties). Both `restart: false`, read-path class (a) LIVE.

`artwork.download_enabled` gates BOTH artwork download choke points in
`LibraryMetadataMatcher` — the poster/backdrop path and the separate logo path.
Gating only the first would have left logos downloading while the control
claimed downloads were off. Turning it off stops NEW fetches only; artwork
already cached on disk is untouched and keeps serving, which the help text
states explicitly since it is the first thing an operator will worry about.

`scanner.ignore_patterns` replaces a hardcoded list at
`MediaScanner::shouldSkipFile()`, a genuine single choke point with seven
in-file callers. Two things came out of wiring it:

- **The old list was matched case-SENSITIVELY**, so SABnzbd's actual marker
  directory `_UNPACK_` was never skipped despite `_unpack` being in the list,
  and `movie.TMP` slipped past `.tmp`. Matching is now case-insensitive. This is
  the only behaviour change at shipped defaults and is unambiguously a fix.
- **Matching is two-tier.** A pattern containing punctuation is self-delimiting
  and matches as a substring, exactly as before. A purely alphanumeric pattern
  matches only as a delimited token, so enabling `sample` cannot drop
  `Resample.mkv` or `Free Samples (2012).mkv`.

**`sample` is deliberately NOT a shipped default.** Token matching makes it safe
to offer but not to impose: it would still skip a title where the word stands
alone — "Sample People (2000)" is a real film — and the failure mode is a movie
that silently never appears, which an operator cannot diagnose. Enabling that
for every install at upgrade time is not a decision this release makes. The
shipped list therefore stays equivalent in effect to the literal it replaced,
and the help text names `sample` as the first thing worth adding.

Clearing the list is legal and means "skip nothing extra". It does not
re-enable scanning of dotfiles, which are handled by a separate hardcoded rule
the list cannot reach.

## [0.38.0] - 2026-07-21

Adds three software-encode keys: `transcoding.preset`, `transcoding.crf_h264`
and `transcoding.audio_bitrate` (60 -> 63 properties). All `advanced`-tier,
`restart: false`, read-path class (a) LIVE.

In `phlix-server` these were TEN hardcoded literals, not three: the ABR
rendition builder, the copy-to-encode upgrade branch and the legacy
single-variant path each assemble their own encode `$params` array from
scratch, giving preset x3, crf x3 and audio_bitrate x4. Server 1.3.0 routes all
ten through `Phlix\Media\Transcoding\EncodeSettings`.

Two consequences shaped the design:

**The transcode job key.** A job persists the parameters it was built with and
`findReusableJob()` returns that job for any later request with the same key —
a key that contained none of these values. Without intervention, changing the
preset would keep serving the OLD encode for anything already watched, which is
exactly the content an administrator would test the change against. An encode
fingerprint is now folded into the key, and it is EMPTY while every value is at
its shipped default, so deploying this does not invalidate any existing
transcode cache.

**The hardware path.** `buildHwaccelSegmentCommand()` hardcoded per-vendor
presets and never read the preset parameter, so a control wired only through
the software builder would silently do nothing on any server with a GPU. It is
now honoured, but only once the value differs from the default: NVENC uses a
p1..p7 namespace rather than the x264 names, and vaapi/qsv were tuned
independently of the software path, so forwarding the default would have
retuned every GPU encode the moment this shipped.

Values are validated rather than merely clamped, because encode parameters are
concatenated into a shell command: an unrecognised preset or an unparseable
bitrate falls back to the shipped default instead of reaching ffmpeg, where it
would abort the transcode and present as "all playback is broken".

**No `crf_h265` companion, deliberately.** Nothing in `phlix-server` ever sets
`video_codec` to `libx265`, so the key would have no live consumer.

## [0.37.0] - 2026-07-21

Promotes the whole `server.rate_limit.*` block into the schema: `max` and
`window` for each of the six limited surfaces — `register`, `refresh`,
`webauthn_start`, `webauthn_finish`, `jwks`, `ws_connect` (48 -> 60
properties). All `advanced`-tier and **`restart: true`**.

`restart: true` is not conservatism, it is accurate. The limiters are `factory()`
closures that capture `max`/`window` **by value** at container-build time
(`AuthServicesProvider.php:309`, `:317`), so the value is frozen into the DI
definition and cannot apply live no matter when the store is read.

`config/server.php:177` builds `rate_limit` as a top-level key of the boot
config, so the values genuinely reach `AuthServicesProvider::registerRateLimiters()`
at `:293`. All six surfaces were checked to have live consumers before exposing
them — none is a dead knob.

**Clamped in code via new `RateLimitProfiles::clampMax()` / `clampWindow()`.**
`MIN_MAX = 1` is a **lock-out fail-safe**, not a sanity bound: a configured
`max` of 0 would reject every request to its surface, and `refresh` at 0 signs
out the entire install as access tokens expire. `MIN_WINDOW = 1` matters for a
subtler reason — a 0-second window puts every request in a fresh bucket, which
silently disables the limiter while the admin UI still displays a limit.

**Documented precedence gotcha:** the `RATE_LIMIT_<SURFACE>_MAX` /
`_WINDOW` environment variables are read at config-include time and the
database overlay is applied afterwards, so **a saved setting overrides the
environment variable** — the reverse of the usual expectation. Each helpText
says so explicitly rather than leaving an operator to discover it.

## [0.36.0] - 2026-07-21

Adds two settings keys: `auth.max_profiles` and
`access.default_concurrent_streams` (46 -> 48 properties). Both are
`standard`-tier, `restart: false`, read-path class (a) LIVE.

`auth.max_profiles` exposes the per-account profile cap, previously the
compile-time `UserProfileManager::MAX_PROFILES_PER_USER = 5`. There are TWO
enforcement sites and only one of them ever fires in practice: the pre-check in
`AdminProfileController::createForUser()` returns 400 before
`UserProfileManager::create()`'s own guard is reached. Wiring only `create()` —
the obvious choice — would have left the admin API, the only route that creates
profiles, still pinned at 5 while the setting appeared wired. Both now call
`UserProfileManager::maxProfiles()`.

`access.default_concurrent_streams` exposes the fallback in
`StreamSessionService::getStreamLimit()`. This is **not** a creation-time seed:
nothing writes a `profile_stream_limits` row when a profile is created — the
only writer is `updateStreamLimit()`, reached solely from the admin API — so the
fallback is what every profile an administrator has not explicitly configured
actually runs on, evaluated per playback.

`StreamSessionService` has TWO construction paths (the container, and a direct
`new` in `Application::getStreamLimitController()`'s no-container fallback) and
both now receive the settings store. It was also resolved by *implicit*
autowiring, which silently skips optional constructor parameters — so it needed
an explicit registration or the key would have been inert by construction.

Both keys are clamped in code as well as by the schema (profiles 1..50, streams
1..100). The floors matter: a configured 0 would have made profile creation
impossible, and denied playback to nearly every profile, respectively.

**Rejected in the same pass, with evidence:**

- **`access.default_rating`** — `UserProfileManager::DEFAULT_CONTENT_RATING`
  has five read sites, of which two (`isContentRatingAllowed()`,
  `getAllowedRatings()`) have ZERO production callers — class (g). The one live
  read is a narrow fallback that fires only when a `profile_settings` row
  exists with a NULL `content_rating`. Meanwhile `AdminProfileController:150`
  and `:229` both default to a literal `'R'` and bypass the constant entirely.
  A "default rating" control would visibly do nothing for most users.
- **`auth.methods.{password,webauthn,oidc,ldap}`** — no per-method check exists
  at any of the six auth entry points, so the keys would be class (f) DEAD on
  arrival; `oidc`/`ldap` would additionally collide with the existing plugin
  enable/disable as a second source of truth; and there is still no lock-out
  fail-safe. Separately, `AuthProviderController::enableProvider()`/
  `disableProvider()` are no-op stubs that report success while persisting
  nothing — that already-shipped lie must be fixed before any related toggle.

## [0.35.0] - 2026-07-21

Adds two settings keys: `auth.access_ttl` and `auth.refresh_ttl`
(44 -> 46 properties). Both are `advanced`-tier, `restart: false`.

These expose the JWT access- and refresh-token lifetimes, which in
`phlix-server` were **four independent literals that nothing kept in
agreement**:

- `JwtHandler`'s constructor defaults (3600 / 604800) built the `exp` claims.
- `AuthServicesProvider` read `$appConfig['jwt']` — a key `config/server.php`
  has never composed, so that branch was dead and its 3600/604800 fallbacks
  were always what shipped.
- `AuthManager::buildAuthResponse()` hardcoded `'expires_in' => 3600`
  independent of the handler that minted the token, so the lifetime the client
  was TOLD and the `exp` it could read out of the JWT were two unrelated
  constants that happened to match.
- `AuthController::attachAuthCookies()` hardcoded `7 * 24 * 3600` for the
  refresh cookie's `Max-Age`, plus a second `3600` fallback.

Wiring a setting to a subset of those would have been worse than shipping no
setting at all. Shortening the access TTL would expire the token while the
client still believed it had an hour; raising the refresh TTL would leave the
browser dropping a cookie that still carried a valid refresh token. Both
present as "random logouts". Server 1.3.0 routes all four sites through a
single `JwtHandler` instance backed by `Phlix\Auth\TokenTtlPolicy`.

**The clamps are two-sided**, unlike `auth.password.min_length`'s one-sided
floor: a password minimum can only be made stronger, but a token lifetime is
unsafe in both directions. Access is bounded to 60..86400 and refresh to
3600..7776000 — in code as well as by the schema, so a `server_settings` row
written by direct SQL or restored from a backup is bounded too. The policy
additionally enforces `refresh >= access`: a refresh token expiring before the
access token it renews would end the session while the client still held a
valid access token and had no way to continue.

Changing either value never signs anyone out — validation only checks the `exp`
baked in at mint time, so a change applies to the next token issued.

## [0.34.0] - 2026-07-21

Adds two settings keys: `dlna.cds_enabled` and `dlna.friendly_name`
(42 -> 44 properties), and corrects `dlna.enabled`'s description/helpText.

`phlix-server` 1.3.0 finally WIRES the DLNA media server. Until now
`Phlix\Dlna\DlnaServer` was registered in no DI provider and takes three
un-autowirable `string` constructor parameters, so `CdsServer` could never be
resolved; `Application::loadCdsRoutes()` swallowed the failure in a bare
`catch (\Throwable)` and **no DLNA browse route was ever registered on any
install**. A new `DlnaServicesProvider` registers the server, and the route
loader now logs a resolution failure instead of hiding it.

**`dlna.cds_enabled` ships FALSE and that is deliberate.** DLNA/UPnP has no
authentication of any kind: serving it lets anything on the local network browse
and stream the entire library without signing in, bypassing the auth gate every
other entry point enforces. It is therefore `advanced`-tier, with the warning
stated plainly in both `description` and `helpText` rather than buried.

`dlna.enabled` (announce over SSDP) now requires `dlna.cds_enabled` as well.
Announcing a server whose browse service is off puts Phlix in every TV's source
list as a device that cannot be opened — which was the real production state.
The reverse combination, server on with announcement off, remains valid for
clients given the address directly. Both texts say so.

`dlna.friendly_name` only became exposable now: it is one of `DlnaServer`'s
un-autowirable constructor parameters, so before this there was no instance for
it to configure. The name devices actually displayed came from a hand-written
static `public/dlna/description.xml` (hardcoded `uuid:PHLIX_SERVER`,
`localhost:8080`, and SCPD URLs matching no real route), which shadowed the
route via the static-file fast path and has been deleted.

## [0.33.0] - 2026-07-21

Adds three settings keys: `casting.chromecast.enabled`, `casting.roku.enabled`
and `casting.airplay.enabled` (39 -> 42 properties).

Ships with a NET-NEW `config/casting.php` in `phlix-server` (the file did not
exist) and a net-new `CastingEnabledMiddleware`, appended to each protocol's
existing route group so one object covers all six of that protocol's routes.

These are read-path class **(a) LIVE**, unlike most of this program's toggles,
and that is deliberate. Middleware runs per request, so flipping one takes
effect on the very next call with no reload. The reason it matters: device
discovery is BLOCKING. `MdnsSocket::query()` loops on `socket_recvfrom()` with
a 5-second `SO_RCVTIMEO`, stalling the whole Workerman worker for the duration
— and AirPlay issues two service queries, so roughly ten seconds. The endpoints
are authenticated, but any signed-in user can call them repeatedly, so an
operator needs to be able to shut one off immediately rather than after a
restart. Each key's helpText states this cost plainly.

Unlike `dlna.enabled`, these subsystems are genuinely reachable. Verified
against production on 2026-07-21: `GET /api/v1/{cast,roku,airplay}/devices` all
return 401 (route present, auth-gated) while a made-up sibling path returns 404,
and all three managers resolve from the container.

The switches are real. Two of the FEATURES behind them are incomplete, which is
recorded rather than papered over — `MdnsDiscovery::SERVICE_ROKU` is
`'_ roku-ecnp._tcp.local.'` (a literal space in the label, `ecnp` for `ecp`) and
Roku does not advertise ECP over mDNS at all, so its device list cannot
populate; and `AirPlaySession::startStream()` sends ANNOUNCE then RECORD with no
RTSP SETUP and no RTP sender, so no audio is ever transmitted. Each helpText
therefore describes what the SWITCH does — stop discovery and the endpoints —
which is true regardless of how complete the protocol implementation is.
Details in `phlix-server/config/casting.php`.

Defaults to `true` for all three so existing installs are unaffected.

## [0.32.0] - 2026-07-21

Adds one settings key: `dlna.enabled` (38 -> 39 properties).

Ships with a NET-NEW `config/dlna.php` in `phlix-server` (the file did not
exist) and a net-new guard in `SsdpAdvertiser::isEnabled()`, consulted by that
worker's `onWorkerStart`. Turning it off stops the SSDP broadcasts that make
this server appear in smart TVs' and games consoles' source lists.

Same disable-on-reload / enable-needs-a-service-restart asymmetry as the five
`process.*` keys, for the same reason: the master process spawns the advertiser
before `Worker::runAll()` and cannot consult the settings store without leaving
a DB connection inherited by every fork. So the spawn decision reads the config
FILE, and the fork applies the EFFECTIVE value on every graceful reload. The
helpText states this in the same words the `process.*` keys use.

**The scope of this key is deliberately narrow, and the reason is a bug worth
recording.** It gates the advertiser only — NOT DLNA browsing — because DLNA
browsing is not currently wired at all. `Application::loadCdsRoutes()` resolves
`Phlix\Dlna\CdsServer` inside a bare `catch (\Throwable)`, and that resolution
always throws: `Phlix\Dlna\DlnaServer` is registered in no DI provider and its
constructor takes three un-autowirable `string` parameters. Verified against
production on 2026-07-21 — `POST /dlna/content_directory`, `POST /cds/control`
and `GET /scpd/ContentDirectory.xml` all return 404, while
`GET /dlna/description.xml` returns 200 only because a static file happens to
sit at `public/dlna/description.xml`. The net effect is that the server
advertises itself as a MediaServer that then 404s every browse request, which
makes switching the advertisement off a genuine improvement today. Do not widen
this key's helpText to promise browsing until the CDS is wired.

## [0.31.0] - 2026-07-21

Adds one settings key: `stats.enabled` (37 -> 38 properties).

Ships with a NET-NEW `config/stats.php` in `phlix-server` (the file did not
exist) and a net-new guard. Enforced centrally in `StatsCollector::isEnabled()`,
which every public `record*()` method consults — one switch covering ~52 call
sites rather than a guard each caller must remember to apply.

Note the framing: plan_settings.md called this "telemetry no opt-out", which is
misleading. These statistics are entirely LOCAL — written to the server's own
`stats_*` tables and read back only by the admin dashboard. Nothing is
transmitted anywhere and there is no reporting endpoint. Switching it off is a
performance/retention choice, not a privacy one, and the helpText says so
plainly rather than implying the admin is disabling data collection that leaves
the machine.

Turning it off blanks the dashboard's activity and storage cards, since these
tables are their only source. The helpText states that too. Defaults to `true`
so existing installs are unaffected.

## [0.30.0] - 2026-07-21

Adds one settings key: `webhooks.enabled` (36 -> 37 properties).

This key ships together with the consumer that makes it real. Before this,
`config/webhooks.php`'s `'enabled' => true` had **zero consumers** in
`phlix-server`: the literal appeared only inside the fallback array
`WebhookDispatcher::getConfig()` returns when the config file is MISSING, and was
never read on any path. `getConfig()` also raw-`include`d the file (read-path
class (d) NOT REACHABLE), so no `webhooks.*` admin override could reach it even
if something had read it.

Both halves were fixed in `phlix-server`: `getConfig()` now goes through
`EffectiveConfig::file('webhooks')`, and `WebhookDispatcher::isEnabled()` gates
`dispatch()` before the per-event DB lookup, so switching webhooks off also stops
the query. Registrations are left intact and resume when switched back on.

Defaults to `true` so existing installs keep delivering exactly as before.

## [0.29.0] - 2026-07-21

Adds one settings key: `auth.password.min_length` (35 -> 36 properties).

Resolves to `config/auth.php -> ['password']['min_length']` in `phlix-server`,
where it is consumed LIVE (read-path class (a), `getEffective()`) by the new
`Phlix\\Auth\\PasswordPolicy`. That class is the single enforcement point
replacing three duplicated `strlen($password) < 8` literals — `AuthManager::register()`
and both `AdminUserController` password paths. Per plan_settings.md, a setting
wired to only some of its duplicate literals is half-effective, so the key ships
together with the removal of all three.

The schema `minimum` is 8, matching `PasswordPolicy::ABSOLUTE_MIN_LENGTH`, which
the server also enforces in code. The control can therefore only ever STRENGTHEN
the shipped policy, never weaken it below the baseline Phlix has always applied
— including against a `server_settings` row written outside the admin API.

Raising the value does not invalidate existing passwords and cannot lock anyone
out; users meet the new requirement the next time their password changes. The
helpText says so explicitly.

## [0.28.0] - 2026-07-20

Deletes six settings keys that shipped without a consumer. `server-settings`
goes from 41 to 35 properties.

Context: `plan_settings.md` §11 asserted the shipped keys were "verified
consumed-and-reachable". A key-by-key sweep of all 41 disproved that for **14**.
Eight were repaired or wired in `phlix-server`; the six below could not be made
honest without building the feature behind them, so per §4 rule 10 they are
deleted rather than shipped.

**Two of them had live admin overrides on production** — an operator had set a
subtitle language and switched trickplay off, and both did nothing. Every one of
these passed `SettingsDefaultResolvabilityTest`, which by design only proves a
default resolves, never that anything reads it.

### Removed

- **`marker_detection.similarity_threshold`**, **`marker_detection.intro_max_duration`**
  — no consumer. The only reads of `config/marker_detection.php` are
  `MediaServicesProvider.php:521,539`, which take `job_queue_dir` and
  `min_episodes_for_detection` through a raw `@include` that bypasses
  `EffectiveConfig` entirely.
- **`subtitles.enabled`**, **`subtitles.burn_in_by_default`** — `config/subtitles.php`
  is composed only into `config/ffmpeg.php:52`, so it lives at
  `$config['ffmpeg']['subtitles']`, a path these keys do not address. Neither
  identifier occurs anywhere in `phlix-server/src/`.
- **`discovery.discovery_port`** — `config/discovery.php` has no loader anywhere,
  and `Application::startDiscoveryIfEnabled()` reads no flag before starting.
  Its helpText was also factually wrong (described UDP broadcast; 8200 is the
  DLNA HTTP port).
- **`trickplay.interval_seconds`** — configures the dead trickplay
  implementation. `StreamManager::setTrickplay()` has no callers, so
  `StreamManager::generateTrickplay()` always throws; the live implementation
  (`MediaAssetGenerationJob`) has no interval concept, only a fixed sprite count.

All six are recorded in `CONSUMERLESS_KEY_DENYLIST` with their evidence, so they
cannot be reintroduced without deleting the citation that says why they are dead.

### Kept and made real (in `phlix-server`, same release)

- **`trickplay.enabled`** — now gates the LIVE sprite path in
  `MediaAssetGenerationJob::generateTrickplaySprites()`.
- **`subtitles.default_language`** — now backs the server-wide fallback for
  `preferred_subtitle_language` in `GET /api/v1/user/settings`. Its config
  default was corrected from `'eng'` (ISO 639-2) to `'en'` (ISO 639-1) to match
  the vocabulary of its consumer, per plan §4 rule 8.

## [0.27.0] - 2026-07-20

Deletes the last known consumerless settings key. `server-settings` goes from
42 to 41 properties.

### Removed

`schemas/server-settings.schema.json` — **`transcoding.include_software_fallback`**
(was `boolean`, `default: true`, `tier: standard`, `restart: false`).

Same defect shape as `hwaccel.probe_timeout` in 0.26.0: the key *resolved*
cleanly, so `SettingsDefaultResolvabilityTest` was green, but **nothing in
`phlix-server` ever read the effective value.** Verified by an exhaustive sweep
of `src/`, `config/`, `public/index.php`, `start.php`, `bin/`, `scripts/` and
the vendored tree — for the literal key name, for variable-key array access on
every config array, and for concatenated/fragmented key construction. Exactly
three live hits existed, none of them a read:

1. `phlix-server/config/transcoding.php:44` — the default literal.
2. `phlix-server/src/Config/HwAccelConfig.php:118` — copies it into the merged
   hwaccel array.
3. `phlix-server/tests/.../AdminSettingsControllerTest.php:84` — the
   hand-written allow-list assertion.

The merged array has exactly two consumers, and neither reads the key:

- `FfmpegRunner::setConfig()` (`Application.php:2971`,
  `TranscodeServicesProvider.php:149`). `FfmpegRunner` reads precisely
  `tone_mapping_mode`, `prefer_hdr_output`, `preferred_accelerator`, `enabled`
  and `prefer_hardware` from `$this->config` — nothing else. Its `getConfig()`
  accessor has no caller in `src/`.
- `HwaccelRegistry`, whose *actual* software-fallback decision
  (`HwaccelRegistry.php:160,206`) reads the **separate**
  `hwaccel.fallback_to_software` key, sourced from `config/hwaccel_base.php`.

So the toggle was inert in both directions: turning it off never disabled
software fallback, and turning it on never enabled anything. Deleted rather
than wired (plan §4 rule 10) because the working equivalent already exists —
`hwaccel.fallback_to_software` is genuinely consumed and is the key to expose
if a software-fallback toggle is wanted. Shipping a second, dead key beside it
would have been strictly worse than absence.

The `config/transcoding.php` default and the `HwAccelConfig::get()` merge line
are deliberately retained (with an explanatory comment) so the merged array
shape is unchanged for any caller reading it defensively — mirroring exactly
what 0.26.0 did for `probe_timeout`.

### Changed

`tests/Schema/ServerSettingsSchemaTest.php` — the single-key
`test_consumerless_probe_timeout_key_is_not_reintroduced()` guard added in
0.26.0 is generalised into `test_no_schema_key_is_a_known_consumerless_key()`,
driven by a `CONSUMERLESS_KEY_DENYLIST` map that now carries both keys deleted
for having no consumer (`hwaccel.probe_timeout`,
`transcoding.include_software_fallback`), each with the audit note that killed
it. The new guard additionally asserts the key is absent from the hand-written
`propertyProvider()` expectation list, so a key re-added to *both* places still
fails rather than quietly satisfying the key-set test.

This stays a **denylist regression guard, not a general consumerless-key
detector.** The general case is not decidable here: `phlix-shared` cannot see
`phlix-server`'s source, and even inside `phlix-server` "is this key's effective
value ever read?" is a whole-program dataflow question — consumers appear as
literals (`getEffective('auth.signup_mode')`), as reads of a merged array handed
across a DI boundary (`FfmpegRunner::setConfig()` → `$this->config['...']`), and
as variable array keys (`EffectiveConfig::file('process')[$procKey]` in
`start.php`). A name-matching heuristic strong enough for the third form matches
common leaf names like `enabled`/`timeout` everywhere and yields false passes; a
stricter one yields false failures that get silenced by an allow-list which then
rots into a rubber stamp. Plan §4 rule 1 — cite the `file:line` consumer in the
PR that adds the key — remains the control for new keys.

## [0.26.0] - 2026-07-20

Makes the last two dishonest `restart: true` settings honest. `server-settings`
goes from 43 to 42 properties.

### Removed

`schemas/server-settings.schema.json` — **`hwaccel.probe_timeout`** (was
`integer`, `minimum: 0`, `default: 30`, `tier: advanced`, `restart: true`).

The key resolved to a real config default, so it passed every resolvability
test, but **nothing in `phlix-server` ever read the effective value.** Two
independent causes, both verified:

1. `HwaccelRegistry` is built through `getInstance()` with no config
   (`src/Media/Transcoding/FfmpegRunner.php:1359`), so it fell back to its own
   literal `'probe_timeout' => 30`
   (`src/Media/Transcoding/Hwaccel/HwaccelRegistry.php:89`) — and even that
   value is never passed on: `HwaccelRegistry::initialize()` constructs
   `new HwaccelProbe($this->ffmpeg_path)`, and `HwaccelProbe::__construct()`
   (`HwaccelProbe.php:51`) accepts only a binary path and a logger.
2. `HwAccelConfig::get()` resolved
   `$transcodingConfig['probe_timeout'] ?? $hwaccelBase['probe_timeout']`
   (`src/Config/HwAccelConfig.php:103`) while `config/transcoding.php:33`
   *always* declares `probe_timeout`, so the `hwaccel.*` side could never win
   even if a consumer had appeared.

It was deleted rather than wired (plan §4 rule 10) because wiring it is neither
cheap nor safe:

- The real timeouts are **two** hardcoded constants with different values —
  `ShellTimeout::FFMPEG_TIMEOUT = 10` and `::GPU_TOOL_TIMEOUT = 5`
  (`src/Media/Transcoding/Hwaccel/ShellTimeout.php:25,28`). One
  `probe_timeout` maps onto neither without inventing a semantic.
- `ShellTimeout::exec()` is **static**, called from 22 sites across seven
  `VendorProbe` classes. Threading a configured value would change
  `VendorProbeInterface::probe()` / `runAcceptanceTest()` across all seven
  implementations plus `HwaccelProbe` and `HwaccelRegistry`'s
  private-constructor singleton.
- The schema declared `minimum: 0`, and coreutils' `timeout 0 CMD` means **no
  timeout at all**. `ShellTimeout` exists specifically "to prevent coroutine
  deadlock during shutdown" (`ShellTimeout.php:15`), so wiring the key would
  have handed an admin a one-click way to hang a resident Workerman worker at
  boot.
- The shipped `helpText` was false regardless: it described a **per-file,
  pre-transcode** probe of "the file's codec profile". No such probe exists.
  `HwaccelRegistry::initialize()` runs a one-time, process-wide capability scan
  (`ffmpeg -encoders`, `nvidia-smi`, `vainfo`) guarded by `$this->initialized`.

`tests/Schema/ServerSettingsSchemaTest.php` gains
`test_consumerless_probe_timeout_key_is_not_reintroduced()` so it cannot come
back without a cited consumer.

### Changed

`schemas/server-settings.schema.json` — rewrote `helpText` for all five
**`process.<worker>.enabled`** switches (`library-scan`, `plugin-auto-update`,
`marker-detection`, `media-asset`, `similarity`).

The old text said the worker "is not spawned". That is **wrong**: `start.php`
spawns it regardless. The spawn loop runs in the master before
`Worker::runAll()` and reads `config/process.php` from disk only, so it cannot
consult the override store; the effective value is applied inside each managed
worker's own `onWorkerStart`, which starts, logs
`Managed worker disabled by settings override; idling`, and never arms its poll
timer.

The consequence is an asymmetry the admin could not previously see, since the
UI renders only `helpText`:

- turning a worker **OFF** takes effect after a restart;
- turning one back **ON** also takes effect after a restart — *unless* it is
  disabled in the on-disk `config/process.php`, in which case no Worker group
  was ever forked and the in-app Restart button (SIGUSR2, a graceful reload of
  the already-executed master) cannot help; the service itself must be
  restarted;
- a worker switched off here **still occupies an idle process**.

All three facts are now stated in each `helpText` and locked in by
`test_managed_worker_switches_disclose_the_restart_asymmetry()`, which also
asserts the old "is not spawned" claim cannot return.

### Notes

The remaining 14 `restart: true` keys were re-audited against
`Phlix\Config\EffectiveConfig` and all remain correctly flagged: overrides are
snapshotted once per process by `EffectiveConfig::bootstrap()`, and every
consumer resolves at worker boot (DI provider factories, or
`EffectiveConfig::file()` behind a generation-keyed cache). None became
live-at-runtime, so no `restart` flag needed flipping to `false`.

## [0.25.0] - 2026-07-20

Restores the three Trakt credential settings that 0.24.0 removed. `server-settings`
goes from 40 to 43 properties.

### Added

`schemas/server-settings.schema.json` — `trakt.client_id`, `trakt.client_secret`,
`trakt.redirect_uri` (group `scrobblers`, tier `standard`), each with `label`,
`helpText`, `helpLinks`, `description` and a `default` mirroring the
`config/scrobblers/trakt.php` literal (`""`, `""`, and
`"https://your-server.com/api/v1/oauth/trakt/callback"` respectively).

0.24.0 deleted these keys because `SettingsRepository::getDefault()` resolved only a
FLAT `config/<file>.php`, while the real config sits at
`config/scrobblers/trakt.php`. The read path never broke — but PUT started rejecting
them as "Unknown setting key", so Trakt credentials could no longer be set from the
admin Settings page at all. `phlix-server` now ships a `config/trakt.php` re-export
shim (`return require __DIR__ . '/scrobblers/trakt.php';`, the same idiom
`config/hwaccel.php` already uses for `config/hwaccel_base.php`), which makes the
original flat keys resolve unchanged.

The keys were deliberately NOT renamed to `scrobblers.trakt.*`, even though the
nested-path resolver added in 0.24.0's companion server change would accept that
form. `trakt.*` overrides are already persisted in the `server_settings` table on
live installs and `TraktOAuthController::SETTING_KEY_MAP`
(`src/Server/Http/Controllers/TraktOAuthController.php:63-67`) reads those exact
keys; a rename would silently orphan those rows and drop working Trakt credentials
on upgrade.

`restart` is `false` on all three, and that is verified, not assumed:
`TraktOAuthController::applySettingsOverrides()` (`:414-429`) calls
`SettingsRepository::getOverride()` at `:421` on every request, from `loadConfig()`
(`:402`), which `authorize()` (`:158`), `callback()` (`:226`) and `status()` (`:511`)
each invoke per request. A saved value is live on the next request with no reload.
These are among the few settings in this schema for which `restart: false` is
literally true — see the open `restart: true` gap documented in
`phlix-server/docs/dev/settings-restart-gap.md`.

`trakt.client_id` and `trakt.client_secret` are both `"secret": true`. An OAuth
client_id is nominally public, but this schema already masks `lastfm.api_key`, which
is the exact analogue (Phlix sends the Trakt client_id as the `trakt-api-key` header
on every request, as Last.fm sends its api_key). `trakt.redirect_uri` is a public URL
and is not masked. The documented secret set therefore grows from three keys to five.

### Changed

- `schemas/server-settings.schema.json` document `description`: corrected — it still
  claimed dotted setting keys must name a flat config file and that "subdirectories
  are not supported". The resolver has since learned multi-segment file paths
  (longest path wins), so `scrobblers.trakt.client_id` does resolve. The text now
  describes the real rule.
- `README.md`: the "Key-naming contract" section carried the same stale claim; it is
  rewritten and records why `trakt.*` stays flat.
- `tests/Schema/ServerSettingsSchemaTest.php`: key count 40 → 43; `SECRET_KEYS`
  trio → quintet; two docblocks that asserted subdirectory keys were unsupported
  corrected. The hand-written `propertyProvider()` list is still hand-written on
  purpose — deriving it from the schema would make it tautological and it would stop
  failing on an unreviewed key change.

## [0.24.0] - 2026-07-20

Settings-schema correctness pass. An audit of all 109 schema properties found 37
with wrong or unreachable values; the two settings schemas are rewritten so every
declared key resolves through the consuming repo's settings resolver, and the
schema tests are strengthened so the same class of defect cannot pass CI again.

### Changed — BREAKING (settings key renames)

Nothing was deployed with these keys, so there are no persisted overrides to
migrate. Each old key resolved to `null` in `phlix-server` — the first dotted
segment must be a FLAT `config/<file>.php` name (`SettingsRepository::getDefault()`
/ `loadConfig()`; subdirectories are rejected by the `/^[A-Za-z0-9_-]+$/` jail), and
these pointed at files or paths that do not exist.

`schemas/server-settings.schema.json`:

- `transcoding.max_concurrent_transcodes` → **`ffmpeg.max_concurrent_transcodes`** (`config/ffmpeg.php:33`)
- `transcoding.transcode_timeout` → **`ffmpeg.transcode_timeout`** (`config/ffmpeg.php:34`)
- `transcoding.max_concurrent_scan_probes` → **`ffmpeg.max_concurrent_scan_probes`** (`config/ffmpeg.php:39`)
- `hls.segment_seconds` → **`server.hls.segment_seconds`** (`config/server.php:95`; there is no `config/hls.php`)
- `hls.max_concurrent_segments` → **`server.hls.max_concurrent_segments`** (`config/server.php:102`)
- `transcoding.segment_cache_max_age` → **`server.hls.cache_max_age`** (`config/server.php:110`)
- `transcoding.segment_cache_max_bytes` → **`server.hls.cache_max_bytes`** (`config/server.php:107`)
- `subsystem.library_scan_enabled` → **`process.library-scan.enabled`** (`config/process.php:37`)
- `subsystem.plugin_auto_update_enabled` → **`process.plugin-auto-update.enabled`** (`config/process.php:46`)
- `subsystem.marker_detection_enabled` → **`process.marker-detection.enabled`** (`config/process.php:55`)
- `subsystem.media_asset_jobs_enabled` → **`process.media-asset.enabled`** (`config/process.php:65`)
- `subsystem.similarity_enabled` → **`process.similarity.enabled`** (`config/process.php:76`)

Note the `process.*` worker names are HYPHENATED, matching `config/process.php`.

`schemas/hub-settings.schema.json`:

- `auth.access_token_ttl` → **`auth.access_ttl`** (`phlix-hub/config/auth.php`)
- `auth.refresh_token_ttl` → **`auth.refresh_ttl`** (`phlix-hub/config/auth.php`)

The `*_token_ttl` spellings match no hub config path. `phlix-hub/config/auth.php`
documents that this exact rename previously disabled `HUB_JWT_ACCESS_TTL` in
production, so the schema is corrected rather than the config.

### Removed — BREAKING (unresolvable or forbidden keys)

`schemas/server-settings.schema.json` (53 → 40 properties):

- `database.pool_size`, `database.timeout` — the settings plan lists all
  `database.*` as DO-NOT-EXPOSE. Both also resolved to `null` (the real paths are
  nested under `database.connections.mysql.*`).
- `metadata.preferred_language`, `metadata.preferred_country`,
  `metadata.fanart_api_key` — no config path and no consumer anywhere in
  `phlix-server/src/`. `preferred_language`/`region` remain legitimate future work,
  but shipping a key before its consumer exists is what produced this audit.
- `auth.enabled` — describes a local-auth kill switch that does not exist.
- `auth.rate_limit` — no config path; the login limiter is a fixed
  `AuthManager::RATE_LIMIT_MAX_ATTEMPTS = 5` const (`src/Auth/AuthManager.php:45`)
  with a 900-second window (`:46`), neither configurable. The schema advertised
  `20` attempts per hour, i.e. 4× the real count over 4× the real window.
- `auth.session_lifetime` — no config path and no consumer; this is a bearer-JWT
  server with no session cookie. The nearest real value is the access-token TTL,
  which falls back to a hardcoded `3600`
  (`src/Common/Container/Providers/AuthServicesProvider.php:146`), not the `86400`
  the schema advertised.
- `transcoding.segment_max_inflight_global` — a duplicate of
  `server.hls.max_concurrent_segments`; two keys, one knob.
- `transcoding.stale_job_max_age` — not configurable in any namespace
  (`TranscodeManager::STALE_JOB_MAX_AGE` is a const).
- `trakt.client_id`, `trakt.client_secret`, `trakt.redirect_uri` — the config lives
  at `config/scrobblers/trakt.php` and the resolver's path jail forbids the `/`, so
  no dotted key can reach it. Re-add once the file is reachable as `config/trakt.php`.
  **Superseded in 0.25.0** — this removal cost a real admin capability (Trakt
  credentials became unsettable) and the three keys are restored below, unrenamed,
  backed by a `config/trakt.php` re-export shim in `phlix-server`.

`schemas/hub-settings.schema.json` (12 → 3 properties) — the file previously
mirrored an orphaned allow-list. It now matches `HubSettingsRepository::ALLOWED_KEYS`
exactly, which is what the hub settings controller enumerates:

- `server.domain`, `server.tls_enabled`, `server.subdomain_auto_claim` — forbidden
  hub identity / ACME-TLS keys; all three are in `HubSettingsRepository::DENIED_KEYS`.
- `server.relay_ping_interval`, `server.max_servers_per_user`,
  `server.heartbeat_interval`, `server.enrollment_renewal_threshold`,
  `logger.level`, `logger.audit_enabled` — none resolves against `phlix-hub/config/`.
  `phlix-hub/config/logger.php` explicitly documents that there is no top-level
  `level`/`audit_enabled` key and that one must not be added to make a settings key
  resolve.

### Fixed

- **`transcoding.preferred_accelerator` enum was factually wrong.** It offered
  `nvenc` (an *encoder* family, `h264_nvenc` — never an FFmpeg hwaccel name) and
  `v4l2` (the hwaccel is `v4l2m2m`), so
  `FfmpegRunner::getBestAcceleratorForCodec()`'s `$accelerators[$preferred]` lookup
  (`src/Media/Transcoding/FfmpegRunner.php:2846`) always missed and the pin silently
  did nothing. The enum is now the hwaccel vocabulary probed at
  `FfmpegRunner.php:2758-2767` and documented at `config/transcoding.php:14`:
  `cuda`, `qsv`, `vaapi`, `videotoolbox`, `amf`, `opencl`, `d3d11va`, `dxva2`,
  `v4l2m2m`, plus `""` for auto-detect.
- **`transcoding.preferred_accelerator` default was unsettable.** It declared
  `"type": "string"` with `"default": null` and `null` in the enum;
  `AdminSettingsController::valueMatchesType()` maps `string` to `is_string()`, so a
  PUT of `null` was rejected and "Auto-detect" could never be restored. A type union
  is not an option either — `loadAllowedKeysFromSchema()` requires
  `is_string($def['type'])` and would drop the key from the writable allow-list
  entirely. The sentinel is now the empty string, which `FfmpegRunner` already
  treats as "no preference": it only applies a preference when the configured value
  is a non-empty string (`FfmpegRunner.php:1364-1366`).
- **`tmdb.api_key` and `lastfm.api_key` are now `"secret": true`.** Both are literal
  service API keys and were rendered as plaintext form fields, inconsistently with
  the other credentials in the same schema.
- **Five dead (404) `helpLinks` URLs replaced**, each verified live:
  `Trick_mode` → `Trick_play`; `Chapter_(media)` → `Opening_credits`;
  `Intel_Media_SDK` → `Intel_Quick_Sync_Video`;
  `P2p_release_group_naming_conventions` → `Standard_(warez)` (plus a TMDb link,
  which is what that help text actually references); and
  `github.com/videoblade/zscale` → `github.com/sekrit-twc/zimg`, the real zscale
  upstream. The previous commit that claimed to fix the zscale URL replaced one
  404 with another.
- **`marker_detection.similarity_threshold` help contradicted its own default** —
  it described "the default of 0.75" where both the schema and
  `config/marker_detection.php:32` are `0.85`. The help no longer restates the
  number, since restating defaults in prose is what let this drift.
- **`transcoding.tone_mapping_mode` help documented an "Auto-detect" option that
  does not exist** in its three-value enum (`none`, `zscale`, `libplacebo`).
- **`transcoding.preferred_accelerator` help said "CUDA"** while the enum offered
  `nvenc`; the enum now genuinely offers `cuda`.
- `auth.signup_mode` no longer links to the OAuth 2.0 RFC, which has nothing to do
  with self-service signup mode.
- `tmdb.api_key` now links to `developer.themoviedb.org/docs` rather than the legacy
  documentation path.
- The duplicate NVENC Wikipedia URL (`/NVENC` vs `/Nvidia_NVENC`, the same article
  via a redirect) is collapsed to one spelling.
- `newsletter.enabled` now says "weekly" consistently with `newsletter.send_hour`
  and `config/newsletter.php`, which has a `send_day` and a weekly subject template.
- The hub schema `description` no longer contains the `handlesarr` typo and no
  longer describes library scanning, which is the server's job, not the hub's.

### Added

- **`helpLinks` on 16 server keys that had none** but name a technical term —
  x264/x265, HDR10/Dolby Vision, FFmpeg/ffprobe, OOM, HLS, WebSocket/keepalive,
  GiB, and cache-eviction policy. The ~22 original keys all carried links; the keys
  batch-added in later phases did not.
- **`helpLinks` on every hub key** — the hub schema previously had zero.
- `"restart": true` on the boot-only keys whose consumers read them once at worker
  start: `ffmpeg.*` and `server.hls.*` (read in
  `TranscodeServicesProvider::register()`) and `process.*.enabled` (read by
  `start.php:803` when it forks the managed workers).
- **`tests/Schema/SettingsSchemaAssertions.php`** — shared assertions used by both
  schema tests. These would have failed on the defects above, where the previous
  tests were green:
  - `tier` is now REQUIRED and must be `standard` or `advanced` (it was only
    validated when present, so a missing `tier` silently became "standard").
  - Every `enum` property must carry `enumLabels` AND `optionHelp` covering exactly
    its members — no missing entries and no stale ones — and every member must be a
    string. This is the assertion `preferred_accelerator`'s 7-member enum with
    6 `optionHelp` entries and a `null` member would have failed.
  - Every `default` must be type-consistent with its declared `type`, and must fall
    inside its own `minimum`/`maximum`.
  - `helpLinks` entries must be well-formed, https, syntactically valid, and
    non-duplicated within a property. Shape only — no network I/O.
- **`tests/Schema/SchemaLinkProbe.php`** and a `@group network` liveness test per
  schema, excluded from the default run via `phpunit.xml` so the suite stays
  offline; run with `vendor/bin/phpunit --group network`.
- Tests asserting that every key's first dotted segment is a flat config file name,
  that the credential keys are marked secret (and that nothing else claims to be),
  that the `database.*`/`jwt.*`/`websocket.*` namespaces stay absent from the server
  schema, and that every `DENIED_KEYS` entry stays absent from the hub schema.

### Fixed — tooling

- **`phpunit.xml` now sets `failOnEmptyTestSuite="true"`.** A mistyped
  `--testsuite=unit` (lowercase) printed "No tests executed!" and exited **0**, so a
  CI job could pass having run nothing. Verified: the same invocation now exits 1.

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
