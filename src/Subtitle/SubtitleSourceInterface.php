<?php

/**
 * contract.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Subtitle;

use Phlix\Shared\Subtitle\Exception\QuotaExceeded;

/**
 * First-class contract a subtitle-source plugin implements so the host server
 * can register it into its subtitle pipeline **without** the brittle
 * `method_exists()` / FQCN-sniffing convention. It is the subtitle analogue of
 * {@see \Phlix\Shared\Metadata\MetadataSourceInterface}.
 *
 * ## Why this lives in `phlix-shared`
 *
 * A subtitle plugin (e.g. `phlix-plugin-opensubtitles`) and the host server
 * both need a *shared* type they can compile against: the plugin's source
 * object implements `SubtitleSourceInterface` directly, the host's subtitle
 * source registry registers any `SubtitleSourceInterface` instance on
 * plugin-enable and deregisters it on plugin-disable (no leak), and the admin
 * priority list (`subtitles.provider_priority`, added later in the server
 * settings schema) can order sources by their {@see getName()} value.
 *
 * ## Method shape (search fan-out + on-demand download)
 *
 * A subtitle lookup is a two-phase operation, deliberately split so the host
 * only spends a provider's (usually rate-limited) download quota on the ONE
 * subtitle a user actually wants:
 *
 *  1. **Search** — the host calls whichever of {@see searchByHash()},
 *     {@see searchByImdbId()} or {@see searchByPath()} it has the inputs for
 *     (best-match first is hash, then imdb id, then a path/name heuristic).
 *     Each returns a list of {@see SubtitleCandidate} value objects describing
 *     found-but-not-yet-downloaded subtitles, carrying ranking signals a UI can
 *     display and sort on. Search does NOT consume download quota.
 *  2. **Download** — the host calls {@see download()} with exactly one chosen
 *     candidate to fetch its actual file content. THIS is the quota-consuming
 *     step and MAY throw {@see QuotaExceeded}.
 *
 * Implementations must be **non-blocking** in a resident-memory (Workerman)
 * host: any network I/O these methods perform has to be async/cooperative
 * (coroutine + an async HTTP client, or a runtime hook). Do not `sleep()` or
 * issue blocking socket reads on the worker thread.
 *
 * @package Phlix\Shared\Subtitle
 * @since 0.42.0
 */
interface SubtitleSourceInterface
{
    /**
     * The canonical, stable source name of this subtitle source.
     *
     * This is the identity string the host keys the source on and the value
     * that appears in the `subtitles.provider_priority` admin ordering (and in
     * each {@see SubtitleCandidate::$provider} it returns). It MUST match the
     * name used in the host priority map. Return a lowercase, slug-style ASCII
     * identifier and keep it constant for the lifetime of the source (it is a
     * persisted ordering key), e.g. `opensubtitles`, `subscene`, `podnapisi`.
     *
     * @return non-empty-string Canonical source name (e.g. `opensubtitles`).
     *
     * @since 0.42.0
     */
    public function getName(): string;

    /**
     * The default ranking priority of this source relative to its peers.
     *
     * The host uses this as the fallback ordering when a source is not pinned in
     * the admin `subtitles.provider_priority` list. LOWER numbers sort FIRST
     * (i.e. 0 is highest priority), mirroring the usual middleware-style weight.
     * Keep it stable for a given source; admins can still override the effective
     * order via the priority setting.
     *
     * @return int Ranking weight; lower runs first (0 = highest priority).
     *
     * @since 0.42.0
     */
    public function getPriority(): int;

    /**
     * PRIMARY search: find candidate subtitles for a local media file path.
     *
     * The implementation derives whatever provider-specific keys it can from the
     * path — most notably an OpenSubtitles-style OSDb movie hash + byte size
     * computed from the file's head/tail bytes and length — and/or a normalised
     * release-name heuristic parsed from the filename. Results are ordered
     * best-match first; return an empty list when nothing matches. This method
     * does NOT consume download quota.
     *
     * @param non-empty-string $path      Absolute path to the local media file to match.
     * @param list<string>     $languages ISO 639 language codes to filter/prefer,
     *                                     best-preferred first (e.g. `['en', 'es']`).
     *                                     An empty list means "no language filter".
     * @return list<SubtitleCandidate> Zero or more candidates, best-match first.
     *
     * @since 0.42.0
     */
    public function searchByPath(string $path, array $languages): array;

    /**
     * Hash-based search: find candidate subtitles by an OSDb movie hash + size.
     *
     * This is the most precise search when the caller has already computed the
     * hash (e.g. via the opensubtitles plugin's hasher) and wants to reuse it
     * without re-reading the file. Results are ordered best-match first; return
     * an empty list when nothing matches. Does NOT consume download quota.
     *
     * @param non-empty-string $movieHash Provider movie hash (e.g. a 16-hex-char OSDb hash).
     * @param int              $byteSize  Exact byte size of the media file the hash was computed from.
     * @param list<string>     $languages ISO 639 language codes to filter/prefer, best-preferred first.
     * @return list<SubtitleCandidate> Zero or more candidates, best-match first.
     *
     * @since 0.42.0
     */
    public function searchByHash(string $movieHash, int $byteSize, array $languages): array;

    /**
     * IMDb-id search: find candidate subtitles by an IMDb identifier.
     *
     * Useful when the item has already been metadata-matched and an IMDb id is
     * known but no usable file hash is available (e.g. remote/relayed media).
     * Results are ordered best-match first; return an empty list when nothing
     * matches. Does NOT consume download quota.
     *
     * @param non-empty-string $imdbId    IMDb identifier, with or without the `tt` prefix (e.g. `tt0133093`).
     * @param list<string>     $languages ISO 639 language codes to filter/prefer, best-preferred first.
     * @return list<SubtitleCandidate> Zero or more candidates, best-match first.
     *
     * @since 0.42.0
     */
    public function searchByImdbId(string $imdbId, array $languages): array;

    /**
     * ON-DEMAND fetch of one candidate's actual subtitle file.
     *
     * Called with exactly one {@see SubtitleCandidate} previously returned by a
     * search method; the implementation uses its
     * {@see SubtitleCandidate::$downloadId} opaque token to fetch the file from
     * the provider and returns a {@see SubtitleFile} carrying the decoded
     * content ready to persist to `/var/subtitles` and attach as a track.
     *
     * This is the quota-consuming step: providers meter downloads (not
     * searches), so implementations MUST throw {@see QuotaExceeded} — carrying
     * the remaining allowance and reset time when the provider reports them —
     * when the account's download quota is exhausted, so the host can surface
     * and persist the quota state instead of silently failing.
     *
     * @param SubtitleCandidate $candidate The chosen candidate to download.
     * @return SubtitleFile The downloaded subtitle, ready to persist and attach.
     *
     * @throws QuotaExceeded When the provider's download quota is exhausted.
     *
     * @since 0.42.0
     */
    public function download(SubtitleCandidate $candidate): SubtitleFile;
}
