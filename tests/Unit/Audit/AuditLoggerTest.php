<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Audit;

use DigitalLibrary\Audit\AuditLogger;
use PHPUnit\Framework\TestCase;

final class AuditLoggerTest extends TestCase
{
    public function testSecretsAreRedactedRecursively(): void
    {
        self::assertSame(
            ['email' => 'a@example.org', 'password' => '[redacted]', 'nested' => ['Token' => '[redacted]', 'ok' => 1]],
            AuditLogger::redact(['email' => 'a@example.org', 'password' => 'hunter2', 'nested' => ['Token' => 'abc', 'ok' => 1]]),
        );
    }
}
