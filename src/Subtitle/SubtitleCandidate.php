<?php

/**
 * Subtitle Candidate.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Subtitle;

/**
 * Immutable value object describing a single found-but-not-yet-downloaded
 * subtitle returned from one of the {@see SubtitleSourceInterface} search
 * methods. It carries the opaque token needed to later fetch the file via
 * {@see SubtitleSourceInterface::download()} plus the ranking signals a UI
 * would display and sort on.
 *
 * A candidate is cheap to produce (search does not consume provider download
 * quota); the actual file is only fetched on demand for the ONE candidate the
 * user picks.
 *
 * @package Phlix\Shared\Subtitle
 * @since 0.42.0
 */
final readonly class SubtitleCandidate
{
    /** Candidate was matched by an exact file hash (highest confidence). */
    public const MATCH_HASH = 'hash';

    /** Candidate was matched by IMDb id. */
    public const MATCH_IMDB = 'imdb';

    /** Candidate was matched by a release-name / filename heuristic (lowest confidence). */
    public const MATCH_NAME = 'name';

    /**
     * @param non-empty-string $provider        Canonical source name that produced this candidate
     *                                           (matches {@see SubtitleSourceInterface::getName()},
     *                                           e.g. `opensubtitles`).
     * @param non-empty-string $language        ISO 639 language code of the subtitle (e.g. `en`, `pt-BR`).
     * @param non-empty-string $downloadId      Opaque provider-specific token identifying THIS file,
     *                                           passed back to {@see SubtitleSourceInterface::download()}.
     *                                           Treat as an opaque string; do not parse.
     * @param string           $releaseName     Provider release / file name (e.g. `The.Matrix.1999.1080p.BluRay`).
     * @param non-empty-string $format          Subtitle format extension without a dot (e.g. `srt`, `ass`, `vtt`).
     * @param string|null      $matchedBy       How the candidate was matched: one of self::MATCH_* , or null
     *                                           when the provider does not report it.
     * @param float|null       $rating          Provider rating (higher is better), or null when not reported.
     * @param int|null         $downloadCount   Number of times the file has been downloaded (popularity
     *                                           ranking signal), or null when not reported.
     * @param bool             $hearingImpaired Whether this is a hearing-impaired (SDH) subtitle.
     * @param float|null       $fps             Frames-per-second the subtitle timing targets (helps match
     *                                           to a release), or null when not reported.
     */
    public function __construct(
        public string $provider,
        public string $language,
        public string $downloadId,
        public string $releaseName,
        public string $format,
        public ?string $matchedBy = null,
        public ?float $rating = null,
        public ?int $downloadCount = null,
        public bool $hearingImpaired = false,
        public ?float $fps = null,
    ) {
    }

    /**
     * True when this is a hearing-impaired (SDH) subtitle.
     *
     * @return bool
     */
    public function isHearingImpaired(): bool
    {
        return $this->hearingImpaired;
    }

    /**
     * True when the candidate was matched by exact file hash (highest confidence).
     *
     * @return bool
     */
    public function isHashMatch(): bool
    {
        return $this->matchedBy === self::MATCH_HASH;
    }
}
