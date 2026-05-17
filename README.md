# detain/phlex-shared

Shared interfaces, DTOs, event names, and protocol types used by both
[`detain/phlex-server`](https://github.com/detain/phlex) (the media server)
and `detain/phlex-hub` (the multi-server hub, forthcoming). Composer-installable,
PHP 8.3+, zero I/O — pure interfaces and value objects only.

## Status

**v0.2.0 — Plugin / Events / Auth / Hub namespaces.** Shipped:

- `Phlex\Shared\Plugin\{LifecycleInterface, Manifest, ManifestType, ManifestValidationError, EventNameMap}`
- `Phlex\Shared\Events\{AbstractEvent, Playback\*, Library\*, Auth\*}` — 12 event DTOs.
- `Phlex\Shared\Auth\JwtClaims`
- `Phlex\Shared\Hub\{ClaimRequest, ClaimResponse, ServerInfoDto, HeartbeatDto}`
- `Phlex\Shared\Arr\` — namespace reserved for Phase K.1.

The PSR-14 dispatcher wiring (Tukio) and the manifest JSON-Schema
validator stay in `phlex-server` and consume this package via Composer.

## Requirements

- PHP `^8.3`
- Composer 2.x
- `psr/container ^2.0`
- `psr/event-dispatcher ^1.0`

The package has zero framework dependencies — no Workerman, no Monolog,
no Smarty. It is intended to be safely required by any PHP 8.3+ codebase.

## Installation

Until `detain/phlex-shared` is published to Packagist (planned post-v1.0),
consumers require it via a Composer VCS repository entry:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:detain/phlex-shared.git"
        }
    ],
    "require": {
        "detain/phlex-shared": "^0.2"
    }
}
```

Then:

```bash
composer update detain/phlex-shared
```

## Related repositories

- [`detain/phlex`](https://github.com/detain/phlex) — the Phlex media server (consumes this package from B.3 onward).
- `detain/phlex-hub` — the multi-server hub (forthcoming, B.5+).

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
