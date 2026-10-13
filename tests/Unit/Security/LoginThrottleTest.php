<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Security;

use DigitalLibrary\Security\LoginThrottle;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class LoginThrottleTest extends TestCase
{
    private PDO $pdo;
    private LoginThrottle $throttle;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, identifier_hash TEXT, ip_address TEXT, succeeded INTEGER, attempted_at TEXT)');
        $this->throttle = new LoginThrottle($this->pdo, maxPerAccount: 3, maxPerIp: 5, windowSeconds: 600);
    }

    public function testLocksAccountAfterMaxFailures(): void
    {
        $now = 1_700_000_000;
        for ($i = 0; $i < 2; $i++) {
            $this->throttle->recordFailure('User@Example.org', '10.0.0.1', $now + $i);
        }
        self::assertFalse($this->throttle->isLocked('user@example.org', '10.0.0.1', $now + 5));

        $this->throttle->recordFailure(' user@example.org ', '10.0.0.2', $now + 2);
        self::assertTrue($this->throttle->isLocked('user@example.org', '10.0.0.9', $now + 5), 'Account lock applies from any IP and is case-insensitive');
        self::assertSame(595, $this->throttle->retryAfter('user@example.org', '10.0.0.9', $now + 5));

        self::assertFalse($this->throttle->isLocked('user@example.org', '10.0.0.9', $now + 601), 'Lock expires with the window');
    }

    public function testLocksIpAcrossAccounts(): void
    {
        $now = 1_700_000_000;
        for ($i = 0; $i < 5; $i++) {
            $this->throttle->recordFailure("user{$i}@example.org", '10.0.0.1', $now);
        }
        self::assertTrue($this->throttle->isLocked('fresh@example.org', '10.0.0.1', $now + 1));
        self::assertFalse($this->throttle->isLocked('fresh@example.org', '10.0.0.2', $now + 1));
    }

    public function testSuccessClearsAccountFailures(): void
    {
        $now = 1_700_000_000;
        $this->throttle->recordFailure('a@example.org', '10.0.0.1', $now);
        $this->throttle->recordFailure('a@example.org', '10.0.0.1', $now);
        $this->throttle->recordSuccess('a@example.org', '10.0.0.1', $now);
        $this->throttle->recordFailure('a@example.org', '10.0.0.1', $now);
        self::assertFalse($this->throttle->isLocked('a@example.org', '10.0.0.1', $now));
    }

    public function testEmailsAreNotStoredInClear(): void
    {
        $this->throttle->recordFailure('secret@example.org', '10.0.0.1');
        $stored = $this->pdo->query('SELECT identifier_hash FROM login_attempts')->fetchColumn();
        self::assertSame(hash('sha256', 'secret@example.org'), $stored);
    }

    public function testPrune(): void
    {
        $now = 1_700_000_000;
        $this->throttle->recordFailure('a@example.org', '10.0.0.1', $now - 100_000);
        $this->throttle->recordFailure('a@example.org', '10.0.0.1', $now);
        self::assertSame(1, $this->throttle->prune(86_400, $now));
    }
}
