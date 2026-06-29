<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Support;

use InvalidArgumentException;
use Phlix\Shared\Support\PayloadAssert;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
trait PayloadAssertTraitStub
{
    use PayloadAssert;

    private const CONTEXT = 'StubClass';

    /**
     * @param array<string, mixed> $payload
     */
    protected static function requireStringStub(array $payload, string $key): string
    {
        return self::requireString($payload, $key, self::CONTEXT);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected static function requireIntStub(array $payload, string $key): int
    {
        return self::requireInt($payload, $key, self::CONTEXT);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected static function requireBoolStub(array $payload, string $key): bool
    {
        return self::requireBool($payload, $key, self::CONTEXT);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected static function optionalStringStub(array $payload, string $key, string $default = ''): string
    {
        return self::optionalString($payload, $key, self::CONTEXT, $default);
    }
}

/**
 * @covers \Phlix\Shared\Support\PayloadAssert
 */
final class PayloadAssertTest extends TestCase
{
    use PayloadAssertTraitStub;

    public function test_require_string_returns_string_value(): void
    {
        $payload = ['key' => 'value'];
        $result = $this->requireStringStub($payload, 'key');

        $this->assertSame('value', $result);
    }

    public function test_require_string_throws_on_missing_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('StubClass "missing" is required.');

        $this->requireStringStub([], 'missing');
    }

    public function test_require_string_throws_on_non_string_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('StubClass "key" must be a string.');

        $this->requireStringStub(['key' => 123], 'key');
    }

    public function test_require_int_returns_int_value(): void
    {
        $payload = ['key' => 42];
        $result = $this->requireIntStub($payload, 'key');

        $this->assertSame(42, $result);
    }

    public function test_require_int_throws_on_missing_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('StubClass "missing" is required.');

        $this->requireIntStub([], 'missing');
    }

    public function test_require_int_throws_on_non_int_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('StubClass "key" must be an integer.');

        $this->requireIntStub(['key' => 'not-an-int'], 'key');
    }

    public function test_require_bool_returns_bool_value(): void
    {
        $payloadTrue = ['key' => true];
        $payloadFalse = ['key' => false];

        $this->assertTrue($this->requireBoolStub($payloadTrue, 'key'));
        $this->assertFalse($this->requireBoolStub($payloadFalse, 'key'));
    }

    public function test_require_bool_throws_on_missing_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('StubClass "missing" is required.');

        $this->requireBoolStub([], 'missing');
    }

    public function test_require_bool_throws_on_non_bool_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('StubClass "key" must be a boolean.');

        $this->requireBoolStub(['key' => 'not-a-bool'], 'key');
    }

    public function test_optional_string_returns_value_when_present(): void
    {
        $payload = ['key' => 'value'];
        $result = $this->optionalStringStub($payload, 'key');

        $this->assertSame('value', $result);
    }

    public function test_optional_string_returns_default_when_missing(): void
    {
        $result = $this->optionalStringStub([], 'missing');

        $this->assertSame('', $result);
    }

    public function test_optional_string_returns_custom_default_when_missing(): void
    {
        $result = $this->optionalStringStub([], 'missing', 'custom-default');

        $this->assertSame('custom-default', $result);
    }

    public function test_optional_string_throws_on_non_string_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('StubClass "key" must be a string.');

        $this->optionalStringStub(['key' => 123], 'key');
    }
}
