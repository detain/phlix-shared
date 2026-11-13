<?php

/**
 * SyncPlay Dialect Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Relay;

use Phlix\Shared\Relay\SyncPlayDialect;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Laws for the canonical SyncPlay wire message-type catalog (LW-082-a).
 *
 * Three layers, mirroring the settings `ALLOWED_KEYS` drift guards:
 *   1. Intrinsic pins that always run — membership, shape, uniqueness,
 *      and `all()` agreeing with the public `TYPE_*` constants in both
 *      directions, so a const added without a list entry (or vice versa)
 *      reddens this suite where no runtime guard would notice.
 *   2. Live sibling comparators — token-based parses of the server's
 *      `Messages.php` and the hub's `SyncPlayRelayWorker.php`, skipped
 *      when no sibling checkout is reachable and failing loudly when an
 *      explicit `PHLIX_*_REPO` pointer is set but broken.
 *   3. Fixture falsifiability pins — these run even when CI checks out
 *      only this package: the parsers must read exactly the supplied
 *      source (comment decoys excluded, colon-prefixed rate-limiter keys
 *      excluded), must throw on broken shapes, and the comparator must
 *      trip on injected drift of every kind (grown, dropped, renamed,
 *      empty). Layer 2 is only trustworthy because layer 3 proves the
 *      machinery teeth it claims.
 *
 */
#[CoversClass(\Phlix\Shared\Relay\SyncPlayDialect::class)]
final class SyncPlayDialectTest extends TestCase
{
    /**
     * The nineteen canonical types in server-declaration order, written
     * out literally: this is the pin a rename has to trip.
     */
    private const CANONICAL_TYPES = [
        'syncplay_group_create',
        'syncplay_group_join',
        'syncplay_group_leave',
        'syncplay_group_state',
        'syncplay_group_list',
        'syncplay_playback_play',
        'syncplay_playback_pause',
        'syncplay_playback_seek',
        'syncplay_playback_queue',
        'syncplay_playback_sync',
        'syncplay_chat',
        'syncplay_typing',
        'syncplay_host_transfer',
        'syncplay_host_elect',
        'syncplay_time_ping',
        'syncplay_time_pong',
        'syncplay_time_sync',
        'syncplay_error',
        'syncplay_info',
    ];

    /**
     * The hub's bare relay-local family — separate vocabulary, never here.
     *
     * @var list<string>
     */
    private const BARE_RELAY_LOCAL_TYPES = ['room_state', 'client_joined', 'client_left', 'time_sync_reply'];

    public function test_the_catalog_holds_exactly_the_canonical_types(): void
    {
        self::assertSame(
            self::CANONICAL_TYPES,
            SyncPlayDialect::all(),
            'The canonical catalog drifted from its pinned membership and order.'
        );
    }

    public function test_type_values_are_unique(): void
    {
        self::assertCount(19, array_unique(SyncPlayDialect::all()));
    }

    public function test_every_canonical_type_has_the_wire_shape(): void
    {
        foreach (SyncPlayDialect::all() as $type) {
            self::assertStringStartsWith(SyncPlayDialect::WIRE_PREFIX, $type);
            self::assertMatchesRegularExpression('/^syncplay_[a-z_]+$/', $type);
        }
    }

    public function test_all_agrees_with_the_public_type_constants(): void
    {
        $fromConstants = [];
        $reflection = new ReflectionClass(SyncPlayDialect::class);
        foreach ($reflection->getReflectionConstants() as $constant) {
            if (!$constant->isPublic()) {
                continue;
            }
            $name = $constant->getName();
            if (!str_starts_with($name, 'TYPE_')) {
                continue;
            }
            $value = $constant->getValue();
            if (!is_string($value)) {
                self::fail(sprintf('Public constant %s must carry a string wire value.', $name));
            }
            $fromConstants[] = $value;
        }

        $catalog = SyncPlayDialect::all();
        sort($catalog);
        sort($fromConstants);

        self::assertSame(
            $catalog,
            $fromConstants,
            'A TYPE_* constant exists without an all() entry, or an all() entry lost its '
            . 'constant — both directions must agree.'
        );
    }

    public function test_the_bare_relay_local_family_is_not_canonical(): void
    {
        foreach (self::BARE_RELAY_LOCAL_TYPES as $type) {
            self::assertNotContains($type, SyncPlayDialect::all());
            self::assertFalse(
                str_starts_with($type, SyncPlayDialect::WIRE_PREFIX),
                sprintf('Bare relay-local type "%s" must not carry the syncplay_ prefix.', $type)
            );
        }
    }

