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
Composer-installable, PHP 8.3+, zero I/O — pure interfaces and value objects only.

## Status

**v0.10.0 — adds the HTTP-over-relay protocol types (`HTTP_REQUEST`/`HTTP_RESPONSE` frames + request/response envelopes + chunk codec) so the hub can proxy a browser's HTTP request to a paired media server over the reverse tunnel.** Cumulative surface:

- `Phlix\Shared\Plugin\{LifecycleInterface, Manifest, ManifestType, ManifestValidationError, EventNameMap}`
- `Phlix\Shared\Events\{AbstractEvent, Playback\*, Library\*, Auth\*}` — 12 event DTOs.
- `Phlix\Shared\Auth\{JwtClaims, ProviderInterface, AuthResult, UserInfo}`
- `Phlix\Shared\Metadata\MetadataSourceInterface` — the typed contract a metadata-source plugin
  implements (`sourceName()`, `supportedMediaTypes()`, `search()`, `getDetails()`, `getImages()`) so
  the server's `SourceRegistry` can register/deregister it on plugin enable/disable without the old
  `method_exists`/FQCN-sniffing convention (0.15.0+).
- `Phlix\Shared\Hub\{ClaimRequest, ClaimResponse, ServerInfoDto, HeartbeatDto}`
- `Phlix\Shared\Relay\{RelayFrameType, RelayWireCodecInterface, RelayFrame}` — channel-mux protocol (0.5+);
  plus `{RelayHttpRequest, RelayHttpResponseHead, RelayHttpResponseChunk, RelayHttpResponseCodec}` —
  HTTP-over-relay request/response envelopes + `HEAD→BODY*→END` chunk framing (0.10.0+).
- `Phlix\Shared\Arr\{BazarrClient, ProwlarrClient, RadarrClient, SonarrClient}` — *arr HTTP clients.
- `Phlix\Shared\Schema\SchemaPaths` — pure path resolver for the bundled `schemas/` files (0.7.0+).

### Bundled schemas

The package ships the canonical JSON files used by both phlix-server and the
admin SPA under `schemas/` (resolve their absolute paths via
`Phlix\Shared\Schema\SchemaPaths`):

- `schemas/manifest.schema.json` — JSON Schema (draft 2020-12) for plugin manifests,
  loaded at runtime by `phlix-server`'s `Phlix\Plugins\Manifest\ManifestSchema` validator (0.6.0+).
  Per-setting `label` and `description` are permitted, and `integer`/`boolean` are accepted as
  aliases of `int`/`bool` in the setting `type` enum (0.9.1+).
- `schemas/hub-settings.schema.json` — JSON Schema (draft 2020-12) for the editable hub
  settings (`/api/v1/me/hub-settings`), resolved via `SchemaPaths::hubSettings()` (0.22.0+).
  Its property keys must stay in lockstep with `phlix-hub`'s
  `HubSettingsRepository::ALLOWED_KEYS` — that constant is what the hub settings controller
  enumerates, and the schema supplies only the render metadata for those keys (0.24.0+).
- `schemas/server-settings.schema.json` — JSON Schema (draft 2020-12) for the editable
  server settings (`/api/v1/admin/settings`); phlix-server derives its writable allow-list
  from this schema and the admin SPA renders the settings form from it (0.7.0+).
  Extended with optional UI-metadata keywords (`label`, `helpText`, `helpLinks`, `tier`,
  `enumLabels`, `optionHelp`, `secret`, `restart`) so the admin settings UI can render
  per-option help text and split Standard vs Advanced options (0.22.0+).

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
  `TraktOAuthController::SETTING_KEY_MAP` reads those exact keys, so renaming them would
  orphan real rows (0.25.0+).
  Adds `matching.noise_suffixes` (array of strings; the admin-extensible match-title noise list —
  0.13.0+) and `metadata.provider_priority` (object: media type → ordered array of source names;
  defaults `movie`/`series` = `["tmdb","imdb"]`, `anime` = `["anidb","myanimelist","tvdb","fanart","local"]`)
  + `metadata.genres_mode` (enum `first`|`union`, default `first`) for the per-media-type metadata
  source-priority editor (0.14.0+).
- `schemas/webhook-events.json` — data catalog of the supported webhook event types for the
  admin SPA webhook picker. Distinct from the plugin PSR-14 events in `EventNameMap` (0.7.0+).
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

- PHP `^8.3`
- Composer 2.x
- `psr/container ^2.0`
- `psr/event-dispatcher ^1.0`

The package has zero framework dependencies — no Workerman, no Monolog,
no Smarty. It is intended to be safely required by any PHP 8.3+ codebase.

## Installation

Until `detain/phlix-shared` is published to Packagist (planned post-v1.0),
consumers require it via a Composer VCS repository entry. Use the HTTPS URL
so CI runners without SSH keys can resolve it:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/detain/phlix-shared.git"
        }
    ],
    "require": {
        "detain/phlix-shared": "^0.6"
    }
}
```

Then:

```bash
composer update detain/phlix-shared
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
./vendor/bin/psalm --no-progress
composer validate --strict
composer audit --no-dev
```

## License

MIT — see [`LICENSE`](LICENSE).
