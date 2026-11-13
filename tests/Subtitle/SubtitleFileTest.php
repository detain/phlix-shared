<?php

/**
 * Subtitle File Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Subtitle;

use InvalidArgumentException;
use Phlix\Shared\Subtitle\SubtitleFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(\Phlix\Shared\Subtitle\SubtitleFile::class)]
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

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeFilenames(): array
    {
        return [
            'empty' => [''],
            'dot' => ['.'],
            'dot-dot' => ['..'],
            'forward slash' => ['../../etc/passwd'],
            'nested dir' => ['sub/Movie.en.srt'],
            'absolute' => ['/var/subtitles/Movie.en.srt'],
            'backslash' => ['..\\Movie.en.srt'],
            'nul byte' => ["Movie.en.srt\0.php"],
            'newline' => ["Movie\n.en.srt"],
            'unit separator' => ["Movie\x1F.en.srt"],
        ];
    }

    #[DataProvider('unsafeFilenames')]
    public function test_rejects_unsafe_suggested_filename(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        // Out-of-contract input (the param is statically non-empty-string), so
        // construct reflectively: this pins the RUNTIME gate a plugin hits.
        (new ReflectionClass(SubtitleFile::class))->newInstanceArgs([
            'language' => 'en',
            'format' => 'srt',
            'content' => '',
            'provider' => 'opensubtitles',
            'suggestedFilename' => $name,
        ]);
    }

    public function test_accepts_dotted_base_name_with_spaces_and_unicode(): void
    {
        $file = new SubtitleFile(
            language: 'en',
            format: 'srt',
            content: '',
            provider: 'opensubtitles',
            suggestedFilename: '..Amélie (2001) ..en.srt',
        );

        $this->assertSame('..Amélie (2001) ..en.srt', $file->suggestedFilename);
    }
}
