<?php

/**
 * Subtitle File Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Subtitle;

use Phlix\Shared\Subtitle\SubtitleFile;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Subtitle\SubtitleFile
 */
final class SubtitleFileTest extends TestCase
{
    public function test_full_field_round_trip(): void
    {
        $file = new SubtitleFile(
            language: 'es',
            format: 'srt',
            content: "1\n00:00:01,000 --> 00:00:02,000\nHola.\n",
            provider: 'opensubtitles',
            suggestedFilename: 'Movie.2020.es.srt',
            encoding: 'ISO-8859-1',
            releaseName: 'Movie.2020.1080p',
            hearingImpaired: true,
        );

        $this->assertSame('es', $file->language);
        $this->assertSame('srt', $file->format);
        $this->assertStringContainsString('Hola.', $file->content);
        $this->assertSame('opensubtitles', $file->provider);
        $this->assertSame('Movie.2020.es.srt', $file->suggestedFilename);
        $this->assertSame('ISO-8859-1', $file->encoding);
        $this->assertSame('Movie.2020.1080p', $file->releaseName);
        $this->assertTrue($file->hearingImpaired);
    }

    public function test_optional_fields_default_sensibly(): void
    {
        $file = new SubtitleFile(
            language: 'en',
            format: 'vtt',
            content: 'WEBVTT',
            provider: 'opensubtitles',
            suggestedFilename: 'Foo.en.vtt',
        );

        $this->assertSame('UTF-8', $file->encoding);
        $this->assertNull($file->releaseName);
        $this->assertFalse($file->hearingImpaired);
        $this->assertFalse($file->isHearingImpaired());
    }
}