    public function test_is_valid_type_accepts_every_canonical_type(): void
    {
        foreach (SyncPlayDialect::all() as $type) {
            self::assertTrue(SyncPlayDialect::isValidType($type));
        }
    }

    /**
     * @return array<non-empty-string, array{string}>
     */
    public static function provideInvalidTypes(): array
    {
        return [
            'rate-limiter key' => ['syncplay_mount'],
            'colon-prefixed key' => ['syncplay_mount:'],
            'bare prefix' => ['syncplay_'],
            'uppercase' => ['SYNCPLAY_ERROR'],
            'empty string' => [''],
            'dotted error-code form' => ['syncplay.queue_limit_exceeded'],
            'trailing space' => ['syncplay_group_create '],
            'leading space' => [' syncplay_group_create'],
            'hyphen instead of underscore' => ['syncplay_group-create'],
            'bare relay-local type' => ['room_state'],
        ];
    }

    #[DataProvider('provideInvalidTypes')]
    public function test_is_valid_type_rejects_non_canonical_forms(string $type): void
    {
        self::assertFalse(SyncPlayDialect::isValidType($type));
    }

    public function test_the_server_leg_agrees_with_the_catalog(): void
    {
        $path = $this->serverMessagesPath();
        if ($path === null) {
            self::markTestSkipped(
                'No phlix-server checkout reachable — set PHLIX_SERVER_REPO '
                . 'or place the sibling repo beside phlix-shared.'
            );
        }

        $source = file_get_contents($path);
        self::assertIsString($source, sprintf('Could not read server Messages source at "%s".', $path));

        self::assertCanonicalSet('server', array_values(self::parseServerTypeConstants($source)));
    }

    public function test_the_hub_leg_agrees_with_the_catalog(): void
    {
        $path = $this->hubRelayWorkerPath();
        if ($path === null) {
            self::markTestSkipped(
                'No phlix-hub checkout reachable — set PHLIX_HUB_REPO '
                . 'or place the sibling repo beside phlix-shared.'
            );
        }

        $source = file_get_contents($path);
        self::assertIsString($source, sprintf('Could not read hub relay worker source at "%s".', $path));

        self::assertCanonicalSet('hub', self::parseHubDialectLiterals($source));
    }

    public function test_the_server_parser_reads_only_the_supplied_source(): void
    {
        $fixture = <<<'PHP'
<?php
final class NotTheServer
{
    /**
     * Docblock decoy: a JSON example mentioning "syncplay_docblock_ghost"
     * must never be collected — comments are single tokens for the parser.
     */
    public const TYPE_ALPHA = 'syncplay_alpha';
    public const TYPE_BETA = 'syncplay_beta';
    public const PROTOCOL_VERSION = 7;
    private const UNLISTED = ['syncplay_beta'];
}
PHP;

        self::assertSame(
            ['TYPE_ALPHA' => 'syncplay_alpha', 'TYPE_BETA' => 'syncplay_beta'],
            self::parseServerTypeConstants($fixture),
            'The parser must collect exactly the public TYPE_* string constants of the supplied source.'
        );
    }

    /**
     * @return array<non-empty-string, array{string, non-empty-string}>
     */
    public static function provideBrokenServerShapes(): array
    {
        return [
            'no TYPE_ constants' => [
                '<?php final class X { public const VERSION = 1; }',
                'declares no',
            ],
            'bare (unquoted) value' => [
                "<?php final class X { public const TYPE_A = syncplay_a; }",
                'quoted string',
            ],
            'integer value' => [
                '<?php final class X { public const TYPE_A = 7; }',
                'quoted string',
            ],
            'value outside the wire family' => [
                "<?php final class X { public const TYPE_A = 'other_a'; }",
                'syncplay_',
            ],
            'missing terminator' => [
                "<?php final class X { public const TYPE_A = 'syncplay_a' }",
                'semicolon',
            ],
        ];
    }

    #[DataProvider('provideBrokenServerShapes')]
    public function test_the_server_parser_fails_loud_on_broken_shapes(string $fixture, string $expectedInMessage): void
    {
        try {
            self::parseServerTypeConstants($fixture);
        } catch (RuntimeException $exception) {
            self::assertStringContainsString($expectedInMessage, $exception->getMessage());

            return;
        }

        self::fail(sprintf('Expected the server-leg parser to reject the "%s" shape.', $fixture));
    }

