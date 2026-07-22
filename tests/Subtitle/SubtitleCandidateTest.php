<?php

/**
 * Subtitle Candidate Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Subtitle;

use Phlix\Shared\Subtitle\SubtitleCandidate;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Subtitle\SubtitleCandidate
 */
final class SubtitleCandidateTest extends TestCase
{
    public function test_full_field_round_trip(): void
    {
        $candidate = new SubtitleCandidate(
            provider: 'opensubtitles',
            language: 'pt-BR',
            downloadId: 'tok-9988',
            releaseName: 'The.Matrix.1999.1080p.BluRay',
            format: 'ass',
            matchedBy: SubtitleCandidate::MATCH_HASH,
            rating: 9.25,
            downloadCount: 54321,
            hearingImpaired: true,
            fps: 23.976,
        );

        $this->assertSame('opensubtitles', $candidate->provider);
        $this->assertSame('pt-BR', $candidate->language);
        $this->assertSame('tok-9988', $candidate->downloadId);
        $this->assertSame('The.Matrix.1999.1080p.BluRay', $candidate->releaseName);
        $this->assertSame('ass', $candidate->format);
        $this->assertSame('hash', $candidate->matchedBy);
        $this->assertSame(9.25, $candidate->rating);
        $this->assertSame(54321, $candidate->downloadCount);
        $this->assertTrue($candidate->hearingImpaired);
        $this->assertSame(23.976, $candidate->fps);
    }

    public function test_optional_fields_default_sensibly(): void
    {
        $candidate = new SubtitleCandidate(
            provider: 'opensubtitles',
            language: 'en',
            downloadId: 'tok-1',
            releaseName: 'Foo',
            format: 'srt',
        );

        $this->assertNull($candidate->matchedBy);
        $this->assertNull($candidate->rating);
        $this->assertNull($candidate->downloadCount);
        $this->assertFalse($candidate->hearingImpaired);
        $this->assertNull($candidate->fps);
    }

    public function test_is_hearing_impaired_reflects_flag(): void
    {
        $plain = new SubtitleCandidate('p', 'en', 't', 'r', 'srt');
        $sdh = new SubtitleCandidate('p', 'en', 't', 'r', 'srt', hearingImpaired: true);

        $this->assertFalse($plain->isHearingImpaired());
        $this->assertTrue($sdh->isHearingImpaired());
    }

    public function test_is_hash_match_reflects_matched_by(): void
    {
        $byHash = new SubtitleCandidate('p', 'en', 't', 'r', 'srt', matchedBy: SubtitleCandidate::MATCH_HASH);
        $byName = new SubtitleCandidate('p', 'en', 't', 'r', 'srt', matchedBy: SubtitleCandidate::MATCH_NAME);

        $this->assertTrue($byHash->isHashMatch());
        $this->assertFalse($byName->isHashMatch());
    }

    public function test_match_constants(): void
    {
        $this->assertSame('hash', SubtitleCandidate::MATCH_HASH);
        $this->assertSame('imdb', SubtitleCandidate::MATCH_IMDB);
        $this->assertSame('name', SubtitleCandidate::MATCH_NAME);
    }
}
