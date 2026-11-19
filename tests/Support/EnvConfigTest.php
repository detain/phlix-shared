<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Support;

use Phlix\Shared\Support\EnvConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * EnvConfig Laws - the "0" Survivor, the Trim, and the Junk Verdict
 *
 * The trap this class exists to kill (M-SERVER-34): `getenv('X') ?: $default`
 * swallows the explicit string `"0"`. Every reader here distinguishes
 * unset/empty from an explicit zero, and the bool family carries E-04's
 * single trim-on-read grammar across both hosts.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 * @package   Phlix\Shared\Tests\Support
 * @since     0.56.0
 */
#[CoversClass(EnvConfig::class)]
final class EnvConfigTest extends TestCase
{
    private const string KNOB = 'PHLIX_SHARED_ENVCONFIG_TEST_KNOB';

    protected function tearDown(): void
    {
        putenv(self::KNOB);
    }

    public function test_integer_survives_an_explicit_zero(): void
    {
        putenv(self::KNOB . '=0');

        self::assertSame(0, EnvConfig::integer(self::KNOB, 7));
    }

    public function test_integer_uses_the_default_for_unset_and_empty(): void
    {
        self::assertSame(7, EnvConfig::integer(self::KNOB, 7));

        // The default is a runtime value here on purpose: the analyzer can
        // (and should) prove the literal cases equal, and PHPUnit's
        // alreadyNarrowedType guard reddens assertSame on provably-identical
        // literals - the empty-knob leg must still OBSERVE the chosen default.
        $default = mt_rand(2, 999999);

        putenv(self::KNOB . '=');
        self::assertSame($default, EnvConfig::integer(self::KNOB, $default));
    }

    public function test_integer_casts_lead_junk_the_way_getenv_casts_it(): void
    {
        // The (int) law preserved from every old site: leading numeric wins,
        // trailing junk is dropped ("12x" -> 12). Disclosed divergence from
        // the hub's is_numeric guards (DESIGN M-12 / section 6).
        putenv(self::KNOB . '=12x');
        self::assertSame(12, EnvConfig::integer(self::KNOB, 7));

        putenv(self::KNOB . '=-5');
        self::assertSame(-5, EnvConfig::integer(self::KNOB, 7));
    }

    public function test_text_returns_the_raw_value_including_zero(): void
    {
        putenv(self::KNOB . '=0');
        self::assertSame('0', EnvConfig::text(self::KNOB, 'fallback'));

        putenv(self::KNOB . '= spaces kept ');
        self::assertSame(' spaces kept ', EnvConfig::text(self::KNOB, 'fallback'));
    }

    public function test_text_defaults_on_unset_and_empty(): void
    {
        self::assertSame('fallback', EnvConfig::text(self::KNOB, 'fallback'));

        $default = 'fb-' . mt_rand(1000, 9999);

        putenv(self::KNOB . '=');
        self::assertSame($default, EnvConfig::text(self::KNOB, $default));
    }

    public function test_nullable_text_answers_null_for_unset_and_empty_but_keeps_zero(): void
    {
        self::assertNull(EnvConfig::nullableText(self::KNOB));

        putenv(self::KNOB . '=');
        self::assertNull(EnvConfig::nullableText(self::KNOB));

        putenv(self::KNOB . '=0');
        self::assertSame('0', EnvConfig::nullableText(self::KNOB));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function trueWords(): array
    {
        return [
            'one' => ['1', true],
            'true' => ['true', true],
            'yes' => ['yes', true],
            'on' => ['on', true],
            'True mixed case' => ['True', true],
            'YES shouting' => ['YES', true],
            'padded true' => [' true', true],
            'trailing-newline-ish padded' => ["on\n", true],
        ];
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function falseWords(): array
    {
        return [
            'zero' => ['0', false],
            'false' => ['false', false],
            'no' => ['no', false],
            'off' => ['off', false],
            'FALSE mixed case' => ['FALSE', false],
            ' padded false' => [' false', false],
        ];
    }

    #[DataProvider('trueWords')]
    public function test_bool_reads_the_true_grammar(string $raw, bool $expected): void
    {
        putenv(self::KNOB . '=' . $raw);

        self::assertSame($expected, EnvConfig::bool(self::KNOB, false));
        // Same grammar under the opt-out verb: the reading, not the default, decides.
        self::assertSame($expected, EnvConfig::boolOptOut(self::KNOB, true));
    }

    #[DataProvider('falseWords')]
    public function test_bool_reads_the_false_grammar(string $raw, bool $expected): void
    {
        putenv(self::KNOB . '=' . $raw);

        self::assertSame($expected, EnvConfig::bool(self::KNOB, true));
        self::assertSame($expected, EnvConfig::boolOptOut(self::KNOB, false));
    }

    public function test_the_trim_is_load_bearing_across_both_words(): void
    {
        // E-04 drift #6, pinned: the old hand-rolled copies disagreed on
        // trim, so ' false' answered per-site. One law now, per knob.
        putenv(self::KNOB . '= true');
        self::assertTrue(EnvConfig::bool(self::KNOB, false));

        putenv(self::KNOB . '= false');
        self::assertFalse(EnvConfig::boolOptOut(self::KNOB, true));
    }

    public function test_bool_junk_answers_the_default_silently(): void
    {
        // Charter divergence from the server copy: same verdict (the
        // default), no error_log warning - logging side-effects are
        // forbidden in this package (AGENTS.md zero-I/O rule).
        putenv(self::KNOB . '=banana');
        self::assertFalse(EnvConfig::bool(self::KNOB, false));
        self::assertTrue(EnvConfig::bool(self::KNOB, true));
        self::assertTrue(EnvConfig::boolOptOut(self::KNOB, true));
    }

    public function test_bool_defaults_on_unset_and_empty(): void
    {
        self::assertFalse(EnvConfig::bool(self::KNOB, false));
        self::assertTrue(EnvConfig::bool(self::KNOB, true));

        putenv(self::KNOB . '=');
        self::assertTrue(EnvConfig::bool(self::KNOB, true));
    }

    public function test_bool_opt_out_verb_reads_on_by_default(): void
    {
        self::assertTrue(EnvConfig::boolOptOut(self::KNOB));

        putenv(self::KNOB . '=off');
        self::assertFalse(EnvConfig::boolOptOut(self::KNOB));
    }

    public function test_bool_value_pure_seam_maps_false_and_null_to_unset(): void
    {
        self::assertTrue(EnvConfig::boolValue(false, true, self::KNOB));
        self::assertTrue(EnvConfig::boolValue(null, true, self::KNOB));
        self::assertFalse(EnvConfig::boolValue(false, false, self::KNOB));

        self::assertTrue(EnvConfig::boolValue('yes', false, self::KNOB));
        self::assertFalse(EnvConfig::boolValue('NO', true, self::KNOB));
        self::assertTrue(EnvConfig::boolValue('garbage', true, self::KNOB));
        self::assertFalse(EnvConfig::boolValue('garbage', false, self::KNOB));
        self::assertTrue(EnvConfig::boolValue('', true, self::KNOB));
    }

    public function test_readers_never_throw_on_hostile_values(): void
    {
        // chr(255) junk keeps the value a RUNTIME string (the analyzer must
        // not constant-fold the fixture) and is the cast the old sites made:
        // (int) of a non-numeric string is 0. No NUL: env values stop at it.
        putenv(self::KNOB . '=' . chr(255) . chr(255) . chr(255));

        self::assertSame(0, EnvConfig::integer(self::KNOB, 3));
        self::assertTrue(EnvConfig::bool(self::KNOB, true));
    }
}
