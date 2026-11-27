<?php

/**
 * Readme Examples Test.
 *
 * Pins the `## Consumer examples` section of README.md to the shipped public
 * API. The section exists so consumers can copy-paste the three
 * highest-friction surfaces (*arr client construction, the HTTP-over-relay
 * framing sequence, the settings value codec) without reading `src/` first;
 * this test makes that promise durable: every documented call shape must
 * survive in the prose verbatim, every `use` line must resolve through the
 * autoloader, the reflection arity of each constructor and static method the
 * examples lean on is pinned here, and each snippet's logic is re-executed
 * hermetically against the current API. An API rename cannot land without
 * updating the examples in the same commit.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests;

use Phlix\Shared\Arr\ArrClientFactory;
use Phlix\Shared\Arr\RadarrClient;
use Phlix\Shared\Arr\SonarrClient;
use Phlix\Shared\Arr\Transport\ArrTransportInterface;
use Phlix\Shared\Relay\RelayHttpRequest;
use Phlix\Shared\Relay\RelayHttpRequestChunk;
use Phlix\Shared\Relay\RelayHttpRequestCodec;
use Phlix\Shared\Relay\RelayHttpRequestHead;
use Phlix\Shared\Relay\RelayHttpResponseChunk;
use Phlix\Shared\Relay\RelayHttpResponseCodec;
use Phlix\Shared\Relay\RelayHttpResponseHead;
use Phlix\Shared\Settings\SettingsCodec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionParameter;

#[CoversClass(ArrClientFactory::class)]
#[CoversClass(RadarrClient::class)]
#[CoversClass(RelayHttpRequestCodec::class)]
#[CoversClass(RelayHttpResponseCodec::class)]
#[CoversClass(SettingsCodec::class)]
final class ReadmeExamplesTest extends TestCase
{
    /**
     * Exact level-two heading that opens the pinned section.
     */
    private const SECTION_HEADING = '## Consumer examples';

    /**
     * Captures each `use Phlix\Shared\...;` import inside the section.
     */
    private const USE_PATTERN = '/^use (Phlix\\\\Shared\\\\[A-Za-z0-9_\\\\]+);$/m';

    /**
     * Literal call shapes the README must carry verbatim. Each row is the
     * needle; it must appear inside the extracted `## Consumer examples`
     * section exactly as written.
     *
     * @return iterable<string, array{string}>
     */
    public static function pinnedCallShapes(): iterable
    {
        yield 'factory construction' => [
            'new ArrClientFactory([',
        ];
        yield 'sonarr factory leg' => ['->createSonarrClient()'];
        yield 'radarr factory leg with logger' => ['->createRadarrClient($logger)'];
        yield 'quality profiles through the factory client' => ['$radarr?->getQualityProfiles()'];
        yield 'bundled transport default is named' => ['CurlArrTransport'];
        yield 'anonymous transport seam' => ['new class implements ArrTransportInterface'];
        yield 'transport request signature' => [
            'public function request(string $method, string $url, array $headers, ?string $body): array',
        ];
        yield 'direct client with injected transport' => [
            "new RadarrClient('http://radarr.local:7878', 'radarr-api-key', null, 30, \$transport)",
        ];
        yield 'request head construction' => [
            "new RelayHttpRequestHead('POST', '/api/v1/libraries', '', ['Content-Type' => 'application/json'])",
        ];
        yield 'request head encode' => ['RelayHttpRequestCodec::encodeHead('];
        yield 'request body chunking' => ['RelayHttpRequestCodec::chunkBody($requestBody)'];
        yield 'request end encode' => ['RelayHttpRequestCodec::encodeEnd()'];
        yield 'request payload decode' => ['RelayHttpRequestCodec::decode($payload)'];
        yield 'request head kind' => ['RelayHttpRequestChunk::KIND_HEAD'];
        yield 'request body kind' => ['RelayHttpRequestChunk::KIND_BODY'];
        yield 'request end kind' => ['RelayHttpRequestChunk::KIND_END'];
        yield 'response head construction with streaming leg' => [
            "new RelayHttpResponseHead(200, ['Content-Type' => 'application/json'], null)",
        ];
        yield 'response head encode' => ['RelayHttpResponseCodec::encodeHead('];
        yield 'response body chunking' => ['RelayHttpResponseCodec::chunkBody($responseBody)'];
        yield 'response end encode' => ['RelayHttpResponseCodec::encodeEnd()'];
        yield 'response payload decode' => ['RelayHttpResponseCodec::decode($responsePayload)'];
        yield 'response head kind' => ['RelayHttpResponseChunk::KIND_HEAD'];
        yield 'response body kind' => ['RelayHttpResponseChunk::KIND_BODY'];
        yield 'body chunk ceiling is named' => ['MAX_BODY_CHUNK'];
        yield 'json settings encode' => ["SettingsCodec::encode(['tmdb', 'imdb'], 'json')"];
        yield 'json settings decode' => ["SettingsCodec::decode(\$stored, 'json')"];
        yield 'int settings encode' => ["SettingsCodec::encode(7, 'int')"];
        yield 'bool settings decode' => ["SettingsCodec::decode('1', 'bool')"];
        yield 'effective falls through null' => ["SettingsCodec::effective(null, 'midnight')"];
        yield 'effective keeps stored override' => ["SettingsCodec::effective('nocturne', 'midnight')"];
        yield 'clamp pins the ceiling' => ['SettingsCodec::clampValue(42, 1, 30)'];
        yield 'gated request head parse is named' => ['RelayHttpRequestHead::fromJsonGated()'];
        yield 'request safety gate is named' => ['RelayHttpRequest::assertSafe()'];
        yield 'enforcement file is named' => ['tests/ReadmeExamplesTest.php'];
    }

    /**
     * (class, method, expected parameter names in order, expected optional
     * parameter names in order) for every constructor/method the examples
     * call. The README prose documents these arities; a drift here means the
     * snippets stopped compiling.
     *
     * @return iterable<string, array{class-string, string, list<string>, list<string>}>
     */
    public static function documentedArity(): iterable
    {
        yield 'arr factory constructor' => [
            ArrClientFactory::class,
            '__construct',
            ['config', 'transport'],
            ['transport'],
        ];
        yield 'sonarr factory method' => [
            ArrClientFactory::class,
            'createSonarrClient',
            ['logger'],
            ['logger'],
        ];
        yield 'radarr factory method' => [
            ArrClientFactory::class,
            'createRadarrClient',
            ['logger'],
            ['logger'],
        ];
        yield 'direct client constructor (inherited)' => [
            RadarrClient::class,
            '__construct',
            ['baseUrl', 'apiKey', 'logger', 'timeout', 'transport', 'connectTimeout'],
            ['logger', 'timeout', 'transport', 'connectTimeout'],
        ];
        yield 'transport seam method' => [
            ArrTransportInterface::class,
            'request',
            ['method', 'url', 'headers', 'body'],
            [],
        ];
        yield 'request head constructor' => [
            RelayHttpRequestHead::class,
            '__construct',
            ['method', 'path', 'query', 'headers'],
            [],
        ];
        yield 'response head constructor' => [
            RelayHttpResponseHead::class,
            '__construct',
            ['status', 'headers', 'bodyLength'],
            ['bodyLength'],
        ];
        yield 'codec encode' => [
            SettingsCodec::class,
            'encode',
            ['value', 'valueType'],
            [],
        ];
        yield 'codec decode' => [
            SettingsCodec::class,
            'decode',
            ['raw', 'valueType'],
            [],
        ];
        yield 'codec effective' => [
            SettingsCodec::class,
            'effective',
            ['override', 'default'],
            [],
        ];
        yield 'codec clampValue' => [
            SettingsCodec::class,
            'clampValue',
            ['value', 'min', 'max'],
            [],
        ];
    }

    public function testTheConsumerExamplesSectionExistsAndCarriesPhpSnippets(): void
    {
        $section = self::consumerExamplesSection();

        $opened = preg_match_all('/^```php$/m', $section, $ignored);
        self::assertNotFalse($opened, 'the fence scan must run.');
        self::assertGreaterThanOrEqual(
            3,
            $opened,
            'the section must carry at least 3 fenced php snippets; found ' . $opened
            . ' — copy-pasteable examples are the whole point of the section.'
        );
        self::assertLessThanOrEqual(
            5,
            $opened,
            'the section must carry at most 5 fenced php snippets; found ' . $opened
            . ' — the pinned vocabulary is sized for four.'
        );

        $closed = preg_match_all('/^```$/m', $section, $ignored);
        self::assertNotFalse($closed, 'the fence scan must run.');
        self::assertSame(
            $opened,
            $closed,
            'every opened php fence must be closed (open=' . $opened . ', close=' . $closed . ').'
        );
    }

    /**
     * @param string $needle literal call shape the README must carry.
     */
    #[DataProvider('pinnedCallShapes')]
    public function testDocumentedCallShapesSurviveVerbatim(string $needle): void
    {
        $section = self::consumerExamplesSection();

        self::assertStringContainsString(
            $needle,
            $section,
            'README.md `## Consumer examples` must document the call shape `' . $needle . '`'
            . ' verbatim — the snippets are the consumer contract this test pins.'
        );
    }

    public function testEveryUseStatementInTheExamplesResolvesAgainstTheAutoloader(): void
    {
        $section = self::consumerExamplesSection();

        $matched = preg_match_all(self::USE_PATTERN, $section, $matches, PREG_SET_ORDER);
        self::assertNotFalse($matched, 'the import scan must run.');
        self::assertGreaterThanOrEqual(
            10,
            $matched,
            'the examples must import every class they lean on; only ' . $matched
            . ' `use Phlix\Shared\...` lines were found.'
        );

        $unresolved = [];
        foreach ($matches as $match) {
            self::assertIsArray($match);
            $fqcn = $match[1];
            self::assertIsString($fqcn);
            if (!class_exists($fqcn) && !interface_exists($fqcn)) {
                $unresolved[] = $fqcn;
            }
        }

        self::assertSame(
            [],
            $unresolved,
            'every class imported by the README examples must exist in the shipped API; unresolved: '
            . implode(', ', $unresolved)
        );
    }

    /**
     * @param class-string $class
     * @param string       $method
     * @param list<string> $expectedParams
     * @param list<string> $expectedOptional
     */
    #[DataProvider('documentedArity')]
    public function testDocumentedCallSitesKeepTheirReflectionArity(
        string $class,
        string $method,
        array $expectedParams,
        array $expectedOptional
    ): void {
        self::assertTrue(
            method_exists($class, $method),
            $class . '::' . $method . '() is documented in the README examples and must exist.'
        );

        $reflection = new ReflectionMethod($class, $method);
        $names = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $reflection->getParameters()
        );

        self::assertSame(
            $expectedParams,
            $names,
            $class . '::' . $method . '() parameter list drifted from the documented shape '
            . '(README says (' . implode(', ', $expectedParams) . '), reflection says (' . implode(', ', $names) . ')).'
        );

        $optional = [];
        foreach ($reflection->getParameters() as $parameter) {
            if ($parameter->isOptional()) {
                $optional[] = $parameter->getName();
            }
        }

        self::assertSame(
            $expectedOptional,
            $optional,
            $class . '::' . $method . '() optionality drifted from the documented shape; '
            . 'README depends on exactly these defaulted positions.'
        );
    }

    public function testTheRelayCodecsExposeTheDocumentedStaticSequenceApi(): void
    {
        $sequence = ['encodeHead', 'encodeBody', 'chunkBody', 'encodeEnd', 'decode'];

        foreach ([RelayHttpRequestCodec::class, RelayHttpResponseCodec::class] as $codec) {
            foreach ($sequence as $method) {
                self::assertTrue(
                    method_exists($codec, $method),
                    $codec . '::' . $method . '() is part of the documented HEAD -> BODY* -> END sequence.'
                );
                self::assertTrue(
                    (new ReflectionMethod($codec, $method))->isStatic(),
                    $codec . '::' . $method . '() must stay static — the examples never instantiate the codec.'
                );
            }
        }
    }

    public function testTheDocumentedSecurityGateMethodsExist(): void
    {
        // The class and method names travel through loop variables exactly as
        // they do in the codec-sequence pin above: the check stays a runtime
        // falsifiable assertion (a rename or removal reddens this test) while
        // refusing phpstan level 9 the constant-fold that would reclassify a
        // passing pin as an always-true alreadyNarrowedType call.
        $documented = [
            [RelayHttpRequestHead::class, 'fromJsonGated'],
            [RelayHttpRequest::class, 'assertSafe'],
        ];

        foreach ($documented as [$class, $method]) {
            self::assertTrue(
                method_exists($class, $method),
                $class . '::' . $method . '() is named by the README security guidance and'
                . ' pinned as a call-shape needle — a rename or removal must update the examples.'
            );
        }
    }

    public function testTheChunkClassesCarryTheDocumentedKindVocabulary(): void
    {
        foreach ([RelayHttpRequestChunk::class, RelayHttpResponseChunk::class] as $chunk) {
            $documented = [
                'KIND_HEAD' => 'head',
                'KIND_BODY' => 'body',
                'KIND_END' => 'end',
            ];
            foreach ($documented as $constant => $value) {
                self::assertTrue(
                    defined($chunk . '::' . $constant),
                    $chunk . '::' . $constant . ' is branched on by the README reconstitution loop.'
                );
                self::assertSame(
                    $value,
                    constant($chunk . '::' . $constant),
                    $chunk . '::' . $constant . ' must keep the wire vocabulary the loop matches.'
                );
            }
        }
    }

    public function testTheFactoryExampleBuildsEnabledClientsAndYieldsNullWhenDisabled(): void
    {
        // Mirrors the README `*arr clients: factory construction` snippet.
        $factory = new ArrClientFactory([
            'sonarr' => ['url' => 'http://sonarr.local:8989', 'api_key' => 'sonarr-api-key', 'enabled' => true],
            'radarr' => ['url' => 'http://radarr.local:7878', 'api_key' => 'radarr-api-key', 'enabled' => true],
        ]);

        self::assertInstanceOf(SonarrClient::class, $factory->createSonarrClient());
        self::assertInstanceOf(RadarrClient::class, $factory->createRadarrClient(null));

        $off = new ArrClientFactory([
            'sonarr' => ['url' => 'http://sonarr.local:8989', 'api_key' => 'sonarr-api-key', 'enabled' => false],
            'radarr' => ['url' => 'http://radarr.local:7878', 'api_key' => 'radarr-api-key', 'enabled' => false],
        ]);

        self::assertNull($off->createSonarrClient(), 'a disabled section must yield null, as documented.');
        self::assertNull($off->createRadarrClient(), 'a disabled section must yield null, as documented.');
    }

    public function testTheTransportSeamExampleRunsHermetically(): void
    {
        // Mirrors the README `*arr clients: the transport seam` snippet exactly.
        $transport = new class implements ArrTransportInterface {
            public function request(string $method, string $url, array $headers, ?string $body): array
            {
                return ['status' => 200, 'body' => '[]'];
            }
        };

        $client = new RadarrClient('http://radarr.local:7878', 'radarr-api-key', null, 30, $transport);

        self::assertSame(
            [],
            $client->getQualityProfiles(),
            'the canned empty list must decode to zero profiles without any network.'
        );
    }

    public function testTheRelayRequestSequenceExampleRoundTrips(): void
    {
        // Mirrors the README `HTTP-over-relay` request-side snippet exactly.
        $requestBody = '{"name":"Films"}';

        $payloads = array_merge(
            [RelayHttpRequestCodec::encodeHead(new RelayHttpRequestHead('POST', '/api/v1/libraries', '', ['Content-Type' => 'application/json']))],
            RelayHttpRequestCodec::chunkBody($requestBody),
            [RelayHttpRequestCodec::encodeEnd()]
        );

        self::assertSame(
            3,
            count($payloads),
            'a small body must frame as exactly HEAD + 1 BODY + END, as the example shows.'
        );

        $method = null;
        $reassembled = '';
        $complete = false;
        foreach ($payloads as $payload) {
            $chunk = RelayHttpRequestCodec::decode($payload);
            if ($chunk->kind === RelayHttpRequestChunk::KIND_HEAD) {
                $method = $chunk->head?->method;
            } elseif ($chunk->kind === RelayHttpRequestChunk::KIND_BODY) {
                $reassembled .= $chunk->body;
            } else {
                $complete = true;
            }
        }

        self::assertSame('POST', $method, 'the decoded head must carry the documented method.');
        self::assertSame($requestBody, $reassembled, 'BODY frames must reassemble the request verbatim.');
        self::assertTrue($complete, 'the END leg must close the sequence.');
    }

    public function testTheRelayResponseSequenceExampleRoundTrips(): void
    {
        // Mirrors the README `HTTP-over-relay` response-side snippet exactly.
        $responseBody = '{"id":1}';
        $responsePayloads = array_merge(
            [RelayHttpResponseCodec::encodeHead(new RelayHttpResponseHead(200, ['Content-Type' => 'application/json'], null))],
            RelayHttpResponseCodec::chunkBody($responseBody),
            [RelayHttpResponseCodec::encodeEnd()]
        );

        $receivedStatus = null;
        $receivedBody = '';
        foreach ($responsePayloads as $responsePayload) {
            $responseChunk = RelayHttpResponseCodec::decode($responsePayload);
            if ($responseChunk->kind === RelayHttpResponseChunk::KIND_HEAD) {
                $receivedStatus = $responseChunk->head?->status;
            } elseif ($responseChunk->kind === RelayHttpResponseChunk::KIND_BODY) {
                $receivedBody .= $responseChunk->body;
            }
        }

        self::assertSame(200, $receivedStatus, 'the decoded head must carry the documented status.');
        self::assertSame($responseBody, $receivedBody, 'BODY frames must reassemble the response verbatim.');
    }

    public function testTheSettingsCodecExampleProducesTheDocumentedValues(): void
    {
        // Mirrors the README `Settings value codec` snippet exactly.
        $stored = SettingsCodec::encode(['tmdb', 'imdb'], 'json');
        self::assertSame('["tmdb","imdb"]', $stored);
        self::assertSame(['tmdb', 'imdb'], SettingsCodec::decode($stored, 'json'));

        self::assertSame('7', SettingsCodec::encode(7, 'int'));
        self::assertTrue(SettingsCodec::decode('1', 'bool'));

        self::assertSame('midnight', SettingsCodec::effective(null, 'midnight'));
        self::assertSame('nocturne', SettingsCodec::effective('nocturne', 'midnight'));
        self::assertSame(30, SettingsCodec::clampValue(42, 1, 30));
    }

    /**
     * Extracts the README text strictly between `## Consumer examples` and
     * the next level-two heading.
     */
    private static function consumerExamplesSection(): string
    {
        $path = dirname(__DIR__) . '/README.md';
        self::assertFileExists($path, 'README.md must exist at the package root.');

        $readme = (string) file_get_contents($path);
        $start = strpos($readme, self::SECTION_HEADING);
        self::assertIsInt(
            $start,
            'README.md must carry the "' . self::SECTION_HEADING . '" section — the consumer'
            . ' entry point for *arr clients, relay framing, and the settings codec.'
        );

        $tail = substr($readme, $start + strlen(self::SECTION_HEADING));
        $end = strpos($tail, "\n## ");
        self::assertIsInt($end, 'the Consumer examples section must end at the next level-two heading.');

        return substr($tail, 0, $end);
    }
}
