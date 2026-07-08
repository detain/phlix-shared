<?php

/**
 * contract.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Metadata;

/**
 * First-class contract a metadata-source plugin implements so the host
 * server can register it into its metadata pipeline **without** the brittle
 * `method_exists()` / FQCN-sniffing convention used today.
 *
 * ## Why this lives in `phlix-shared`
 *
 * Before this interface, an anime/metadata plugin (e.g. `phlix-plugin-anidb`,
 * `phlix-plugin-myanimelist`) had to:
 *
 *  1. probe the host PSR-11 container for the *string* FQCN
 *     `Phlix\Media\Metadata\MetadataManager`, then
 *  2. duck-type it with `is_object(...) && method_exists($manager, 'registerProvider')`, then
 *  3. wrap itself in a thin adapter implementing the **server-private**
 *     `Phlix\Media\Metadata\MetadataProviderInterface` (which is not shippable
 *     to a plugin without a runtime-autoloader gamble), and finally
 *  4. call `$manager->registerProvider($name, $adapter, $types)`.
 *
 * That whole dance exists only because there was no *shared* contract both the
 * server and a plugin could compile against. This interface is that contract:
 * a plugin's metadata-source object implements `MetadataSourceInterface`
 * directly, the host server's source registry registers any
 * `MetadataSourceInterface` instance on plugin-enable and deregisters it on
 * plugin-disable (no leak), and the admin priority list
 * (`metadata.provider_priority`) can include plugin {@see sourceName()} values
 * alongside the built-ins.
 *
 * ## Method shape (the lookup triad + identity)
 *
 * The triad ({@see search()} → {@see getDetails()} / {@see getImages()})
 * mirrors the host's existing provider-driving contract exactly, so the host
 * registry can drive a registered source the same way it drives a built-in
 * provider: resolve a free-text query to an external id, then fetch the full
 * record and images by that id. Identity is carried by {@see sourceName()}
 * (the canonical priority-map name) and {@see supportedMediaTypes()} (the
 * media types the registry indexes the source under).
 *
 * Implementations must be **non-blocking** in a resident-memory (Workerman)
 * host: any network I/O these methods perform has to be async/cooperative
 * (coroutine + an async HTTP client, or a runtime hook). Do not `sleep()` or
 * issue blocking socket reads on the worker thread.
 *
 * @package Phlix\Shared\Metadata
 * @since 0.15.0
 */
interface MetadataSourceInterface
{
    /**
     * The canonical, stable source name of this metadata source.
     *
     * This is the identity string the host keys the source on and the value
     * that appears in the per-media-type `metadata.provider_priority` lists
     * (and in a resolved record's `source` field). It MUST match the name used
     * in the host priority map so an admin ordering like
     * `['anidb', 'myanimelist', 'tvdb', 'fanart', 'local']` selects this
     * source. Built-in / well-known names include `tmdb`, `imdb`, `tvdb`,
     * `fanart`, `local`, `anidb`, and `myanimelist`.
     *
     * Return a lowercase, slug-style ASCII identifier and keep it constant for
     * the lifetime of the source (it is a persisted ordering key).
     *
     * @return non-empty-string Canonical source name (e.g. `anidb`,
     *         `myanimelist`, `tmdb`).
     *
     * @since 0.15.0
     */
    public function sourceName(): string;

    /**
     * The media types this source can provide metadata for.
     *
     * The host registry uses this to index the source per media type (the same
     * role the old `registerProvider($name, $adapter, $supportedTypes)` third
     * argument played), so the source is only consulted for matching item
     * types. Values are the host's media-type slugs, e.g. `movie`, `series`,
     * `episode`, `anime`, `artist`, `album`, `track`.
     *
     * @return list<non-empty-string> Media-type slugs this source supports
     *         (e.g. `['anime', 'series']`). Never empty for a usable source.
     *
     * @since 0.15.0
     */
    public function supportedMediaTypes(): array;

    /**
     * Search this source for items matching a free-text query.
     *
     * The host calls this first; the returned external id(s) are then fed to
     * {@see getDetails()} / {@see getImages()}. Results are ordered best-match
     * first; a source with no ranked search may return a single best match (or
     * an empty list when nothing matches). Each result MUST carry a non-empty
     * `id` (the source's external identifier, as a string) and a `title`;
     * `overview` and `poster_path` are optional enrichments.
     *
     * @param string               $query   Free-text query (e.g. a title parsed from a filename).
     * @param array<string, mixed> $options Optional hints such as `year`/`language`; unknown keys MUST be ignored.
     * @return list<array{id: non-empty-string, title: string, overview?: string, poster_path?: string}>
     *         Zero or more matches, best-match first.
     *
     * @since 0.15.0
     */
    public function search(string $query, array $options = []): array;

    /**
     * Fetch the full metadata record for an external id returned by
     * {@see search()}.
     *
     * The returned map is the source's raw-but-named detail shape; the host's
     * field mappers normalise it into the canonical record. Return an empty
     * array when the id is unknown or the lookup fails — never a partially
     * `null`-filled map (absent keys signal "this source has no value").
     *
     * @param string               $externalId External id from {@see search()} (e.g. an AniDB AID/MAL id as a string).
     * @param array<string, mixed> $options    Optional hints such as `language`; unknown keys MUST be ignored.
     * @return array<string, mixed> Detailed metadata, or `[]` when not found.
     *
     * @since 0.15.0
     */
    public function getDetails(string $externalId, array $options = []): array;

    /**
     * Fetch image URLs for an external id, grouped by image type.
     *
     * Groups are keyed by type (e.g. `poster`, `backdrop`, `fanart`, `banner`);
     * each group is an ordered list of image descriptors carrying a `url` and
     * optional pixel dimensions. Omit a group entirely when the source has no
     * images of that type (do not emit empty groups). Return an empty array
     * when the id is unknown or the source provides no images.
     *
     * @param string $externalId The external id from {@see search()}.
     * @return array<string, list<array{url: non-empty-string, width?: int, height?: int}>> Images keyed by type.
     *
     * @since 0.15.0
     */
    public function getImages(string $externalId): array;
}
