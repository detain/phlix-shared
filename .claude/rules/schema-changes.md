---
description: Lockstep updates required when editing the bundled JSON schemas
globs: schemas/**/*.json, src/Schema/**/*.php, tests/Schema/**/*.php
alwaysApply: false
---

# Bundled schema changes

The canonical JSON files under `schemas/` are consumed by `phlix-server` and the
admin SPA. Keep edits additive (new optional properties, widened enums).

## Lockstep files

- `schemas/server-settings.schema.json` — `tests/Schema/ServerSettingsSchemaTest.php`
  hard-locks the key set with a `propertyProvider()` row list, a `pinTable()`
  full-value row (type/default/bounds/enum/restart/secret) AND an
  `assertCount(N)`. Adding or removing a key requires updating the provider row,
  the pin row AND bumping the count, or the suite fails at the `assertCount` line.
- New schema files / new resolvers — add the `SchemaPaths::xxx()` method in
  `src/Schema/SchemaPaths.php` and the matching assertions in
  `tests/Schema/SchemaPathsTest.php` (it asserts filenames, `type` enums, and
  required property keys).
- `src/Version.php` `VERSION`, a matching `## [x.y.z]` heading in `CHANGELOG.md`
  (`tests/VersionTest.php` asserts the heading exists), and an annotated `vX.Y.Z`
  git tag. `composer.json` has no `version` field — the tag drives resolution.

## Key-naming

Every `schemas/server-settings.schema.json` property key must be a dotted path
that `SettingsRepository::getDefault()` can resolve against the consuming repo's
`config/`. A key that does not resolve renders an empty control and can never
take effect.

## Restart flags

`restart: true` means the admin **Restart server** control (a graceful SIGUSR2
reload). Use it for any value captured at worker start, container build, or
route build; `restart: false` is only for keys read live through
`SettingsRepository::getEffective()` at use time.

## Verify

```bash
./vendor/bin/phpunit
composer stan
composer cs
```
