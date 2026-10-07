<?php

/**
 * Hub Settings Schema Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Schema;

use Phlix\Shared\Schema\SchemaPaths;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HubSettingsSchemaTest extends TestCase
{
    use SettingsSchemaAssertions;

    /**
     * Decoded hub-settings schema document.
     *
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $raw = (string) file_get_contents(SchemaPaths::hubSettings());
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'hub-settings.schema.json must decode to a JSON object.');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The decoded `properties` map of the schema.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function properties(): array
    {
        $schema = self::schema();
        self::assertArrayHasKey('properties', $schema);
        self::assertIsArray($schema['properties']);

        $properties = [];
        foreach ($schema['properties'] as $key => $value) {
            self::assertIsString($key);
            self::assertIsArray($value, sprintf('Property "%s" must be a JSON object.', $key));
            /** @var array<string, mixed> $value */
            $properties[$key] = $value;
        }

        return $properties;
    }

    /**
     * The expected property keys mapped to their JSON-Schema type.
     *
     * This list must stay in lockstep with
     * `Phlix\Hub\Hub\HubSettingsRepository::ALLOWED_KEYS`, which is what the
     * hub settings controller enumerates: the schema supplies only the render
     * metadata for those keys, so a schema key with no allow-list entry is
     * never rendered, and an allow-list key with no schema entry renders with
     * no label or help at all.
     *
     * The key IS the dotted config path — `auth.access_ttl` resolves
     * `phlix-hub/config/auth.php`'s `access_ttl`. The `*_token_ttl` spellings
     * this schema previously carried match no config path.
     *
     * Frozen list — drift is caught by
     * {@see self::test_properties_match_hub_allow_list_when_hub_checkout_available()},
     * which parses the live `ALLOWED_KEYS` constant out of a sibling (or
     * `PHLIX_HUB_REPO`-pointed) phlix-hub checkout. Where no hub checkout is
     * on disk, keep this list in lockstep with
     * `phlix-hub/src/Hub/HubSettingsRepository.php::ALLOWED_KEYS` by hand.
     *
     * Rotated 2026-10-07 (v0.52.0): the fourteen W5 Phase-6 keys upstreamed
     * from the hub's retired SUPPLEMENTAL_META bridge (4 → 18). The hub's
     * `json` type vocabulary maps onto the schema's `object` type — the
     * mapping was extended for it in {@see self::jsonSchemaTypeForHubType()}
     * at the same time.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function propertyProvider(): array
    {
        return [
            // config/server.php
            'server.enrollment_ttl' => ['server.enrollment_ttl', 'integer'],
            // config/auth.php — NOT `access_token_ttl` / `refresh_token_ttl`
            'auth.access_ttl' => ['auth.access_ttl', 'integer'],
            'auth.refresh_ttl' => ['auth.refresh_ttl', 'integer'],
            'auth.signups_disabled' => ['auth.signups_disabled', 'boolean'],
            // ---- W5 Phase-6 settings program (upstreamed 2026-10-07) ------
            // config/hub.php
            'hub.maintenance_mode' => ['hub.maintenance_mode', 'boolean'],
            // config/federation.php
            'federation.enabled' => ['federation.enabled', 'boolean'],
            // config/requests.php
            'requests.auto_approve' => ['requests.auto_approve', 'boolean'],
            // config/invite.php
            'invite.default_expiry_seconds' => ['invite.default_expiry_seconds', 'integer'],
            // config/server.php (quota / metrics / relay / rate-limit / arr)
            'server.max_servers_per_user' => ['server.max_servers_per_user', 'integer'],
            'server.max_users_per_server' => ['server.max_users_per_server', 'integer'],
            'server.metrics.enabled' => ['server.metrics.enabled', 'boolean'],
            'server.metrics.retention_days' => ['server.metrics.retention_days', 'integer'],
            'server.relay.reconnect_drain_grace_seconds' => ['server.relay.reconnect_drain_grace_seconds', 'number'],
            'server.rate_limit' => ['server.rate_limit', 'object'],
            'server.arr.sonarr.enabled' => ['server.arr.sonarr.enabled', 'boolean'],
            'server.arr.sonarr.url' => ['server.arr.sonarr.url', 'string'],
            'server.arr.radarr.enabled' => ['server.arr.radarr.enabled', 'boolean'],
            'server.arr.radarr.url' => ['server.arr.radarr.url', 'string'],
        ];
    }

    /**
     * Config paths the settings plan forbids the hub from ever exposing.
     *
     * Mirrors `HubSettingsRepository::DENIED_KEYS`: secrets, relay TLS
     * material, the hub domain values baked into already-issued enrollment
     * JWTs, ACME/TLS provisioning toggles, and listen/worker infrastructure.
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEYS = [
        'auth.secret',
        'server.hub_base_url',
        'server.public_domain',
        'server.domain',
        'server.tls_enabled',
        'server.subdomain_auto_claim',
        'server.relay_tls_cert',
        'server.relay_tls_key',
        'server.host',
        'server.port',
        'server.workers',
        'server.arr.sonarr.api_key',
        'server.arr.radarr.api_key',
    ];

    /**
     * Numeric constraints (minimum/maximum) the constrained keys must carry.
     *
     * @return array<string, array{0: string, 1: array<string, int|float>}>
     */
    public static function constraintProvider(): array
    {
        return [
            'server.enrollment_ttl' => ['server.enrollment_ttl', ['minimum' => 60, 'maximum' => 2592000]],
            'auth.access_ttl' => ['auth.access_ttl', ['minimum' => 300, 'maximum' => 86400]],
            'auth.refresh_ttl' => ['auth.refresh_ttl', ['minimum' => 3600, 'maximum' => 2592000]],
            // W5 Phase-6 keys (upstreamed 2026-10-07): bounds are a contract —
            // the hub's PUT law validates against these merged-meta values.
            'invite.default_expiry_seconds' => ['invite.default_expiry_seconds', ['minimum' => 0, 'maximum' => 31536000]],
            'server.max_servers_per_user' => ['server.max_servers_per_user', ['minimum' => 0, 'maximum' => 1000]],
            'server.max_users_per_server' => ['server.max_users_per_server', ['minimum' => 0, 'maximum' => 10000]],
            'server.metrics.retention_days' => ['server.metrics.retention_days', ['minimum' => 1, 'maximum' => 3650]],
            'server.relay.reconnect_drain_grace_seconds' => ['server.relay.reconnect_drain_grace_seconds', ['minimum' => 0, 'maximum' => 300]],
        ];
    }

    public function test_schema_declares_the_expected_meta_header(): void
    {
        $schema = self::schema();
        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema'] ?? null);
        $this->assertSame('https://phlix.tv/schemas/hub-settings.schema.json', $schema['$id'] ?? null);
        $this->assertSame('object', $schema['type'] ?? null);
        $this->assertFalse($schema['additionalProperties'] ?? null);

        $description = $schema['description'] ?? null;
        $this->assertIsString($description);
        $this->assertNotSame('', $description);
        // Regression guard for a copy-pasted description that described the
        // SERVER's responsibilities (library scanning) and had a missing space.
        $this->assertStringNotContainsString('handlesarr', $description);
        $this->assertStringNotContainsString('library scanning, and other background tasks', $description);
    }

    public function test_schema_properties_is_an_object(): void
    {
        $schema = self::schema();
        $this->assertArrayHasKey('properties', $schema);
        $this->assertIsArray($schema['properties'], 'properties must be a JSON object.');
    }

    public function test_schema_has_exactly_the_expected_property_keys(): void
    {
        $actual = array_keys(self::properties());
        $expected = array_map(
            static fn (array $row): string => $row[0],
            array_values(self::propertyProvider())
        );

        sort($actual);
        sort($expected);

        $this->assertSame($expected, $actual, 'hub-settings schema must declare exactly the expected settings keys.');
        $this->assertCount(18, $actual);
    }

    public function test_forbidden_infrastructure_keys_are_absent(): void
    {
        $properties = self::properties();

        foreach (self::FORBIDDEN_KEYS as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                $properties,
                sprintf('Hub setting "%s" is a DO-NOT-EXPOSE key and must not appear in the schema.', $forbidden)
            );
        }
    }

    public function test_every_key_first_segment_is_a_flat_config_file_name(): void
    {
        foreach (array_keys(self::properties()) as $key) {
            $this->assertStringContainsString('.', $key, sprintf('Setting key "%s" must be dotted.', $key));

            $segments = explode('.', $key);

            $this->assertMatchesRegularExpression(
                '/^[A-Za-z0-9_-]+$/',
                $segments[0],
                sprintf('Setting key "%s" must start with a flat config file name.', $key)
            );

            foreach (array_slice($segments, 1) as $segment) {
                $this->assertNotSame('', $segment, sprintf('Setting key "%s" must not contain an empty path segment.', $key));
            }
        }
    }

    /**
     * @dataProvider propertyProvider
     */
    public function test_property_has_the_expected_json_schema_type(string $key, string $expectedType): void
    {
        $properties = self::properties();
        $this->assertArrayHasKey($key, $properties);
        $this->assertSame($expectedType, $properties[$key]['type'] ?? null);
    }

    /**
     * @dataProvider propertyProvider
     */
    public function test_property_has_non_empty_group_and_description(string $key, string $expectedType): void
    {
        // $expectedType is part of the shared provider row but not asserted here.
        $this->assertNotSame('', $expectedType);

        $properties = self::properties();
        $this->assertArrayHasKey($key, $properties);

        $group = $properties[$key]['group'] ?? null;
        $description = $properties[$key]['description'] ?? null;

        $this->assertIsString($group, sprintf('Property "%s" must have a string group.', $key));
        $this->assertNotSame('', $group, sprintf('Property "%s" group must be non-empty.', $key));

        $this->assertIsString($description, sprintf('Property "%s" must have a string description.', $key));
        $this->assertNotSame('', $description, sprintf('Property "%s" description must be non-empty.', $key));
    }

    /**
     * @dataProvider propertyProvider
     */
    public function test_property_has_label_and_help_text(string $key, string $expectedType): void
    {
        $this->assertNotSame('', $expectedType);

        $properties = self::properties();
        $this->assertArrayHasKey($key, $properties);

        $label = $properties[$key]['label'] ?? null;
        $helpText = $properties[$key]['helpText'] ?? null;

        $this->assertIsString($label, sprintf('Property "%s" must have a string label.', $key));
        $this->assertNotSame('', $label, sprintf('Property "%s" label must be non-empty.', $key));

        $this->assertIsString($helpText, sprintf('Property "%s" must have a string helpText.', $key));
        $this->assertNotSame('', $helpText, sprintf('Property "%s" helpText must be non-empty.', $key));
    }

    /**
     * @dataProvider propertyProvider
     */
    public function test_property_declares_a_valid_tier(string $key, string $expectedType): void
    {
        $this->assertNotSame('', $expectedType);

        $properties = self::properties();
        $this->assertArrayHasKey($key, $properties);
        $this->assertPropertyDeclaresTier($key, $properties[$key]);
    }

    /**
     * @dataProvider propertyProvider
     */
    public function test_property_default_is_type_consistent_and_within_bounds(string $key, string $expectedType): void
    {
        $this->assertNotSame('', $expectedType);

        $properties = self::properties();
        $this->assertArrayHasKey($key, $properties);

        $this->assertDefaultMatchesDeclaredType($key, $properties[$key]);
        $this->assertDefaultIsWithinBounds($key, $properties[$key]);
    }

    /**
     * @dataProvider propertyProvider
     */
    public function test_property_enum_options_are_fully_documented(string $key, string $expectedType): void
    {
        $this->assertNotSame('', $expectedType);

        $properties = self::properties();
        $this->assertArrayHasKey($key, $properties);
        $this->assertEnumOptionsAreFullyDocumented($key, $properties[$key]);
    }

    /**
     * @dataProvider propertyProvider
     */
    public function test_optional_extended_keywords_are_well_formed(string $key, string $expectedType): void
    {
        $this->assertNotSame('', $expectedType);

        $properties = self::properties();
        $this->assertArrayHasKey($key, $properties);

        $this->assertHelpLinksAreWellFormed($key, $properties[$key]);
        $this->assertFlagKeywordsAreBooleans($key, $properties[$key]);
    }

    /**
     * Every hub key names at least one technical concept, so every hub key
     * must carry at least one documentation link (plan §3.5).
     *
     * @dataProvider propertyProvider
     */
    public function test_property_carries_at_least_one_help_link(string $key, string $expectedType): void
    {
        $this->assertNotSame('', $expectedType);

        $properties = self::properties();
        $this->assertArrayHasKey($key, $properties);

        $links = $properties[$key]['helpLinks'] ?? null;
        $this->assertIsArray($links, sprintf('Property "%s" must declare helpLinks.', $key));
        $this->assertNotEmpty($links, sprintf('Property "%s" must declare at least one help link.', $key));
    }

    /**
     * @dataProvider constraintProvider
     * @param array<string, int|float> $constraints
     */
    public function test_constrained_property_carries_its_numeric_bounds(string $key, array $constraints): void
    {
        $properties = self::properties();
        $this->assertArrayHasKey($key, $properties);

        foreach ($constraints as $constraintKey => $expectedValue) {
            $this->assertArrayHasKey(
                $constraintKey,
                $properties[$key],
                sprintf('Property "%s" must declare "%s".', $key, $constraintKey)
            );
            $this->assertEqualsWithDelta(
                $expectedValue,
                $properties[$key][$constraintKey],
                0.0,
                sprintf('Property "%s" "%s" must equal the documented bound.', $key, $constraintKey)
            );
        }
    }

    /**
     * Cross-repo drift guard: the schema's `properties` must equal the hub's
     * live `ALLOWED_KEYS` — key names in both directions, and JSON-Schema
     * types once the hub's PHP vocabulary (`int`, `bool`) is mapped.
     *
     * The hub owns the allow-list; this schema only supplies the render
     * metadata for those keys. A schema key with no allow-list entry is never
     * rendered; an allow-list key with no schema entry renders with no label
     * or help at all. Either drift is invisible to the hub test suite, so it
     * is caught here — against the real constant, not a restatement.
     *
     * Hub checkout resolution lives in
     * {@see self::hubSettingsRepositoryPath()}: an explicit `PHLIX_HUB_REPO`
     * pointer must be valid (a broken pointer fails, it never silently
     * skips), else a `phlix-hub` sibling of this checkout is probed. With no
     * hub checkout on disk the test skips; the frozen
     * {@see self::propertyProvider()} list is the best-effort mirror there.
     *
     * The guard is falsifiable, and pinned so: the parser provably reads only
     * the source handed to it
     * ({@see self::test_hub_allow_list_parser_reads_only_the_supplied_source()}),
     * broken const shapes throw loudly
     * ({@see self::test_hub_allow_list_parser_fails_loud_on_broken_shapes()}),
     * and the comparator trips on every drift shape an injected wrong list
     * can express
     * ({@see self::test_hub_allow_list_comparator_trips_on_injected_drift()}).
     */
    public function test_properties_match_hub_allow_list_when_hub_checkout_available(): void
    {
        $path = $this->hubSettingsRepositoryPath();
        if ($path === null) {
            $this->markTestSkipped(
                'No phlix-hub checkout on disk: place phlix-hub beside phlix-shared, or set '
                . 'PHLIX_HUB_REPO to a phlix-hub checkout, to run the ALLOWED_KEYS drift guard.'
            );
        }

        $source = file_get_contents($path);
        if (!is_string($source)) {
            $this->markTestSkipped(sprintf('Hub checkout "%s" became unreadable mid-test.', $path));
        }

        self::assertSchemaPropertiesMatchHubAllowList(self::properties(), self::parseHubAllowedKeys($source));
    }

    /**
     * Falsifiability pin #1: the parser returns what the SOURCE says, not
     * what the schema says. The fixture shares no key with the live schema;
     * if the parser were ever rewired to read the schema — or to hand back
     * the expected list, making the guard vacuous — this test goes red. It
     * also pins that interleaved comments are tolerated, that double-quoted
     * literals parse, and that the walk stops at the `ALLOWED_KEYS` const's
     * own closing bracket instead of bleeding into `DENIED_KEYS`.
     */
    public function test_hub_allow_list_parser_reads_only_the_supplied_source(): void
    {
        $source = <<<'PHP'
            <?php

            declare(strict_types=1);

            final class HubSettingsRepository
            {
                public const array ALLOWED_KEYS = [
                    // config/server.php
                    'server.enrollment_ttl' => 'int',
                    'auth.access_ttl' => 'int',
                    'auth.signups_disabled' => 'bool', // trailing comment
                    "auth.dquoted" => "bool",
                ];

                public const array DENIED_KEYS = [
                    'auth.secret',
                ];
            }
            PHP;

        self::assertSame(
            [
                'server.enrollment_ttl' => 'int',
                'auth.access_ttl' => 'int',
                'auth.signups_disabled' => 'bool',
                'auth.dquoted' => 'bool',
            ],
            self::parseHubAllowedKeys($source)
        );
    }

    /**
     * Falsifiability pin #2 (Fail Fast law): malformed hub sources must throw
     * a descriptive RuntimeException rather than mis-parse into a plausible
     * but wrong list — a silent mis-parse is how a drift guard rots.
     */
    public function test_hub_allow_list_parser_fails_loud_on_broken_shapes(): void
    {
        $broken = [
            'const absent' => "<?php class R { public const array DENIED_KEYS = ['a' => 'int']; }",
            'dangling key without a type' => "<?php class R { public const array ALLOWED_KEYS = ['a']; }",
            'type is not a string literal' => "<?php class R { public const array ALLOWED_KEYS = ['a' => self::T]; }",
            'value before any key' => "<?php class R { public const array ALLOWED_KEYS = [=>'int']; }",
            'unclosed literal' => "<?php class R { public const array ALLOWED_KEYS = ['a' => 'int'",
        ];

        foreach ($broken as $case => $source) {
            $threw = false;
            try {
                self::parseHubAllowedKeys($source);
            } catch (RuntimeException) {
                $threw = true;
            }
            self::assertTrue($threw, sprintf('Broken source "%s" must throw, not mis-parse.', $case));
        }
    }

    /**
     * Falsifiability pin #3: the type mapping is total over the hub's current
     * vocabulary (`int`, `bool`) and refuses to guess at vocabulary it has
     * not been taught, so a hub-side `date`/`array`/whatever addition fails
     * loudly here instead of being silently coerced.
     */
    public function test_hub_type_vocabulary_maps_onto_json_schema_types(): void
    {
        self::assertSame('integer', self::jsonSchemaTypeForHubType('int'));
        self::assertSame('boolean', self::jsonSchemaTypeForHubType('bool'));
        self::assertSame('string', self::jsonSchemaTypeForHubType('string'));
        self::assertSame('number', self::jsonSchemaTypeForHubType('float'));
        self::assertSame('number', self::jsonSchemaTypeForHubType('number'));
        self::assertSame('object', self::jsonSchemaTypeForHubType('json'));

        $this->expectException(RuntimeException::class);
        self::jsonSchemaTypeForHubType('date');
    }

    /**
     * Falsifiability pin #4: the comparison itself. The control list proves
     * the comparator passes real agreement; every injected drift case —
     * allow-list growth, allow-list shrinkage, a type flip, and the vacuity
     * tripwire of an empty parsed list — must fail it. Without this, a
     * comparator quietly weakened to `assertTrue(true)` would keep CI green
     * forever.
     */
    public function test_hub_allow_list_comparator_trips_on_injected_drift(): void
    {
        $properties = [
            'a.one' => ['type' => 'integer'],
            'b.two' => ['type' => 'boolean'],
        ];

        // Control first: if this stops passing, the "trips" below prove nothing.
        self::assertSchemaPropertiesMatchHubAllowList($properties, ['a.one' => 'int', 'b.two' => 'bool']);

        $driftCases = [
            'allow-list grew a key the schema lacks' => ['a.one' => 'int', 'b.two' => 'bool', 'c.three' => 'string'],
            'allow-list dropped a key the schema still renders' => ['a.one' => 'int'],
            'type flipped under the schema (int -> string)' => ['a.one' => 'string', 'b.two' => 'bool'],
            'parsed list is empty (vacuity tripwire)' => [],
        ];

        foreach ($driftCases as $case => $allowList) {
            $tripped = false;
            try {
                self::assertSchemaPropertiesMatchHubAllowList($properties, $allowList);
            } catch (AssertionFailedError) {
                $tripped = true;
            }
            self::assertTrue($tripped, sprintf('Drift case "%s" must fail the guard.', $case));
        }
    }

    /**
     * Absolute path to the hub's `HubSettingsRepository.php`, or null when no
     * hub checkout is on disk.
     *
     * `PHLIX_HUB_REPO` is an explicit pointer: when set it is the ONLY source
     * consulted, and an unreadable target fails the test outright — silently
     * skipping a guard someone deliberately wired up would recreate the exact
     * blind spot this test exists to close. Without the env var, the estate
     * layout (`<parent>/phlix-shared` beside `<parent>/phlix-hub`) is probed
     * and a missing checkout yields null (skip), keeping this package
     * testable standalone.
     */
    private function hubSettingsRepositoryPath(): ?string
    {
        $env = getenv('PHLIX_HUB_REPO');
        if (is_string($env) && $env !== '') {
            $path = rtrim($env, '/') . '/src/Hub/HubSettingsRepository.php';
            if (!is_readable($path)) {
                $this->fail(sprintf(
                    'PHLIX_HUB_REPO points at "%s" but "%s" is not readable — fix the pointer or unset it.',
                    $env,
                    $path
                ));
            }

            return $path;
        }

        $sibling = dirname(__DIR__, 3) . '/phlix-hub/src/Hub/HubSettingsRepository.php';

        return is_readable($sibling) ? $sibling : null;
    }

    /**
     * Assert schema `properties` == parsed hub allow-list, both directions,
     * names AND mapped types, in one sorted-map comparison.
     *
     * @param array<string, array<string, mixed>> $properties Decoded schema `properties`.
     * @param array<string, string> $allowList Raw `ALLOWED_KEYS` as parsed from hub source.
     */
    private static function assertSchemaPropertiesMatchHubAllowList(array $properties, array $allowList): void
    {
        self::assertNotEmpty(
            $allowList,
            'Parsed ALLOWED_KEYS is empty — either the hub genuinely allows nothing (then the '
            . 'schema must declare nothing) or the parser regressed. Refusing to pass vacuously.'
        );

        $expected = [];
        foreach ($allowList as $key => $hubType) {
            $expected[$key] = self::jsonSchemaTypeForHubType($hubType);
        }

        $actual = [];
        foreach ($properties as $key => $definition) {
            $type = $definition['type'] ?? null;
            $actual[$key] = is_string($type) ? $type : '(no type declared)';
        }

        ksort($expected);
        ksort($actual);

        self::assertSame(
            $expected,
            $actual,
            'hub-settings schema properties drifted from phlix-hub '
            . 'HubSettingsRepository::ALLOWED_KEYS. Keys only in the schema render nothing; keys '
            . 'only in the allow-list render without label/help. Fix schemas/'
            . 'hub-settings.schema.json or the hub const — whichever side is wrong — never this guard.'
        );
    }

    /**
     * Map the hub's PHP-side type vocabulary onto JSON-Schema type names.
     *
     * @throws RuntimeException For vocabulary this mapping has not been taught.
     */
    private static function jsonSchemaTypeForHubType(string $hubType): string
    {
        return match ($hubType) {
            'int' => 'integer',
            'bool' => 'boolean',
            'string' => 'string',
            'float', 'number' => 'number',
            // The hub serialises sparse-JSON blobs (e.g. `server.rate_limit`)
            // under the `json` type; the schema renders them as objects —
            // mapped deliberately when the W5 keys were upstreamed (v0.52.0).
            'json' => 'object',
            default => throw new RuntimeException(sprintf(
                'Hub ALLOWED_KEYS carries type vocabulary "%s" that this mapping has never seen; '
                . 'extend jsonSchemaTypeForHubType() deliberately, not by silent coercion.',
                $hubType
            )),
        };
    }

    /**
     * Parse the `ALLOWED_KEYS` `'key' => 'type'` literal out of hub source.
     *
     * Tokenized, not regex-matched: the live const interleaves multi-line
     * admission-rule comments between every entry, and comments are single
     * tokens, so the walk cannot be fooled by prose. The scan stops at the
     * const's own closing bracket, so nothing below it (e.g. `DENIED_KEYS`)
     * leaks in, and any shape other than plain quoted-string pairs throws.
     *
     * @param string $source Full PHP source of the hub's HubSettingsRepository.
     *
     * @return array<string, string> Raw hub-side entries, declaration order.
     *
     * @throws RuntimeException When the const is absent, unclosed, or carries
     *                          a shape this parser refuses to guess about.
     */
    private static function parseHubAllowedKeys(string $source): array
    {
        $tokens = token_get_all($source);

        $nameIndex = self::findTokenByName($tokens, 'ALLOWED_KEYS');
        if ($nameIndex === null) {
            throw new RuntimeException(
                'Hub source contains no ALLOWED_KEYS identifier — the const was renamed or removed.'
            );
        }

        $openIndex = self::findArrayOpenAfter($tokens, $nameIndex);
        $count = count($tokens);

        $entries = [];
        $pendingKey = null;
        $awaitingType = false;
        $depth = 1;
        $closed = false;

        for ($i = $openIndex + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === '[') {
                    $depth++;
                } elseif ($token === ']') {
                    $depth--;
                    if ($depth === 0) {
                        $closed = true;
                        break;
                    }
                }
                continue;
            }

            if ($depth !== 1) {
                continue;
            }

            if ($token[0] === T_DOUBLE_ARROW) {
                if ($pendingKey === null || $awaitingType) {
                    throw new RuntimeException(sprintf(
                        'ALLOWED_KEYS carries a "=>" at token %d that does not follow exactly one key.',
                        $i
                    ));
                }
                $awaitingType = true;
                continue;
            }

            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $text = trim($token[1], "'\"");
                if (str_contains($text, '\\')) {
                    throw new RuntimeException(sprintf(
                        'ALLOWED_KEYS entry "%s" uses an escaped literal; this parser only understands '
                        . 'plain quoted snake.dotted keys.',
                        $text
                    ));
                }

                if (!$awaitingType) {
                    if ($pendingKey !== null) {
                        throw new RuntimeException(sprintf(
                            'ALLOWED_KEYS key "%s" is not followed by "=>" before the next string.',
                            $pendingKey
                        ));
                    }
                    $pendingKey = $text;
                    continue;
                }

                if ($pendingKey === null) {
                    throw new RuntimeException(sprintf(
                        'ALLOWED_KEYS carries a type value "%s" with no key pending — impossible state.',
                        $text
                    ));
                }
                $entries[$pendingKey] = $text;
                $pendingKey = null;
                $awaitingType = false;
            }
        }

        if (!$closed) {
            throw new RuntimeException('The ALLOWED_KEYS literal never closes — hub source is truncated.');
        }

        if ($pendingKey !== null || $awaitingType) {
            throw new RuntimeException(sprintf(
                'ALLOWED_KEYS ends with an incomplete entry (%s awaiting a %s).',
                $pendingKey !== null ? '"' . $pendingKey . '"' : 'a bare value',
                $awaitingType ? 'type value' : '"=>" after the key'
            ));
        }

        return $entries;
    }

    /**
     * Index of the first bare (non-comment) identifier token with this name.
     *
     * Comments are single doc-comment tokens, so a `{@see ALLOWED_KEYS}` in
     * prose never matches — only real code does.
     *
     * @param list<array{0:int, 1:string, 2:int}|string> $tokens
     */
    private static function findTokenByName(array $tokens, string $name): ?int
    {
        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_STRING && $token[1] === $name) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Index of the `[` that opens the array literal assigned after `$from`.
     *
     * @param list<array{0:int, 1:string, 2:int}|string> $tokens
     *
     * @throws RuntimeException When no `= [` is found before the statement ends.
     */
    private static function findArrayOpenAfter(array $tokens, int $from): int
    {
        $count = count($tokens);
        $sawEquals = false;

        for ($i = $from + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_string($token)) {
                continue;
            }

            if (!$sawEquals) {
                if ($token === '=') {
                    $sawEquals = true;
                }
                continue;
            }

            if ($token === '[') {
                return $i;
            }

            if ($token === ';') {
                break;
            }
        }

        throw new RuntimeException(
            'No array literal follows "ALLOWED_KEYS =" — the const is no longer a plain array literal.'
        );
    }

    /**
     * Liveness check for every documentation link in the hub schema.
     *
     * Excluded from the default suite (see `phpunit.xml`); run deliberately
     * with `--group network`.
     *
     * @group network
     */
    public function test_help_links_resolve(): void
    {
        $urls = self::collectHelpLinkUrls(self::properties());
        $this->assertNotEmpty($urls);

        $dead = [];
        foreach ($urls as $url) {
            $status = SchemaLinkProbe::status($url);
            if (!SchemaLinkProbe::isAcceptable($status)) {
                $dead[] = sprintf('%s -> %d', $url, $status);
            }
        }

        $this->assertSame([], $dead, "Dead helpLinks URLs:\n" . implode("\n", $dead));
    }
}
