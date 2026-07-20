<?php

/**
 * Shared assertions for the settings-schema property shape.
 *
 * Both `server-settings.schema.json` and `hub-settings.schema.json` use the
 * same extended property vocabulary (`label`, `helpText`, `helpLinks`, `tier`,
 * `secret`, `restart`, `enumLabels`, `optionHelp`) described in the settings
 * plan. These assertions encode the invariants that vocabulary must satisfy so
 * a schema edit cannot silently ship a property the admin UI renders wrongly.
 *
 * Deliberately NOT asserted here: whether a key resolves to a non-null default
 * in phlix-server's / phlix-hub's `config/` tree. That is a cross-repo
 * assertion and belongs in the consuming repository, which owns those files.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Schema;

/**
 * @psalm-require-extends \PHPUnit\Framework\TestCase
 */
trait SettingsSchemaAssertions
{
    /**
     * The `tier` values the Standard/Advanced UI toggle understands.
     *
     * @var list<string>
     */
    private const VALID_TIERS = ['standard', 'advanced'];

    /**
     * Assert a property declares a non-empty `tier` drawn from the known set.
     *
     * `tier` drives the Standard/Advanced gating in the admin SPA. A property
     * that omits it is silently treated as "standard", which is how advanced
     * knobs leak into the basic form — so it is REQUIRED, not optional.
     *
     * @param string               $key      Dotted setting key (for messages).
     * @param array<string, mixed> $property Decoded property definition.
     *
     * @return void
     */
    private function assertPropertyDeclaresTier(string $key, array $property): void
    {
        $this->assertArrayHasKey(
            'tier',
            $property,
            sprintf('Property "%s" must declare a "tier" (the Advanced-mode gate defaults to "standard" without it).', $key)
        );

        $tier = $property['tier'];

        $this->assertIsString($tier, sprintf('Property "%s" tier must be a string.', $key));
        $this->assertNotSame('', $tier, sprintf('Property "%s" tier must be non-empty.', $key));
        $this->assertContains(
            $tier,
            self::VALID_TIERS,
            sprintf('Property "%s" tier must be one of: %s.', $key, implode(', ', self::VALID_TIERS))
        );
    }

    /**
     * Assert a property's `default` is type-consistent with its `type`.
     *
     * A `default` that cannot satisfy its own declared type cannot be written
     * back through the admin PUT endpoint, which validates the submitted value
     * against the same type — so the documented default becomes unrestorable
     * once an admin changes the value.
     *
     * @param string               $key      Dotted setting key (for messages).
     * @param array<string, mixed> $property Decoded property definition.
     *
     * @return void
     */
    private function assertDefaultMatchesDeclaredType(string $key, array $property): void
    {
        $this->assertArrayHasKey('type', $property, sprintf('Property "%s" must declare a "type".', $key));
        $this->assertArrayHasKey('default', $property, sprintf('Property "%s" must declare a "default".', $key));

        $type = $property['type'];
        $this->assertIsString(
            $type,
            sprintf(
                'Property "%s" type must be a single JSON-Schema type string; a type union is dropped by the consumers that build the writable allow-list.',
                $key
            )
        );

        $default = $property['default'];
        $message = sprintf('Property "%s" default must be type-consistent with its declared type "%s".', $key, $type);

        switch ($type) {
            case 'boolean':
                $this->assertIsBool($default, $message);
                break;
            case 'integer':
                $this->assertIsInt($default, $message);
                break;
            case 'number':
                $this->assertTrue(is_int($default) || is_float($default), $message);
                break;
            case 'string':
                $this->assertIsString($default, $message);
                break;
            case 'array':
                $this->assertIsArray($default, $message);
                $this->assertSame(array_values($default), $default, sprintf('Property "%s" array default must be a JSON list.', $key));
                break;
            case 'object':
                $this->assertIsArray($default, $message);
                break;
            default:
                $this->fail(sprintf('Property "%s" declares unsupported type "%s".', $key, $type));
        }
    }

    /**
     * Assert a property's `default` sits inside any declared numeric bounds.
     *
     * @param string               $key      Dotted setting key (for messages).
     * @param array<string, mixed> $property Decoded property definition.
     *
     * @return void
     */
    private function assertDefaultIsWithinBounds(string $key, array $property): void
    {
        if (!array_key_exists('default', $property)) {
            return;
        }

        $default = $property['default'];
        if (!is_int($default) && !is_float($default)) {
            return;
        }

        if (array_key_exists('minimum', $property)) {
            $minimum = $property['minimum'];
            $this->assertTrue(is_int($minimum) || is_float($minimum), sprintf('Property "%s" minimum must be numeric.', $key));
            $this->assertGreaterThanOrEqual(
                $minimum,
                $default,
                sprintf('Property "%s" default (%s) must not be below its own minimum (%s).', $key, (string) $default, (string) $minimum)
            );
        }

        if (array_key_exists('maximum', $property)) {
            $maximum = $property['maximum'];
            $this->assertTrue(is_int($maximum) || is_float($maximum), sprintf('Property "%s" maximum must be numeric.', $key));
            $this->assertLessThanOrEqual(
                $maximum,
                $default,
                sprintf('Property "%s" default (%s) must not exceed its own maximum (%s).', $key, (string) $default, (string) $maximum)
            );
        }

        if (array_key_exists('minimum', $property) && array_key_exists('maximum', $property)) {
            $this->assertLessThanOrEqual(
                $property['maximum'],
                $property['minimum'],
                sprintf('Property "%s" minimum must not exceed its maximum.', $key)
            );
        }
    }

