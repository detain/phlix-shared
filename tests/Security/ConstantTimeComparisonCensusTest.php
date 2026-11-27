<?php

/**
 * Constant-time comparison census.
 *
 * The security docblocks in `src/Security/` read as laws — "the comparison
 * is `hash_equals()`, a byte-for-byte operation; neither side is a prefix or
 * substring test of the other", "no comparison here is a prefix or substring
 * test; a single `===` is the whole decision", "single `hash_equals()`,
 * constant-time". Laws stated in prose but never pinned are exactly what
 * phlix-shared-014 (medium) convicted: flipping a `hash_equals()` call to
 * `===` left the whole suite green. This file turns those docblocks into
 * executable pins.
 *
 * Both scans are pure static analysis of committed source text — they make
 * no runtime assertions, so they are honest under every `zend.assertions`
 * mode, and they need no database or environment fixtures.
 *
 * LAW 1 — the hash_equals census (roster pin): every built-in
 * `hash_equals()` call site in `src/` is pinned, file and full call text, as
 * an exact multiset. The roster cannot silently lose a site (removal, or a
 * swap to `===`, changes a file's multiset) and cannot silently gain one (a
 * new timing-safe site forces a conscious roster update). Call forms that
 * resolve elsewhere — qualified (`\hash_equals(...)`), namespaced, or
 * methods (`$x->hash_equals(...)`) — are convicted as non-built-in, never
 * silently counted as the built-in.
 *
 * LAW 2 — the plaintext-comparison ban: inside the four files whose
 * docblocks declare the constant-time law, no equality operator may
 * directly compare a secret-named variable (`$secret`, `$signature`,
 * `$verifier`, `$challenge`, `$digest`, `$token`, `$password`, `$hash`,
 * `$mac`, `$nonce`, `$otp`, `$seed`) against anything that is not a neutral
 * arm (`null`, `false`, `true`, `self`, a number, an empty string, a leading
 * namespace separator, or a known guard function). Loose equality (`==`,
 * `!=`) is convicted outright against a secret-named operand — type
 * juggling over a secret (magic-hash `"0e..."` strings comparing equal to
 * `0`) is never a safe shape, however neutral the other arm looks.
 * This is what converts the docblock sentence "no comparison here is a
 * prefix or substring test" into a pin: the ban convicts
 * `if ($expected === $signature)` — the very mutation the finding says the
 * suite used to bless.
 *
 * Scope, honestly stated: the ban sees only operand-adjacent bare variables.
 * An equality operator whose operands are both wrapped — `strlen($a) ===
 * strlen($b)`, `substr($a, 0, 6) === 'Bearer'` — is a length/shape probe of
 * public framing, not a plaintext secret compare; `Hmac`'s own docblock
 * explains why such prefix tests were deliberately removed there, and
 * `Pkce`'s `strlen($challenge) !== self::CHALLENGE_LENGTH` is exactly the
 * shape probe the law continues to allow. Because LAW 1 is the full multiset
 * over `src/`, any *new* site — including a re-added plaintext compare —
 * still has to be consciously rostered.
 *
 * The four law-declaring files carry a fixed identity: their docblocks are
 * the law text, so renaming or adding such a file is a conscious edit this
 * pin surfaces.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT License https://opensource.org/licenses/MIT
 *
 * @package   Phlix\Shared\Tests
 * @author    Joe Huss <detain@interserver.net>
 * @since     2.0.0
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Security;

use FilesystemIterator;
use Phlix\Shared\Security\CredentialDigest;
use Phlix\Shared\Security\Hmac;
use Phlix\Shared\Security\Jwt;
use Phlix\Shared\Security\Pkce;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

#[CoversClass(Hmac::class)]
#[CoversClass(Jwt::class)]
#[CoversClass(Pkce::class)]
#[CoversClass(CredentialDigest::class)]
final class ConstantTimeComparisonCensusTest extends TestCase
{
    /**
     * Every built-in `hash_equals()` call site in `src/`, exactly as written
     * (whitespace collapsed), grouped by file. Pinned from the tree at
     * commit 8655195 (2026-11-27).
     *
     * @var array<string, list<string>>
     */
    private const EXPECTED_SITES = [
        'Security/CredentialDigest.php' => [
            'hash_equals($storedHex, self::digest($secret))',
        ],
        'Security/Hmac.php' => [
            'hash_equals($expected, $signature)',
        ],
        'Security/Jwt.php' => [
            'hash_equals(self::ALGORITHM, $alg)',
            'hash_equals(self::TYPE, $typ)',
            'hash_equals($expectedSignature, $signature)',
        ],
        'Security/Pkce.php' => [
            'hash_equals(self::METHOD_S256, $method)',
            'hash_equals($challenge, self::challengeFor($verifier))',
        ],
    ];

    /**
     * Files whose docblocks declare the constant-time comparison law.
     *
     * @var list<string>
     */
    private const LAW_DECLARING_FILES = [
        'Security/CredentialDigest.php',
        'Security/Hmac.php',
        'Security/Jwt.php',
        'Security/Pkce.php',
    ];

    /**
     * Variable-name vocabulary treated as secret-bearing (substring match
     * on the bare name, case-insensitive).
     *
     * @var list<string>
     */
    private const SECRET_NAME_WORDS = [
        'secret',
        'signature',
        'verifier',
        'challenge',
        'digest',
        'token',
        'password',
        'hash',
        'mac',
        'nonce',
        'otp',
        'seed',
    ];

    /**
     * Equality operators the ban scans for.
     *
     * @var list<int>
     */
    private const EQUALITY_TOKENS = [
        T_IS_IDENTICAL,
        T_IS_NOT_IDENTICAL,
        T_IS_EQUAL,
        T_IS_NOT_EQUAL,
    ];

    /**
     * Function names that legitimately sit on a neutral arm of a secret
     * compare (presence/shape guards, never plaintext value equality).
     *
     * @var list<string>
     */
    private const GUARD_FUNCTIONS = [
        'null',
        'false',
        'true',
        'self',
        'parent',
        'strcasecmp',
        'strlen',
        'preg_match',
        'count',
        'empty',
        'is_string',
        'is_int',
        'in_array',
        'hash_equals',
        'hash_hmac',
        'hash',
    ];

    public function testCensusMatchesThePinnedHashEqualsRosterExactly(): void
    {
        $actual = [];
        $nonBuiltin = [];

        foreach (self::allPhpFileRels() as $rel) {
            $calls = self::collectHashEqualsCalls(self::readSrcFile($rel), $rel);

            if ($calls['builtin'] !== []) {
                $sites = $calls['builtin'];
                sort($sites);
                $actual[$rel] = $sites;
            }

            $nonBuiltin = array_merge($nonBuiltin, $calls['other']);
        }

        $actual = self::normalizeRoster($actual);

        $expected = self::normalizeRoster(self::EXPECTED_SITES);

        self::assertSame([], $nonBuiltin, 'Qualified or method-form hash_equals call forms.');

        self::assertSame(
            $expected,
            $actual,
            'The hash_equals() census drifted from the pinned roster; a '
            . 'comparison site was added, removed, or rewritten (review the '
            . 'phlix-shared-014 law before re-rolling).'
        );
    }

    public function testRosterCoversEveryLawDeclaringFile(): void
    {
        foreach (self::LAW_DECLARING_FILES as $rel) {
            self::assertArrayHasKey(
                $rel,
                self::EXPECTED_SITES,
                $rel . ' declares a constant-time law in its docblocks and '
                . 'must carry at least one hash_equals() call in the roster.'
            );
        }
    }

    public function testNoSecretRidesAPlaintextEqualityOperatorInLawDeclaringFiles(): void
    {
        $violations = [];

        foreach (self::LAW_DECLARING_FILES as $rel) {
            $violations = array_merge(
                $violations,
                self::scanBanViolations(self::readSrcFile($rel), $rel)
            );
        }

        self::assertSame(
            [],
            $violations,
            "A plaintext equality operator compares a secret-named operand "
            . "in a law-declaring file; the docblocks forbid prefix and "
            . "value-to-value plaintext compares:\n"
            . implode("\n", $violations)
        );
    }

    public function testRosterScanSeesAHashEqualsCall(): void
    {
        $calls = self::collectHashEqualsCalls(
            "<?php\nreturn hash_equals(\$a, \$b);\n",
            'synthetic/Planted.php'
        );

        self::assertSame(['hash_equals($a, $b)'], $calls['builtin']);
        self::assertSame([], $calls['other']);
    }

    public function testRosterScanSkipsImportsAndConvictsLookalikes(): void
    {
        $calls = self::collectHashEqualsCalls(
            "<?php\n"
            . "use function hash_equals;\n"
            . "return hash_equals(\$a, \$b);\n",
            'synthetic/Imported.php'
        );

        self::assertSame(
            ['hash_equals($a, $b)'],
            $calls['builtin'],
            'The import line must not count as a call site.'
        );

        $calls = self::collectHashEqualsCalls(
            "<?php\n"
            . "return \$signer->hash_equals(\$a, \$b);\n",
            'synthetic/Method.php'
        );

        self::assertSame([], $calls['builtin']);
        self::assertSame(
            ['synthetic/Method.php: $signer->hash_equals'],
            $calls['other'],
            'A method-form lookalike is convicted, never counted as built-in.'
        );
    }

    public function testBanScannerConvictsPlantedSecretCompares(): void
    {
        self::assertSame(
            ['synthetic/Planted.php:2: `===` compares secret-named operand `$signature`'],
            self::scanBanViolations(
                "<?php\nif (\$expected === \$signature) {\n    return false;\n}\n",
                'synthetic/Planted.php'
            ),
            'The ban must convict the `===` mutation the finding describes.'
        );

        self::assertSame(
            ['synthetic/BothSecrets.php:2: `===` compares secret-named operand `$token`'],
            self::scanBanViolations(
                "<?php\nif (\$token === \$digest) {\n    return false;\n}\n",
                'synthetic/BothSecrets.php'
            ),
            'Value-to-value compares of secret-named operands are banned too.'
        );
    }

    public function testBanScannerDeclinesNeutralArms(): void
    {
        $allowed = [
            "<?php\nif (\$key === 'alg') {\n}\n",
            "<?php\nif (strlen(\$verifier) === 43) {\n}\n",
            "<?php\nif (preg_match('/./', \$challenge) === 1) {\n}\n",
            "<?php\nif (\$secret === null) {\n}\n",
            "<?php\nif (\$secret === '') {\n}\n",
            "<?php\nif (\$secret === false) {\n}\n",
            "<?php\nif (\$signature !== self::EXPECTED_PREFIX) {\n}\n",
        ];

        foreach ($allowed as $index => $source) {
            self::assertSame(
                [],
                self::scanBanViolations($source, 'synthetic/Neutral' . $index . '.php'),
                'Neutral arm ' . $index . ' must not be convicted.'
            );
        }
    }

    public function testBanScannerConvictsBareSecretOnEitherSide(): void
    {
        self::assertSame(
            ['synthetic/Planted2.php:2: `!==` compares secret-named operand `$mac`'],
            self::scanBanViolations(
                "<?php\nif (strlen(\$a) !== \$mac) {\n}\n",
                'synthetic/Planted2.php'
            ),
            'A bare secret on the right of an equality operator is convicted '
            . 'just as on the left.'
        );

        self::assertSame(
            ['synthetic/Planted3.php:2: `==` compares secret-named operand `$password`'],
            self::scanBanViolations(
                "<?php\nif (\$password == 0) {\n}\n",
                'synthetic/Planted3.php'
            ),
            'A loose compare against a number is a type-juggling hazard and '
            . 'is convicted even though the arm is neutral.'
        );
    }

    /**
     * Sort the roster by file and each file's call sites, so the multiset
     * comparison is order-independent.
     *
     * @param  array<string, list<string>> $roster
     * @return array<string, list<string>>
     */
    private static function normalizeRoster(array $roster): array
    {
        foreach ($roster as $rel => $sites) {
            sort($sites);
            $roster[$rel] = $sites;
        }

        ksort($roster);

        return $roster;
    }

    /**
     * Relative paths of every `*.php` file under `src/`, sorted.
     *
     * @return list<string>
     */
    private static function allPhpFileRels(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $rels = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->isFile() && $file->getExtension() === 'php') {
                $rels[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        if ($rels === []) {
            throw new RuntimeException(
                'Constant-time comparison census: no PHP sources under ' . $root
                . ' — refusing a vacuous green.'
            );
        }

        sort($rels);

        return $rels;
    }

    private static function readSrcFile(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/src/' . $rel;

        if (!is_file($path)) {
            throw new RuntimeException('Census source file is missing: ' . $path);
        }

        $source = file_get_contents($path);

        if (!is_string($source)) {
            throw new RuntimeException('Census source file is unreadable: ' . $path);
        }

        return $source;
    }

    /**
     * Built-in and non-built-in `hash_equals` call expressions in one file.
     *
     * Built-in texts are the full call, whitespace runs collapsed to single
     * spaces: `hash_equals($expected, $signature)`. `use function` imports
     * are skipped; method, static, qualified, and namespaced forms land in
     * `other` so a planted lookalike is convicted rather than counted.
     *
     * @return array{builtin: list<string>, other: list<string>}
     */
    private static function collectHashEqualsCalls(string $source, string $label): array
    {
        $tokens = token_get_all($source);
        $builtin = [];
        $other = [];

        foreach ($tokens as $index => $token) {
            if (
                !is_array($token)
                || $token[0] !== T_STRING
                || $token[1] !== 'hash_equals'
            ) {
                continue;
            }

            $previous = self::previousSignificant($tokens, $index);

            if (
                is_array($previous)
                && in_array($previous[0], [T_USE, T_FUNCTION], true)
            ) {
                continue;
            }

            $openIndex = self::nextSignificantIndex($tokens, $index);

            if ($openIndex < 0 || self::tokenText($tokens[$openIndex]) !== '(') {
                continue;
            }

            $resolved = self::resolveCallName($tokens, $index);

            if ($resolved === null) {
                $closeIndex = self::matchingCloseIndex($tokens, $openIndex);

                if ($closeIndex === -1) {
                    throw new RuntimeException(
                        'Unbalanced parentheses in ' . $label . ' near line ' . $token[2]
                    );
                }

                $builtin[] = self::collapseCall($tokens, $index, $closeIndex);
            } else {
                $other[] = $label . ': ' . $resolved;
            }
        }

        return ['builtin' => $builtin, 'other' => $other];
    }

    /**
     * Plaintext-compare ban violations in one source string.
     *
     * @return list<string>
     */
    private static function scanBanViolations(string $source, string $label): array
    {
        $tokens = token_get_all($source);
        $violations = [];

        foreach ($tokens as $index => $token) {
            if (
                !is_array($token)
                || !in_array($token[0], self::EQUALITY_TOKENS, true)
            ) {
                continue;
            }

            $left = self::previousSignificant($tokens, $index);
            $right = self::nextSignificant($tokens, $index);

            $leftBare = self::isBareSecretVariable($left);
            $rightBare = self::isBareSecretVariable($right);

            if (!$leftBare && !$rightBare) {
                continue;
            }

            if (in_array($token[0], [T_IS_EQUAL, T_IS_NOT_EQUAL], true)) {
                $secret = $leftBare ? self::tokenText($left) : self::tokenText($right);

                $violations[] = $label . ':' . $token[2] . ': `' . $token[1]
                    . '` compares secret-named operand `' . $secret . '`';

                continue;
            }

            if (
                ($leftBare && !self::isNeutralArm($right))
                || ($rightBare && !self::isNeutralArm($left))
            ) {
                $secret = $leftBare ? self::tokenText($left) : self::tokenText($right);

                $violations[] = $label . ':' . $token[2] . ': `' . $token[1]
                    . '` compares secret-named operand `' . $secret . '`';
            }
        }

        return $violations;
    }

    /**
     * Expression text of a non-built-in `hash_equals` name, or null if the
     * token resolves to the global built-in.
     *
     * @param  array<int, array{0:int, 1:string, 2:int}|string> $tokens
     */
    private static function resolveCallName(array $tokens, int $index): ?string
    {
        $previous = self::previousSignificant($tokens, $index);

        if (!is_array($previous)) {
            return null;
        }

        if (
            in_array(
                $previous[0],
                [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON],
                true
            )
        ) {
            $operatorIndex = self::indexOfSignificant($tokens, $index, -1);
            $owner = self::tokenText(self::previousSignificant($tokens, $operatorIndex));
            $arrow = $previous[0] === T_DOUBLE_COLON
                ? '::'
                : ($previous[0] === T_NULLSAFE_OBJECT_OPERATOR ? '?->' : '->');

            return $owner . $arrow . 'hash_equals';
        }

        if ($previous[0] === T_NS_SEPARATOR) {
            return self::collectQualifiedName($tokens, $index);
        }

        return null;
    }

    /**
     * Full qualified name (`Phlix\hash_equals` or `\hash_equals`) ending at
     * the given token index.
     *
     * @param  array<int, array{0:int, 1:string, 2:int}|string> $tokens
     */
    private static function collectQualifiedName(array $tokens, int $index): string
    {
        $parts = ['hash_equals'];
        $cursor = $index;

        while (true) {
            $separator = self::indexOfSignificant($tokens, $cursor, -1);

            if ($separator === -1) {
                break;
            }

            $token = $tokens[$separator];

            if (!is_array($token) || $token[0] !== T_NS_SEPARATOR) {
                break;
            }

            array_unshift($parts, '\\');

            $before = self::indexOfSignificant($tokens, $separator, -1);

            if ($before === -1) {
                break;
            }

            $token = $tokens[$before];

            if (!is_array($token) || $token[0] !== T_STRING) {
                break;
            }

            array_unshift($parts, $token[1]);
            $cursor = $before;
        }

        return implode('', $parts);
    }

    /**
     * The normalized call text from the name token through its close paren:
     * whitespace runs collapse to a single space, spaces at the inner edges
     * of the parentheses and before commas are dropped, so the fingerprint
     * is stable across formatting.
     *
     * @param  array<int, array{0:int, 1:string, 2:int}|string> $tokens
     */
    private static function collapseCall(array $tokens, int $from, int $to): string
    {
        $text = '';

        for ($i = $from; $i <= $to; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                $text .= ' ';

                continue;
            }

            $text .= is_array($token) ? $token[1] : $token;
        }

        $collapsed = trim((string) preg_replace('/ {2,}/', ' ', $text));

        return str_replace(['( ', ' )', ' ,'], ['(', ')', ','], $collapsed);
    }

    /**
     * Index of the `)` matching the `(` found at `$openIndex`, or -1.
     *
     * @param  array<int, array{0:int, 1:string, 2:int}|string> $tokens
     */
    private static function matchingCloseIndex(array $tokens, int $openIndex): int
    {
        $depth = 0;
        $count = count($tokens);

        for ($i = $openIndex; $i < $count; $i++) {
            $text = self::tokenText($tokens[$i]);

            if ($text === '(') {
                $depth++;
            } elseif ($text === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return -1;
    }

    /**
     * The nearest significant token before `$index`.
     *
     * @param  array<int, array{0:int, 1:string, 2:int}|string> $tokens
     * @return array{0:int, 1:string, 2:int}|string|null
     */
    private static function previousSignificant(array $tokens, int $index): array|string|null
    {
        $found = self::indexOfSignificant($tokens, $index, -1);

        return $found === -1 ? null : $tokens[$found];
    }

    /**
     * @param  array<int, array{0:int, 1:string, 2:int}|string> $tokens
     * @return array{0:int, 1:string, 2:int}|string|null
     */
    private static function nextSignificant(array $tokens, int $index): array|string|null
    {
        $found = self::indexOfSignificant($tokens, $index, 1);

        return $found === -1 ? null : $tokens[$found];
    }

    /**
     * @param  array<int, array{0:int, 1:string, 2:int}|string> $tokens
     */
    private static function nextSignificantIndex(array $tokens, int $index): int
    {
        return self::indexOfSignificant($tokens, $index, 1);
    }

    /**
     * Walk from `$index` in direction `$step` to the next significant token.
     *
     * @param  array<int, array{0:int, 1:string, 2:int}|string> $tokens
     */
    private static function indexOfSignificant(array $tokens, int $index, int $step): int
    {
        $count = count($tokens);
        $cursor = $index + $step;

        while ($cursor >= 0 && $cursor < $count) {
            if (self::isSignificant($tokens[$cursor])) {
                return $cursor;
            }

            $cursor += $step;
        }

        return -1;
    }

    /**
     * @param  array{0:int, 1:string, 2:int}|string $token
     */
    private static function isSignificant(array|string $token): bool
    {
        if (!is_array($token)) {
            return trim($token) !== '';
        }

        return !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * @param  array{0:int, 1:string, 2:int}|string|null $token
     */
    private static function isBareSecretVariable(array|string|null $token): bool
    {
        if (!is_array($token) || $token[0] !== T_VARIABLE) {
            return false;
        }

        $name = strtolower(substr($token[1], 1));

        foreach (self::SECRET_NAME_WORDS as $word) {
            if (str_contains($name, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{0:int, 1:string, 2:int}|string|null $token
     */
    private static function isNeutralArm(array|string|null $token): bool
    {
        if ($token === null) {
            return false;
        }

        if (!is_array($token)) {
            return $token === '\\';
        }

        if ($token[0] === T_NS_SEPARATOR) {
            return true;
        }

        if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
            return strlen(trim($token[1], "'\"")) === 0;
        }

        if (in_array($token[0], [T_LNUMBER, T_DNUMBER], true)) {
            return true;
        }

        return $token[0] === T_STRING
            && in_array(strtolower($token[1]), self::GUARD_FUNCTIONS, true);
    }

    /**
     * @param  array{0:int, 1:string, 2:int}|string|null $token
     */
    private static function tokenText(array|string|null $token): string
    {
        if ($token === null) {
            return '?';
        }

        return is_array($token) ? $token[1] : $token;
    }
}
