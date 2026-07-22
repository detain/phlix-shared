<?php

/**
 * Subtitle File.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Subtitle;

/**
 * Immutable value object returned by {@see SubtitleSourceInterface::download()}:
 * the actual downloaded subtitle, ready for the host to persist to
 * `/var/subtitles` and attach to a media item as a selectable track.
 *
 * A pure value object with zero I/O — it holds the decoded subtitle CONTENT in
 * memory plus the metadata needed to write it out (a suggested filename, the
 * character encoding the content is in, the language/format, and provenance).
 *
 * @package Phlix\Shared\Subtitle
 * @since 0.42.0
 */
final readonly class SubtitleFile
{
    /**
     * @param non-empty-string $language          ISO 639 language code of the subtitle (e.g. `en`, `pt-BR`).
     * @param non-empty-string $format            Subtitle format extension without a dot (e.g. `srt`, `ass`, `vtt`).
     * @param string           $content           The decoded subtitle content (the full file text).
     * @param non-empty-string $provider          Canonical source name that produced this file
     *                                             (matches {@see SubtitleSourceInterface::getName()}).
     * @param non-empty-string $suggestedFilename Filename the host should use when persisting to
     *                                             `/var/subtitles` (e.g. `The.Matrix.1999.en.srt`). Base name
     *                                             only — no directory component.
     * @param string           $encoding          Character encoding of $content (e.g. `UTF-8`), so the host can
     *                                             transcode/write it correctly. Defaults to `UTF-8`.
     * @param string|null      $releaseName       Original provider release name the file came from, or null.
     * @param bool             $hearingImpaired   Whether this is a hearing-impaired (SDH) subtitle.
     */
    public function __construct(
        public string $language,
        public string $format,
        public string $content,
        public string $provider,
        public string $suggestedFilename,
        public string $encoding = 'UTF-8',
        public ?string $releaseName = null,
        public bool $hearingImpaired = false,
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
}
