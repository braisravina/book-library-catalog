<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Support;

/**
 * The integration-test database configuration is unsafe. Always an error,
 * never a reason to skip.
 */
final class TestDatabaseSafetyException extends \RuntimeException
{
}
