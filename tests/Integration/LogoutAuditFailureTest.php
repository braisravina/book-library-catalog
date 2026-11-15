<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Security\Role;
use PHPUnit\Framework\Attributes\DataProvider;

final class LogoutAuditFailureTest extends IntegrationTestCase
{
    public static function failureModes(): array
    {
        return ['preparation failure' => ['prepare'], 'execution FK failure' => ['execute']];
    }

    #[DataProvider('failureModes')]
    public function testNativeLogoutInvalidatesEvenWhenAuditFails(string $mode): void
    {
        $actor = $this->createUser('logout@example.org', Role::Member);
        if ($mode === 'prepare') {
            $this->migrationPdo()->exec('RENAME TABLE audit_log TO logout_audit_unavailable');
        }
        try {
            [$result, $err] = $this->worker($actor, $mode);
            self::assertSame(303, $result['status']);
            self::assertSame('/login', $result['location']);
            self::assertFalse($result['authenticated']);
            self::assertTrue($result['rotated']);
            self::assertTrue($result['csrf_rotated']);
            self::assertSame([['type' => 'warning', 'message' => 'auth.logged_out_audit_failed']], $result['flashes']);
            self::assertSame(303, $result['next_status']);
            self::assertSame('/login', $result['next_location']);
            self::assertFalse($result['next_authenticated']);
            self::assertStringContainsString('auth.logout audit recording failed; session invalidated', $err);
            self::assertStringNotContainsString('SQLSTATE', $err . $result['body']);
        } finally {
            if ($mode === 'prepare') {
                $this->migrationPdo()->exec('RENAME TABLE logout_audit_unavailable TO audit_log');
            }
        }
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action='auth.logout'"));
    }

    public function testLogoutStillRequiresCsrfAndSuccessfulLogoutStillAudits(): void
    {
        $actor = $this->createUser('logout@example.org', Role::Member);
        [$bad] = $this->worker($actor, 'csrf');
        self::assertSame(419, $bad['status']);
        self::assertTrue($bad['authenticated']);
        self::assertFalse($bad['rotated']);
        self::assertSame(200, $bad['next_status']);
        [$ok, $err] = $this->worker($actor, 'success');
        self::assertSame(303, $ok['status']);
        self::assertFalse($ok['authenticated']);
        self::assertFalse($ok['next_authenticated']);
        self::assertSame([['type' => 'success', 'message' => 'auth.logged_out']], $ok['flashes']);
        self::assertSame('', $err);
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action='auth.logout'"));
    }

    private function worker(int $actor, string $mode): array
    {
        $dir = sys_get_temp_dir() . '/dla-logout-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $values = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://library.test', 'METADATA_PROVIDERS' => 'none'];
        foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            $values[$key] = self::config()->get($key);
        }
        $job = ['config' => $values, 'actor' => $actor, 'mode' => $mode, 'sessions' => $dir];
        try {
            $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/logout_worker.php'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2),
                ['DLA_LOGOUT_JOB' => json_encode($job, JSON_THROW_ON_ERROR)]);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $err);

            return [json_decode($out, true, 32, JSON_THROW_ON_ERROR), $err];
        } finally {
            foreach (glob($dir . '/*') as $file) { unlink($file); }
            rmdir($dir);
        }
    }
}
