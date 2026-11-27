<?php

/**
 * JsonEnvelope Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Console;

use Phlix\Shared\Console\JsonEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Characterization of the console `--json` envelope (v0.57 leg 0.12, M-26).
 * Donor twins: phlix-server src/Console/Commands/Concerns/JsonOutput.php and
 * the hub-side Phlix\Hub\Console\Commands\Concerns\JsonOutput copy — the
 * payloads they pass to their private encode() are lifted here as string
 * kernels. The Symfony-bound trait glue (isJsonMode/fail()/writeln) stays
 * per-repo (shared is PSR-only); JSON_OUTPUT_MARKER stays per-repo (it marks
 * the shipped compiled source of each CLI). Key order and flag set are the
 * bytes the per-command byte-matrix suites pin on both hosts.
 */
#[CoversClass(JsonEnvelope::class)]
final class JsonEnvelopeTest extends TestCase
{
    public function testSuccessEnvelopeIsTheExactShippedBytes(): void
    {
        self::assertSame('{"ok":true,"data":[]}', JsonEnvelope::success([]));
        self::assertSame(
            '{"ok":true,"data":[{"id":1,"name":"ada"}]}',
            JsonEnvelope::success([['id' => 1, 'name' => 'ada']])
        );
    }

    public function testDataIsNormalizedToAListPreservingOrder(): void
    {
        // The emitJsonSuccess law: array_values so even a single logical
        // record or a string-keyed map renders as a JSON array.
        self::assertSame(
            '{"ok":true,"data":[{"a":1},{"b":2}]}',
            JsonEnvelope::success(['first' => ['a' => 1], 'second' => ['b' => 2]])
        );
    }

    public function testFailureEnvelopeKeyOrderOkThenError(): void
    {
        self::assertSame('{"ok":false,"error":"user not found"}', JsonEnvelope::failure('user not found'));
    }

    public function testSlashesAndUnicodeAreUnescaped(): void
    {
        self::assertSame(
            '{"ok":true,"data":[{"url":"https://hub.example.com/app","label":"é中文"}]}',
            JsonEnvelope::success([['url' => 'https://hub.example.com/app', 'label' => 'é中文']])
        );
    }

    public function testUnencodableValuesHaltLoudly(): void
    {
        // JSON_THROW_ON_ERROR is deliberate on both hosts: an unencodable
        // value is a real bug, never a silently truncated line.
        $this->expectException(\JsonException::class);

        JsonEnvelope::success([['x' => \INF]]);
    }

    public function testKernelSurfaceIsExactlySuccessAndFailure(): void
    {
        $class = new ReflectionClass(JsonEnvelope::class);

        self::assertTrue($class->isFinal());

        $methods = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            $class->getMethods(ReflectionMethod::IS_PUBLIC)
        );
        self::assertSame(['success', 'failure'], $methods);

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertTrue($method->isStatic(), $method->getName());
            self::assertSame('string', (string) $method->getReturnType(), $method->getName());
        }
    }

    public function testOutputMarkerStaysPerRepo(): void
    {
        // Negative law: S61CLICOMMANDX4K7 marks the shipped compiled source
        // of each repo's CLI; lifting it into the package would let a merge
        // tool see a marker in a repo that does not actually carry the trait.
        self::assertArrayNotHasKey('JSON_OUTPUT_MARKER', (new ReflectionClass(JsonEnvelope::class))->getConstants());
    }
}
