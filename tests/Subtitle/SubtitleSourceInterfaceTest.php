<?php

/**
 * Subtitle Source Interface Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Subtitle;

use Phlix\Shared\Subtitle\Exception\QuotaExceeded;
use Phlix\Shared\Subtitle\SubtitleCandidate;
use Phlix\Shared\Subtitle\SubtitleFile;
use Phlix\Shared\Subtitle\SubtitleSourceInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Locks the {@see SubtitleSourceInterface} contract: the exact method set and
 * signatures the host server's subtitle source registry compiles against, and
 * proves the contract is satisfiable end-to-end (search fan-out + on-demand,
 * quota-throwing download) through a fake implementer.
 *
 * @coversNothing
 */
final class SubtitleSourceInterfaceTest extends TestCase
{
    public function test_is_an_interface(): void
    {
        $reflection = new ReflectionClass(SubtitleSourceInterface::class);
        $this->assertTrue(
            $reflection->isInterface(),
            'SubtitleSourceInterface must be an interface.'
        );
    }

    public function test_interface_declares_exactly_the_expected_methods(): void
    {
        $reflection = new ReflectionClass(SubtitleSourceInterface::class);

        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods()
        );
        sort($methods);

        $this->assertSame(
            ['download', 'getName', 'getPriority', 'searchByHash', 'searchByImdbId', 'searchByPath'],
            $methods,
            'SubtitleSourceInterface must declare exactly getName, getPriority, searchByPath, '
            . 'searchByHash, searchByImdbId, download.'
        );
    }

    public function test_getName_signature(): void
    {
        $method = new ReflectionMethod(SubtitleSourceInterface::class, 'getName');
        $this->assertCount(0, $method->getParameters());
        $this->assertReturnTypeName('string', $method);
    }

    public function test_getPriority_signature(): void
    {
        $method = new ReflectionMethod(SubtitleSourceInterface::class, 'getPriority');
        $this->assertCount(0, $method->getParameters());
        $this->assertReturnTypeName('int', $method);
    }

    public function test_searchByPath_signature(): void
    {
        $method = new ReflectionMethod(SubtitleSourceInterface::class, 'searchByPath');
        $params = $method->getParameters();

        $this->assertCount(2, $params);

        $this->assertSame('path', $params[0]->getName());
        $this->assertParamTypeName('string', $params[0]->getType());
        $this->assertFalse($params[0]->isOptional(), 'path must be required.');

        $this->assertSame('languages', $params[1]->getName());
        $this->assertParamTypeName('array', $params[1]->getType());
        $this->assertFalse($params[1]->isOptional(), 'languages must be required.');

        $this->assertReturnTypeName('array', $method);
    }

    public function test_searchByHash_signature(): void
    {
        $method = new ReflectionMethod(SubtitleSourceInterface::class, 'searchByHash');
        $params = $method->getParameters();

        $this->assertCount(3, $params);

        $this->assertSame('movieHash', $params[0]->getName());
        $this->assertParamTypeName('string', $params[0]->getType());

        $this->assertSame('byteSize', $params[1]->getName());
        $this->assertParamTypeName('int', $params[1]->getType());

        $this->assertSame('languages', $params[2]->getName());
        $this->assertParamTypeName('array', $params[2]->getType());

        $this->assertReturnTypeName('array', $method);
    }

    public function test_searchByImdbId_signature(): void
    {
        $method = new ReflectionMethod(SubtitleSourceInterface::class, 'searchByImdbId');
        $params = $method->getParameters();

        $this->assertCount(2, $params);

        $this->assertSame('imdbId', $params[0]->getName());
        $this->assertParamTypeName('string', $params[0]->getType());

        $this->assertSame('languages', $params[1]->getName());
        $this->assertParamTypeName('array', $params[1]->getType());

        $this->assertReturnTypeName('array', $method);
    }

    public function test_download_signature(): void
    {
        $method = new ReflectionMethod(SubtitleSourceInterface::class, 'download');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('candidate', $params[0]->getName());
        $this->assertParamTypeName(SubtitleCandidate::class, $params[0]->getType());

        $this->assertReturnTypeName(SubtitleFile::class, $method);
    }

    public function test_interface_is_implementable_and_exposes_identity(): void
    {
        $source = $this->fakeSource();

        $this->assertInstanceOf(SubtitleSourceInterface::class, $source);
        $this->assertSame('opensubtitles', $source->getName());
        $this->assertSame(10, $source->getPriority());
    }

    public function test_search_fan_out_round_trips_through_a_fake_implementer(): void
    {
        $source = $this->fakeSource();

        $byPath = $source->searchByPath('/media/The.Matrix.1999.mkv', ['en']);
        $this->assertCount(1, $byPath);
        $this->assertSame('opensubtitles', $byPath[0]->provider);
        $this->assertSame('en', $byPath[0]->language);
        $this->assertSame(SubtitleCandidate::MATCH_NAME, $byPath[0]->matchedBy);

        $byHash = $source->searchByHash('8e245d9679d31e12', 742086656, ['en']);
        $this->assertCount(1, $byHash);
        $this->assertTrue($byHash[0]->isHashMatch());

        $byImdb = $source->searchByImdbId('tt0133093', ['en']);
        $this->assertCount(1, $byImdb);
        $this->assertSame(SubtitleCandidate::MATCH_IMDB, $byImdb[0]->matchedBy);

        // Empty inputs / no match -> empty list.
        $this->assertSame([], $source->searchByImdbId('tt0000000', ['en']));
    }

    public function test_download_returns_a_subtitle_file(): void
    {
        $source = $this->fakeSource();
        $candidate = $source->searchByHash('8e245d9679d31e12', 742086656, ['en'])[0];

        $file = $source->download($candidate);
        $this->assertInstanceOf(SubtitleFile::class, $file);
        $this->assertSame('en', $file->language);
        $this->assertSame('srt', $file->format);
        $this->assertStringContainsString('Wake up', $file->content);
        $this->assertSame('opensubtitles', $file->provider);
    }

    public function test_download_may_throw_quota_exceeded(): void
    {
        $source = $this->fakeSource();
        $exhausted = new SubtitleCandidate(
            provider: 'opensubtitles',
            language: 'en',
            downloadId: 'QUOTA',
            releaseName: 'The.Matrix.1999',
            format: 'srt',
        );

        $this->expectException(QuotaExceeded::class);
        $source->download($exhausted);
    }

    /**
     * A minimal anonymous-class implementer proving the contract is satisfiable
     * and that PHP accepts every declared signature (including the DTO
     * parameter/return types).
     */
    private function fakeSource(): SubtitleSourceInterface
    {
        return new class implements SubtitleSourceInterface {
            public function getName(): string
            {
                return 'opensubtitles';
            }

            public function getPriority(): int
            {
                return 10;
            }

            public function searchByPath(string $path, array $languages): array
            {
                if ($path === '') {
                    return [];
                }

                return [new SubtitleCandidate(
                    provider: 'opensubtitles',
                    language: $this->firstLanguage($languages),
                    downloadId: 'file-1',
                    releaseName: 'The.Matrix.1999.1080p',
                    format: 'srt',
                    matchedBy: SubtitleCandidate::MATCH_NAME,
                )];
            }

            public function searchByHash(string $movieHash, int $byteSize, array $languages): array
            {
                if ($movieHash === '') {
                    return [];
                }

                return [new SubtitleCandidate(
                    provider: 'opensubtitles',
                    language: $this->firstLanguage($languages),
                    downloadId: 'file-2',
                    releaseName: 'The.Matrix.1999.1080p',
                    format: 'srt',
                    matchedBy: SubtitleCandidate::MATCH_HASH,
                    rating: 9.5,
                    downloadCount: 12000,
                )];
            }

            public function searchByImdbId(string $imdbId, array $languages): array
            {
                if ($imdbId !== 'tt0133093') {
                    return [];
                }

                return [new SubtitleCandidate(
                    provider: 'opensubtitles',
                    language: $this->firstLanguage($languages),
                    downloadId: 'file-3',
                    releaseName: 'The.Matrix.1999',
                    format: 'srt',
                    matchedBy: SubtitleCandidate::MATCH_IMDB,
                )];
            }

            /**
             * @param list<string> $languages
             * @return non-empty-string
             */
            private function firstLanguage(array $languages): string
            {
                $lang = $languages[0] ?? 'en';

                return $lang === '' ? 'en' : $lang;
            }

            public function download(SubtitleCandidate $candidate): SubtitleFile
            {
                if ($candidate->downloadId === 'QUOTA') {
                    throw new QuotaExceeded(
                        'Daily download quota exhausted.',
                        downloadsRemaining: 0,
                        resetTimeUtc: '2026-07-22T00:00:00+00:00',
                    );
                }

                return new SubtitleFile(
                    language: $candidate->language,
                    format: $candidate->format,
                    content: "1\n00:00:01,000 --> 00:00:03,000\nWake up, Neo.\n",
                    provider: $candidate->provider,
                    suggestedFilename: 'The.Matrix.1999.en.srt',
                );
            }
        };
    }

    private function assertReturnTypeName(string $expected, ReflectionMethod $method): void
    {
        $returnType = $method->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame($expected, $returnType->getName());
    }

    private function assertParamTypeName(string $expected, ?\ReflectionType $type): void
    {
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame($expected, $type->getName());
    }
}