    public function test_the_hub_parser_excludes_non_type_literals(): void
    {
        $fixture = <<<'PHP'
<?php
final class NotTheWorker
{
    public function handle(string $ip): void
    {
        $key = 'syncplay_mount:' . $ip;
        $other = 'syncplay_error';
        $barePrefix = 'syncplay_';
        $upper = 'SYNCPLAY_UPPER';
        $hyphenated = 'syncplay_group-create';
        // comment decoy: "syncplay_ghost_comment"
        $this->send('syncplay_chat');
    }
}
PHP;

        self::assertSame(
            ['syncplay_error', 'syncplay_chat'],
            self::parseHubDialectLiterals($fixture),
            'Only closed, fully-matching syncplay_ string literals are collected, in source order.'
        );
    }

    public function test_the_hub_parser_fails_loud_when_nothing_matches(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declares no');

        $fixture = '<?php final class X { public function f(): string { return "syncplay_mount:"; } }';

        self::parseHubDialectLiterals($fixture);
    }

    public function test_the_comparator_trips_on_injected_drift(): void
    {
        $catalog = SyncPlayDialect::all();

        // Control: the machinery must agree before it may disagree.
        self::assertCanonicalSet('control', $catalog);

        $grown = $catalog;
        $grown[] = 'syncplay_zeta';

        $dropped = $catalog;
        array_shift($dropped);

        $renamed = $catalog;
        $renamed[array_search('syncplay_error', $renamed, true)] = 'syncplay_errr';

        $cases = [
            'catalog grew a key' => $grown,
            'catalog dropped a key' => $dropped,
            'a member was renamed' => $renamed,
            'parsed list is empty' => [],
        ];

        foreach ($cases as $case => $parsed) {
            $tripped = false;
            try {
                self::assertCanonicalSet($case, $parsed);
            } catch (AssertionFailedError $exception) {
                $tripped = true;
            }
            self::assertTrue($tripped, sprintf('Drift case "%s" must fail the guard.', $case));
        }
    }

    /**
     * Absolute path to the server's `Messages.php`, or null when no server
     * checkout is on disk.
     *
     * `PHLIX_SERVER_REPO` is an explicit pointer: when set it is the ONLY
     * source consulted, and an unreadable target fails the test outright —
     * silently skipping a guard someone deliberately wired up would
     * recreate the exact blind spot this test exists to close. Without the
     * env var, the estate layout (`<parent>/phlix-shared` beside
     * `<parent>/phlix-server`) is probed and a missing checkout yields null
     * (skip), keeping this package testable standalone.
     */
    private function serverMessagesPath(): ?string
    {
        $env = getenv('PHLIX_SERVER_REPO');
        if (is_string($env) && $env !== '') {
            $path = rtrim($env, '/') . '/src/Session/SyncPlay/Messages.php';
            if (!is_readable($path)) {
                $this->fail(sprintf(
                    'PHLIX_SERVER_REPO points at "%s" but "%s" is not readable — fix the pointer or unset it.',
                    $env,
                    $path
                ));
            }

            return $path;
        }

        $sibling = dirname(__DIR__, 3) . '/phlix-server/src/Session/SyncPlay/Messages.php';

        return is_readable($sibling) ? $sibling : null;
    }

    /**
     * Absolute path to the hub's `SyncPlayRelayWorker.php`, or null when no
     * hub checkout is on disk. Same pointer discipline as
     * {@see self::serverMessagesPath()}; the worker is the register-named
     * source that carries the full catalog, so the leg never has to guess
     * across the hub tree (whole-src parses would hit `syncplay_port`).
     */
    private function hubRelayWorkerPath(): ?string
    {
        $env = getenv('PHLIX_HUB_REPO');
        if (is_string($env) && $env !== '') {
            $path = rtrim($env, '/') . '/src/SyncPlay/SyncPlayRelayWorker.php';
            if (!is_readable($path)) {
                $this->fail(sprintf(
                    'PHLIX_HUB_REPO points at "%s" but "%s" is not readable — fix the pointer or unset it.',
                    $env,
                    $path
                ));
            }

            return $path;
        }

        $sibling = dirname(__DIR__, 3) . '/phlix-hub/src/SyncPlay/SyncPlayRelayWorker.php';

        return is_readable($sibling) ? $sibling : null;
    }

    /**
     * Assert the parsed leg set-equals the canonical catalog, both
     * directions, refusing to pass vacuously.
     *
     * @param list<string> $parsed
     */
    private static function assertCanonicalSet(string $leg, array $parsed): void
    {
        self::assertNotEmpty(
            $parsed,
            sprintf(
                'Parsed %s-leg catalog is empty — either the source genuinely declares nothing (then the canonical '
                . 'set must be empty too) or the parser regressed. Refusing to pass vacuously.',
                $leg
            )
        );

        $expected = SyncPlayDialect::all();
        sort($expected);
        $actual = $parsed;
        sort($actual);

        self::assertSame(
            $expected,
            $actual,
            sprintf('The %s leg drifted from the canonical SyncPlay dialect catalog (LW-082).', $leg)
        );
    }

