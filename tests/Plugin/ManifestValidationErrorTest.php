<?php

declare(strict_types=1);

namespace Phlix\Shared\Tests\Plugin;

use Phlix\Shared\Plugin\ManifestValidationError;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Plugin\ManifestValidationError
 */
final class ManifestValidationErrorTest extends TestCase
{
    public function test_readonly_props_populated(): void
    {
        $error = new ManifestValidationError('settings.key', 'required', 'Field is required.');

        $this->assertSame('settings.key', $error->field);
        $this->assertSame('required', $error->code);
        $this->assertSame('Field is required.', $error->message);
    }

    public function test_toArray_returns_map(): void
    {
        $error = new ManifestValidationError('name', 'pattern', 'Does not match pattern.');

        $this->assertSame(
            [
                'field' => 'name',
                'code' => 'pattern',
                'message' => 'Does not match pattern.',
            ],
            $error->toArray()
        );
    }
}
