<?php

/**
 * Subtitle File.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Subtitle;

use InvalidArgumentException;

/**
 * Immutable value object returned by {@see SubtitleSourceInterface::download()}:
 * the actual downloaded subtitle, ready for the host to persist to
 * `/var/subtitles` and attach to a media item as a selectable track.
 *
 * A pure value object with zero I/O — it holds the decoded subtitle CONTENT in
 * memory plus the metadata needed to write it out (a suggested filename, the
 * character encoding the content is in, the language/format, and provenance).
 *
 * VALIDATION: `$suggestedFilename` is the one field a host is invited to use as
 * a filesystem name, so the constructor gates it — it must be a non-empty base
 * name: not `.`/`..`, no `/` or `\` path separator, and no C0 control byte
 * (`\x00`-`\x1F`, NUL included). A violation throws
 * {@see InvalidArgumentException}. The other `non-empty-string` fields
 * (`$language`, `$format`, `$provider`) are static contract types only and are
 * NOT re-checked at runtime; hosts persisting a file still apply their own path
 * jail (e.g. phlix-server's `SubtitleStorage` derives the on-disk name from the
 * item id + language, not from this value).
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
     *
     * @throws InvalidArgumentException When $suggestedFilename is empty, `.`/`..`, or contains a
     *                                  path separator or a C0 control byte.
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
        self::assertBaseName($suggestedFilename);
    }

    /**
     * Gate the suggested filename to a bare, non-degenerate base name.
     *
     * @param string $name Candidate filename.
     *
     * @throws InvalidArgumentException On an empty, `.`/`..`, separator-bearing or control-byte name.
     */
    private static function assertBaseName(string $name): void
    {
        if ($name === '' || $name === '.' || $name === '..') {
            throw new InvalidArgumentException(
                'SubtitleFile "suggestedFilename" must be a non-empty base name (not "." or "..").',
            );
        }

        if (preg_match('#[/\\\\\x00-\x1F]#', $name) === 1) {
            throw new InvalidArgumentException(
                'SubtitleFile "suggestedFilename" must not contain a path separator or control byte.',
            );
        }
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