    /**
     * Collect `TYPE_*` string constants from PHP source, token-based.
     *
     * `token_get_all()` makes comments single tokens, so the docblock JSON
     * decoy that lives in the real server file (and prose in any future
     * edit) can never masquerade as a declaration. Every shape deviation
     * — missing `=`, non-string value, foreign-prefixed value, missing
     * terminator, duplicate name, or an empty result — throws: the parser
     * reads a closed list or says loudly that it could not.
     *
     * @return array<string, string> const name => wire value
     */
    private static function parseServerTypeConstants(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $types = [];

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_CONST) {
                continue;
            }

            [$nameIndex, $name] = self::nextMeaningful($tokens, $index);
            if (!is_array($name) || $name[0] !== T_STRING) {
                throw new RuntimeException('A T_CONST is not followed by a constant name.');
            }
            $constName = $name[1];

            if (!str_starts_with($constName, 'TYPE_')) {
                continue;
            }
            if (array_key_exists($constName, $types)) {
                throw new RuntimeException(sprintf('Duplicate TYPE_* constant "%s" in one source.', $constName));
            }

            [$equalsIndex, $equals] = self::nextMeaningful($tokens, $nameIndex);
            if ($equals !== '=') {
                throw new RuntimeException(sprintf('Constant %s is not followed by "=".', $constName));
            }

            [$valueIndex, $value] = self::nextMeaningful($tokens, $equalsIndex);
            if (!is_array($value) || $value[0] !== T_CONSTANT_ENCAPSED_STRING) {
                throw new RuntimeException(sprintf('Constant %s does not declare a quoted string value.', $constName));
            }

            [, $terminator] = self::nextMeaningful($tokens, $valueIndex);
            if ($terminator !== ';') {
                throw new RuntimeException(sprintf('Constant %s is not closed by a semicolon.', $constName));
            }

            $wireValue = trim($value[1], "'\"");
            if (!str_starts_with($wireValue, 'syncplay_')) {
                throw new RuntimeException(sprintf(
                    'Constant %s declares "%s" — outside the syncplay_ wire family.',
                    $constName,
                    $wireValue
                ));
            }

            $types[$constName] = $wireValue;
            $index = $valueIndex;
        }

        if ($types === []) {
            throw new RuntimeException(
                'The source declares no TYPE_* string constants — the server wire catalog lives in '
                . 'src/Session/SyncPlay/Messages.php; check the pointer.'
            );
        }

        return $types;
    }

    /**
     * Collect canonical closed `syncplay_[a-z_]+` string literals from PHP
     * source, token-based — comments cannot fool it, and the hub's
     * colon-prefixed rate-limiter keys (`'syncplay_mount:' . $ip`) are
     * naturally excluded by the shape requirement.
     *
     * @return list<string> unique matches in source order
     */
    private static function parseHubDialectLiterals(string $source): array
    {
        $found = [];
        foreach (token_get_all($source) as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $value = trim($token[1], "'\"");
            if (preg_match('/^syncplay_[a-z_]+$/', $value) === 1 && !array_key_exists($value, $found)) {
                $found[$value] = true;
            }
        }

        $types = array_keys($found);
        if ($types === []) {
            throw new RuntimeException(
                'The source declares no syncplay_-prefixed string literals — the hub wire catalog lives in '
                . 'src/SyncPlay/SyncPlayRelayWorker.php; check the pointer.'
            );
        }

        return $types;
    }

    /**
     * Next non-whitespace, non-comment token after $index.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: int, 1: array{0: int, 1: string, 2: int}|string}
     */
    private static function nextMeaningful(array $tokens, int $index): array
    {
        $next = self::indexOfMeaningful($tokens, $index);
        if ($next === null) {
            throw new RuntimeException('Source ended while a constant declaration was still open.');
        }

        return [$next, $tokens[$next]];
    }

    /**
     * Index of the next non-whitespace, non-comment token after $index, or
     * null at end of source.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function indexOfMeaningful(array $tokens, int $index): ?int
    {
        $count = count($tokens);
        for ($cursor = $index + 1; $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];
            if (is_array($token)) {
                if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
            }

            return $cursor;
        }

        return null;
    }
}