    /**
     * Assert enum properties carry complete `enumLabels` and `optionHelp` maps.
     *
     * The plan requires per-value help on every enum option: an option with no
     * label renders as a raw token, and an option with no help is undocumented.
     * Coverage must be EXACT in both directions — a stale entry for a removed
     * option is as much a defect as a missing one.
     *
     * @param string               $key      Dotted setting key (for messages).
     * @param array<string, mixed> $property Decoded property definition.
     *
     * @return void
     */
    private function assertEnumOptionsAreFullyDocumented(string $key, array $property): void
    {
        if (!array_key_exists('enum', $property)) {
            return;
        }

        $enum = $property['enum'];
        $this->assertIsArray($enum, sprintf('Property "%s" enum must be an array.', $key));
        $this->assertNotEmpty($enum, sprintf('Property "%s" enum must not be empty.', $key));

        $members = [];
        foreach ($enum as $index => $member) {
            $this->assertIsString(
                $member,
                sprintf(
                    'Property "%s" enum[%s] must be a string; a non-string member (such as null) cannot be keyed in enumLabels/optionHelp and cannot satisfy the declared type.',
                    $key,
                    (string) $index
                )
            );
            $members[] = $member;
        }

        $this->assertSame(
            $members,
            array_values(array_unique($members)),
            sprintf('Property "%s" enum members must be distinct.', $key)
        );

        $this->assertContains(
            $property['default'] ?? null,
            $members,
            sprintf('Property "%s" default must be one of its own enum members.', $key)
        );

        foreach (['enumLabels', 'optionHelp'] as $mapName) {
            $this->assertArrayHasKey(
                $mapName,
                $property,
                sprintf('Property "%s" has an enum and must therefore declare "%s".', $key, $mapName)
            );

            $map = $property[$mapName];
            $this->assertIsArray($map, sprintf('Property "%s" %s must be a JSON object.', $key, $mapName));

            $mapKeys = array_map(static fn (int|string $mapKey): string => (string) $mapKey, array_keys($map));

            sort($members);
            sort($mapKeys);

            $this->assertSame(
                $members,
                $mapKeys,
                sprintf('Property "%s" %s must cover exactly its enum members (no missing and no stale entries).', $key, $mapName)
            );

            foreach ($map as $mapKey => $text) {
                $this->assertIsString($text, sprintf('Property "%s" %s["%s"] must be a string.', $key, $mapName, (string) $mapKey));
                $this->assertNotSame('', $text, sprintf('Property "%s" %s["%s"] must be non-empty.', $key, $mapName, (string) $mapKey));
            }
        }
    }

    /**
     * Assert `helpLinks` entries are structurally well formed.
     *
     * Shape only — this deliberately performs no network I/O so the unit suite
     * stays offline and deterministic. Liveness is covered by the separate
     * `network`-grouped test, which is excluded from the default run.
     *
     * @param string               $key      Dotted setting key (for messages).
     * @param array<string, mixed> $property Decoded property definition.
     *
     * @return void
     */
    private function assertHelpLinksAreWellFormed(string $key, array $property): void
    {
        if (!array_key_exists('helpLinks', $property)) {
            return;
        }

        $links = $property['helpLinks'];
        $this->assertIsArray($links, sprintf('Property "%s" helpLinks must be an array.', $key));
        $this->assertSame(array_values($links), $links, sprintf('Property "%s" helpLinks must be a JSON list.', $key));
        $this->assertNotEmpty($links, sprintf('Property "%s" helpLinks must be omitted rather than declared empty.', $key));

        $seen = [];
        foreach ($links as $index => $link) {
            $label = sprintf('Property "%s" helpLinks[%s]', $key, (string) $index);

            $this->assertIsArray($link, $label . ' must be an object.');
            $this->assertArrayHasKey('text', $link, $label . ' must declare "text".');
            $this->assertArrayHasKey('url', $link, $label . ' must declare "url".');

            $this->assertIsString($link['text'], $label . '.text must be a string.');
            $this->assertNotSame('', $link['text'], $label . '.text must be non-empty.');

            $url = $link['url'];
            $this->assertIsString($url, $label . '.url must be a string.');
            $this->assertStringStartsWith('https://', $url, $label . '.url must be https.');
            $this->assertNotFalse(
                filter_var($url, FILTER_VALIDATE_URL),
                $label . '.url must be a syntactically valid URL.'
            );
            $this->assertStringNotContainsString(' ', $url, $label . '.url must not contain spaces.');

            $this->assertNotContains($url, $seen, $label . '.url is duplicated within the same property.');
            $seen[] = $url;
        }
    }

    /**
     * Assert the boolean-ish extended keywords are real booleans when present.
     *
     * The consumers read these with `!empty()`, so a string `"false"` would be
     * read as true — hence the strict type assertion.
     *
     * @param string               $key      Dotted setting key (for messages).
     * @param array<string, mixed> $property Decoded property definition.
     *
     * @return void
     */
    private function assertFlagKeywordsAreBooleans(string $key, array $property): void
    {
        foreach (['secret', 'restart'] as $flag) {
            if (array_key_exists($flag, $property)) {
                $this->assertIsBool(
                    $property[$flag],
                    sprintf('Property "%s" "%s" must be a real boolean.', $key, $flag)
                );
            }
        }
    }

    /**
     * Collect every `helpLinks[].url` declared anywhere in a schema document.
     *
     * @param array<string, array<string, mixed>> $properties Decoded properties map.
     *
     * @return list<string> Distinct URLs, in first-seen order.
     */
    private static function collectHelpLinkUrls(array $properties): array
    {
        $urls = [];
        foreach ($properties as $property) {
            if (!isset($property['helpLinks']) || !is_array($property['helpLinks'])) {
                continue;
            }
            foreach ($property['helpLinks'] as $link) {
                if (is_array($link) && isset($link['url']) && is_string($link['url'])) {
                    $urls[$link['url']] = true;
                }
            }
        }

        return array_keys($urls);
    }
}
