<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Backup\BackupCreator;
use DigitalLibrary\Console\Console;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Maintenance\Cleanup;
use DigitalLibrary\Security\Role;
use PDO;

/**
 * Retention and housekeeping: dry runs, safe defaults, what is and is
 * never deleted, orphaned covers (report only by default) and backup
 * retention that always keeps the newest verified backup.
 */
final class CleanupTest extends IntegrationTestCase
{
    private string $work;
    private int $adminId;
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = sys_get_temp_dir() . '/dla-cleanup-test-' . bin2hex(random_bytes(6));
        foreach (['covers/ab', 'tmp', 'backups', 'outside'] as $dir) {
            mkdir($this->work . '/' . $dir, 0700, true);
        }
        $this->adminId = $this->createUser('admin@example.org', Role::Admin);
        $this->now = time();
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->work, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->work);
        parent::tearDown();
    }

    public function testDryRunReportsAndApplyRemovesOnlyExpiredRecords(): void
    {
        $member = $this->createUser('member@example.org', Role::Member);
        $insert = fn (string $email, string $expires, ?string $revoked = null, ?int $accepted = null) => $this->pdo()->exec(
            "INSERT INTO invitations (email, role_id, token_hash, invited_by, expires_at, revoked_at, accepted_at, accepted_user_id, created_at)
             VALUES ('{$email}', 2, '" . hash('sha256', $email) . "', {$this->adminId}, '{$expires}', " . ($revoked === null ? 'NULL' : "'{$revoked}'")
                . ', ' . ($accepted === null ? 'NULL' : "'{$expires}'") . ', ' . ($accepted ?? 'NULL') . ", '2020-01-01 00:00:00')"
        );
        $insert('old-expired@example.org', $this->ago(100));
        $insert('old-revoked@example.org', $this->ago(-5), $this->ago(100));
        $insert('recent-expired@example.org', $this->ago(10));
        $insert('accepted@example.org', $this->ago(400), null, $member);
        $insert('pending@example.org', $this->ago(-5));
        $this->pdo()->exec("INSERT INTO login_attempts (identifier_hash, ip_address, succeeded, attempted_at) VALUES
            ('" . str_repeat('a', 64) . "', '192.0.2.1', 0, '" . $this->ago(40) . "'),
            ('" . str_repeat('b', 64) . "', '192.0.2.2', 0, '" . $this->ago(1, 3600) . "')");
        $book = $this->makeBook($member, 'Kept', null, [], [], [], $member);
        $this->pdo()->exec("INSERT INTO loans (copy_id, borrower_name, loaned_on, returned_on, created_at, updated_at) VALUES ({$book['copy_id']}, 'Old', '2000-01-01', '2000-02-01', '2000-01-01', '2000-01-01')");
        $this->pdo()->exec("INSERT INTO audit_log (action, created_at) VALUES ('ancient.event', '2000-01-01 00:00:00')");
        $before = $this->counts();

        $dry = $this->cleanup()->run(['invitations', 'login-attempts'], false, false, null, $this->now);
        self::assertSame([2, 0], [$dry['invitations']['found'], $dry['invitations']['removed']]);
        self::assertSame([1, 0], [$dry['login-attempts']['found'], $dry['login-attempts']['removed']]);
        self::assertSame($before, $this->counts(), 'A dry run changes nothing');

        $applied = $this->cleanup()->run(['invitations', 'login-attempts'], true, false, null, $this->now);
        self::assertSame(2, $applied['invitations']['removed']);
        self::assertSame(1, $applied['login-attempts']['removed']);
        self::assertSame(['accepted@example.org', 'pending@example.org', 'recent-expired@example.org'],
            $this->pdo()->query('SELECT email FROM invitations ORDER BY email')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM login_attempts'));
        foreach (['loans', 'works', 'editions', 'copies', 'users', 'audit_log'] as $table) {
            self::assertSame($before[$table], $this->countRows("SELECT COUNT(*) FROM {$table}"), $table . ' is never cleaned');
        }
    }

    public function testTemporaryFilesAndOrphanedCovers(): void
    {
        $old = $this->now - 3 * 86400;
        $tmp = $this->work . '/tmp';
        mkdir($tmp . '/restore-0123456789abcdef/covers', 0700, true);
        file_put_contents($tmp . '/restore-0123456789abcdef/covers/x', 'x');
        mkdir($tmp . '/restore-fedcba9876543210', 0700);
        file_put_contents($tmp . '/notes.txt', 'not ours');
        file_put_contents($this->work . '/outside/precious', 'do not follow links');
        symlink($this->work . '/outside', $tmp . '/restore-aaaaaaaaaaaaaaaa');
        file_put_contents($this->work . '/backups/.tmp-dla-0123456789abcdef.sql', 'partial');
        foreach (['/restore-0123456789abcdef', '/notes.txt'] as $item) {
            touch($tmp . $item, $old);
        }
        touch($this->work . '/backups/.tmp-dla-0123456789abcdef.sql', $old);
        exec('touch -h -d @' . $old . ' ' . escapeshellarg($tmp . '/restore-aaaaaaaaaaaaaaaa'));

        $book = $this->makeBook($this->adminId, 'With cover');
        $key = 'ab/ab' . str_repeat('1', 30) . '.jpg';
        $this->pdo()->exec("INSERT INTO covers (edition_id, storage_key, mime_type, width, height, byte_size, sha256, created_at)
            VALUES ({$book['edition_id']}, '{$key}', 'image/jpeg', 1, 1, 1, '" . str_repeat('0', 64) . "', UTC_TIMESTAMP())");
        $covers = $this->work . '/covers';
        foreach ([$key, substr($key, 0, -4) . '.thumb.jpg', 'ab/ab' . str_repeat('2', 30) . '.jpg', 'ab/ab' . str_repeat('2', 30) . '.thumb.jpg', 'ab/ab' . str_repeat('3', 30) . '.jpg'] as $file) {
            file_put_contents($covers . '/' . $file, 'img');
            touch($covers . '/' . $file, $old);
        }
        touch($covers . '/ab/ab' . str_repeat('3', 30) . '.jpg', $this->now); // a fresh upload in flight

        $report = $this->cleanup()->run(['temp-files', 'orphan-covers'], false, false, null, $this->now);
        self::assertSame(3, $report['temp-files']['found']);
        self::assertSame(2, $report['orphan-covers']['found']);
        self::assertStringContainsString('report only', implode(' ', $report['orphan-covers']['details']));

        // --apply alone still does not delete covers.
        $applied = $this->cleanup()->run(['temp-files', 'orphan-covers'], true, false, null, $this->now);
        self::assertSame(3, $applied['temp-files']['removed']);
        self::assertSame(0, $applied['orphan-covers']['removed']);
        self::assertFileExists($covers . '/ab/ab' . str_repeat('2', 30) . '.jpg');
        self::assertDirectoryDoesNotExist($tmp . '/restore-0123456789abcdef');
        self::assertDirectoryExists($tmp . '/restore-fedcba9876543210', 'Recent work directories are kept');
        self::assertFileExists($tmp . '/notes.txt', 'Files the application did not create are kept');
        self::assertFalse(is_link($tmp . '/restore-aaaaaaaaaaaaaaaa'), 'The symbolic link itself is removed');
        self::assertSame('do not follow links', file_get_contents($this->work . '/outside/precious'), 'Its target is never followed');
        self::assertFileDoesNotExist($this->work . '/backups/.tmp-dla-0123456789abcdef.sql');

        $deleted = $this->cleanup()->run(['orphan-covers'], true, true, null, $this->now);
        self::assertSame(2, $deleted['orphan-covers']['removed']);
        self::assertFileExists($covers . '/' . $key, 'Referenced covers are kept');
        self::assertFileExists($covers . '/' . substr($key, 0, -4) . '.thumb.jpg', 'Thumbnails of referenced covers are kept');
        self::assertFileExists($covers . '/ab/ab' . str_repeat('3', 30) . '.jpg', 'Recent files are kept');
        self::assertFileDoesNotExist($covers . '/ab/ab' . str_repeat('2', 30) . '.jpg');
    }

    public function testBackupRetentionAlwaysKeepsTheNewestVerifiedBackup(): void
    {
        $creator = new BackupCreator($this->pdo(), $this->work . '/covers', dirname(__DIR__, 2) . '/public');
        $paths = [];
        for ($i = 0; $i < 4; $i++) {
            $paths[] = $creator->create($this->work . '/backups')['path'];
        }
        // Give them distinct, old timestamps (names sort by creation time).
        $named = [];
        foreach ($paths as $i => $path) {
            $name = $this->work . '/backups/dla-backup-2026010' . ($i + 1) . 'T000000Z.tar.gz';
            rename($path, $name);
            rename($path . '.sha256', $name . '.sha256');
            file_put_contents($name . '.sha256', hash_file('sha256', $name) . '  ' . basename($name) . "\n");
            touch($name, $this->now - (40 - $i) * 86400);
            $named[] = $name;
        }
        file_put_contents($this->work . '/backups/unrelated.tar.gz', 'not a backup');
        touch($this->work . '/backups/unrelated.tar.gz', 0);

        // The newest archive is corrupted: the newest VERIFIED one must survive.
        file_put_contents($named[3], 'corrupted');
        $dry = $this->cleanup(['BACKUP_KEEP_COUNT' => '1', 'BACKUP_KEEP_DAYS' => '1'])->run(['backups'], false, false, null, $this->now);
        self::assertSame(2, $dry['backups']['found']);
        self::assertStringContainsString(basename($named[2]), $dry['backups']['details'][0]);
        foreach ($named as $name) {
            self::assertFileExists($name, 'dry run');
        }

        $applied = $this->cleanup(['BACKUP_KEEP_COUNT' => '1', 'BACKUP_KEEP_DAYS' => '1'])->run(['backups'], true, false, null, $this->now);
        self::assertSame(2, $applied['backups']['removed']);
        self::assertFileDoesNotExist($named[0]);
        self::assertFileDoesNotExist($named[0] . '.sha256');
        self::assertFileDoesNotExist($named[1]);
        self::assertFileExists($named[2], 'newest verified backup');
        self::assertFileExists($named[3], 'newest by count');
        self::assertFileExists($this->work . '/backups/unrelated.tar.gz', 'Files not named like backups are never touched');

        // If no backup verifies, nothing is deleted at all.
        file_put_contents($named[2], 'also corrupted');
        $none = $this->cleanup(['BACKUP_KEEP_COUNT' => '1', 'BACKUP_KEEP_DAYS' => '1'])->run(['backups'], true, false, null, $this->now + 365 * 86400);
        self::assertSame([0, 0], [$none['backups']['found'], $none['backups']['removed']]);
        self::assertStringContainsString('no backup passes verification', $none['backups']['details'][0]);
        self::assertFileExists($named[2]);
    }

    public function testPolicyDefaultsAndConsole(): void
    {
        self::assertSame(['invitation_days' => 90, 'login_attempt_days' => 30, 'temp_hours' => 24, 'orphan_min_hours' => 24, 'backup_keep_count' => 14, 'backup_keep_days' => 30], Cleanup::policy([]));
        self::assertSame(1, Cleanup::policy(['LOGIN_ATTEMPT_RETENTION_DAYS' => '0'])['login_attempt_days'], 'Never below one day');
        self::assertSame(1, Cleanup::policy(['BACKUP_KEEP_COUNT' => '-5'])['backup_keep_count'], 'Always keep at least one backup');
        self::assertSame(90, Cleanup::policy(['INVITATION_RETENTION_DAYS' => 'abc'])['invitation_days'], 'Invalid values fall back to the default');

        $this->pdo()->exec("INSERT INTO login_attempts (identifier_hash, ip_address, succeeded, attempted_at) VALUES ('" . str_repeat('c', 64) . "', '192.0.2.3', 0, '2000-01-01 00:00:00')");
        self::$configOverrides = ['BACKUP_PATH' => $this->work . '/backups', 'TEMP_PATH' => $this->work . '/tmp', 'COVER_STORAGE_PATH' => $this->work . '/covers'];
        $services = new Services(self::config(), dirname(__DIR__, 2));
        $services->set(PDO::class, $this->pdo());
        $run = function (array $args) use ($services): array {
            $out = fopen('php://memory', 'w+');
            $err = fopen('php://memory', 'w+');
            $code = (new Console($services, null, $out, $err))->run(array_merge(['console'], $args));
            rewind($out);
            rewind($err);

            return [$code, stream_get_contents($out), stream_get_contents($err)];
        };
        [$code, $out] = $run(['cleanup']);
        self::assertSame(0, $code);
        self::assertStringContainsString('dry run', $out);
        self::assertMatchesRegularExpression('/login-attempts\s+found 1, would remove 1/', $out);
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM login_attempts'));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'maintenance.cleanup'"));

        [$code, $out] = $run(['cleanup', '--apply', '--tasks=login-attempts']);
        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/login-attempts\s+found 1, removed 1/', $out);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM login_attempts'));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'maintenance.cleanup'"));

        self::assertSame(1, $run(['cleanup', '--tasks=loans'])[0], 'Unknown tasks are refused');
    }

    /**
     * @param array<string, string> $config
     */
    private function cleanup(array $config = []): Cleanup
    {
        return new Cleanup($this->pdo(), $this->work . '/covers', $this->work . '/tmp', $this->work . '/backups', Cleanup::policy($config));
    }

    private function ago(int $days, int $extraSeconds = 0): string
    {
        return gmdate('Y-m-d H:i:s', $this->now - $days * 86400 - $extraSeconds);
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $out = [];
        foreach (['invitations', 'login_attempts', 'loans', 'works', 'editions', 'copies', 'users', 'audit_log'] as $table) {
            $out[$table] = $this->countRows("SELECT COUNT(*) FROM {$table}");
        }

        return $out;
    }
}
