# Changelog

All notable changes to `detain/phlex-shared` are documented here.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.0] — 2026-05-17

### Added
- `Phlex\Shared\Auth\ProviderInterface` — core interface for pluggable external
  authentication providers (OIDC, LDAP, SAML, passkeys). Zero I/O dependencies
  so both phlex-server and phlex-hub can implement providers without pulling in
  server/runtime dependencies.
- `Phlex\Shared\Auth\AuthResult` — immutable value object returned by
  `ProviderInterface::authenticate()`. Captures success/failure, local userId,
  provider externalId, error code, and arbitrary attributes (email, name,
  avatarUrl …).
- `Phlex\Shared\Auth\UserInfo` — immutable value object returned by
  `ProviderInterface::getUserInfo()`. Describes an external identity for
  account linking and profile display.

## [0.2.0] — 2026-05-17

### Added
- `Phlex\Shared\Plugin\LifecycleInterface` — moved from `Phlex\Plugins\Contract\LifecycleInterface` in `phlex-server`.
- `Phlex\Shared\Plugin\{Manifest,ManifestType,ManifestValidationError,EventNameMap}` — moved from `Phlex\Plugins\*` in `phlex-server`. Validator logic stays in `phlex-server` (`Phlex\Plugins\Manifest\ManifestSchema`).
- `Phlex\Shared\Events\{AbstractEvent, Playback\*, Library\*, Auth\*}` — moved from `Phlex\Common\Events\*` in `phlex-server` (the 12 readonly event DTOs). PSR-14 dispatcher wiring stays in `phlex-server`.
- `Phlex\Shared\Auth\JwtClaims` — new value object capturing the Phlex JWT payload shape; consumed by `phlex-hub` starting Phase C.5.
- `Phlex\Shared\Hub\{ClaimRequest,ClaimResponse,ServerInfoDto,HeartbeatDto}` — new placeholder DTOs for the hub claim/heartbeat protocol; consumed by `phlex-hub` starting Phase C.1.
- `Phlex\Shared\Arr\.gitkeep` — namespace reserved for Phase K.1's `Sonarr`/`Radarr`/etc. typed clients.

## [0.1.0] — 2026-05-17

### Added
- Initial release: composer package scaffolding, `Phlex\Shared\Version` marker class, CI workflow.
- Real interfaces and DTOs land in v0.2.0 per `plans/expansion/b.1-shared-design.md` in `detain/phlex`.
