<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Backup\BackupCreator;
use DigitalLibrary\Backup\BackupException;
use DigitalLibrary\Backup\BackupRestorer;
use DigitalLibrary\Backup\DatabaseDumper;
use DigitalLibrary\Backup\TarWriter;
use DigitalLibrary\Console\Console;
use DigitalLibrary\Console\OperationLock;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Database\Migrator;
use DigitalLibrary\Security\Role;
use PDO;

/**
 * Phase 5A Batch 6: disaster recovery end to end, failed-restore handling
 * (safety backup, incomplete-restore marker), operational role separation,
 * operation locking, consistency during concurrent changes, and space/
 * write failures.
 *
 * Isolation: everything runs against the disposable integration-test
 * database (TestDatabaseGuard, dedicated test accounts) and private
 * temporary directories. "Disaster" means dropping the test database's
 * tables and deleting the temporary covers directory; the schema is rebuilt
 * from the migrations afterwards. A separate restore database would need
 * CREATE DATABASE rights the test accounts deliberately lack.
 */
final class BackupRecoveryTest extends IntegrationTestCase
{
    private string $work;
    private string $covers;
    private string $backups;
    private string $flag;
    private bool $schemaTouched = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = sys_get_temp_dir() . '/dla-recovery-test-' . bin2hex(random_bytes(6));
        mkdir($this->work, 0700);
        $this->covers = $this->work . '/covers';
        $this->backups = $this->work . '/backups';
        $this->flag = $this->work . '/state/maintenance.flag';
        mkdir($this->covers, 0750);
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->work);
        if ($this->schemaTouched) {
            $pdo = $this->migrationPdo();
            $this->dropAllTables();
            (new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        }
        parent::tearDown();
    }

    public function testDisasterRecoveryEndToEnd(): void
    {
        $this->schemaTouched = true;
        $covers = $this->coverChecksums($this->covers);
        self::assertCount(5, $covers, 'Representative cover files and thumbnails');

        // 1-2. Back up and verify (as an operator would, through the console).
        [$code, $out, $err] = $this->console(['backup:create']);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('verified: yes', $out);
        // The backup.created audit event is written after the snapshot.
        $records = $this->fingerprint(['audit_log']);
        $audit = $this->auditActions();
        $archive = $this->onlyArchive();
        self::assertSame(0, $this->console(['backup:verify', '--file=' . $archive])[0]);

        // 3. Disaster: every table and every cover file is lost.
        $this->dropAllTables();
        $this->removeTree($this->covers);
        mkdir($this->covers, 0750);

        // 4. Restore (maintenance mode first), into the empty database.
        self::assertSame(0, $this->console(['maintenance:on', '--reason=disaster recovery test'])[0]);
        [$code, $out, $err] = $this->console(['backup:restore', '--file=' . $archive, '--confirm=' . $this->database()]);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('RESTORED', $out);
        self::assertStringNotContainsString('Safety backup', $out, 'An empty target needs no safety backup');

        // 5. Records, cover checksums and referential integrity match.
        self::assertSame($records, $this->fingerprint(['audit_log']), 'Every table matches the backed-up state');
        self::assertSame(array_merge(array_values(array_diff($audit, ['backup.created'])), ['backup.restored']), $this->auditActions(), 'Audit log as backed up, plus the restore event');
        self::assertSame($covers, $this->coverChecksums($this->covers), 'Every cover file is byte-identical');
        self::assertSame([], BackupRestorer::foreignKeyViolations($this->pdo()));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM loans l LEFT JOIN copies c ON c.id = l.copy_id WHERE c.id IS NULL'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM covers v LEFT JOIN editions e ON e.id = v.edition_id WHERE e.id IS NULL'));
        foreach ($this->pdo()->query('SELECT storage_key FROM covers')->fetchAll(PDO::FETCH_COLUMN) as $key) {
            self::assertFileExists($this->covers . '/' . $key);
        }

        // 6. Back in service: the marker is gone, the site and sign-in work.
        self::assertStringNotContainsString('INCOMPLETE', $this->console(['maintenance:status'])[1]);
        self::assertSame(0, $this->console(['maintenance:off'])[0]);
        self::$configOverrides = ['MAINTENANCE_FLAG_PATH' => $this->flag];
        self::assertSame(303, $this->login($this->browser(), 'alice@example.org')->status());
        self::assertSame([], glob($this->work . '/tmp/restore-*') ?: [], 'Extraction directories are removed');
    }

    public function testFailedRestoreKeepsTheSiteDownAndRollsBackFromTheSafetyBackup(): void
    {
        $this->schemaTouched = true;
        $this->pdo()->exec("UPDATE works SET title = CONCAT(title, ' (current state)')");
        $current = $this->fingerprint(['audit_log']);
        $audit = $this->auditActions();
        $currentCovers = $this->coverChecksums($this->covers);
        $broken = $this->forgedArchive('broken', [
            'SET FOREIGN_KEY_CHECKS = 0;',
            "CREATE TABLE `partial_one` (\n  `id` int NOT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            'INSERT INTO `no_such_table` (`id`) VALUES (1);',
        ]);

        self::assertSame(0, $this->console(['maintenance:on'])[0]);
        [$code, , $err] = $this->console(['backup:restore', '--file=' . $broken, '--confirm=' . $this->database(), '--replace']);
        self::assertSame(2, $code, 'A partial restore is never reported as success');
        self::assertStringContainsString('PARTIALLY restored', $err);
        self::assertMatchesRegularExpression('#Roll back with: php bin/console backup:restore --file=(\S+dla-backup-\S+\.tar\.gz) --confirm=#', $err);
        preg_match('#--file=(\S+dla-backup-\S+\.tar\.gz)#', $err, $m);
        $safety = $m[1];
        self::assertFileExists($safety);

        // The site stays down: even removing the flag by hand does not reopen it.
        self::assertStringContainsString('INCOMPLETE RESTORE: broken.tar.gz into ' . $this->database(), $this->console(['maintenance:status'])[1]);
        [$code, , $err] = $this->console(['maintenance:off']);
        self::assertSame(1, $code);
        self::assertStringContainsString('did not complete', $err);
        unlink($this->flag);
        self::$configOverrides = ['MAINTENANCE_FLAG_PATH' => $this->flag];
        self::assertSame(503, $this->request($this->browser(), 'GET', '/login')->status());
        self::assertSame(0, $this->console(['maintenance:on'])[0]);

        // Roll back to the state before the failed restore.
        [$code, $out, $err] = $this->console(['backup:restore', '--file=' . $safety, '--confirm=' . $this->database(), '--replace', '--no-safety-backup']);
        self::assertSame(0, $code, $err);
        self::assertSame($current, $this->fingerprint(['audit_log']), 'Rolled back to the pre-restore state');
        self::assertSame(array_merge($audit, ['backup.restored']), $this->auditActions());
        self::assertSame($currentCovers, $this->coverChecksums($this->covers));
        self::assertStringNotContainsString('INCOMPLETE', $this->console(['maintenance:status'])[1]);
        self::assertSame(0, $this->console(['maintenance:off'])[0]);
    }

    public function testAnUnverifiedArchiveIsNeverPublished(): void
    {
        $before = $this->fingerprint();
        foreach ([
            'corrupted byte' => static function (string $tmp): void {
                $bytes = (string) file_get_contents($tmp);
                $middle = intdiv(strlen($bytes), 2);
                file_put_contents($tmp, substr_replace($bytes, chr(ord($bytes[$middle]) ^ 0xFF), $middle, 1));
            },
            'truncated' => static function (string $tmp): void {
                $handle = fopen($tmp, 'r+');
                ftruncate($handle, intdiv((int) filesize($tmp), 2));
                fclose($handle);
            },
        ] as $label => $sabotage) {
            $sabotaged = [];
            try {
                // The real verifier runs on the real temporary archive, which is
                // damaged at the last moment before verification.
                $this->creator()->create($this->backups, false, function (string $line) use ($sabotage, &$sabotaged): void {
                    if (str_starts_with($line, 'Verifying the archive')) {
                        $sabotaged = glob($this->backups . '/.tmp-dla-*.tar.gz') ?: [];
                        foreach ($sabotaged as $tmp) {
                            $sabotage($tmp);
                        }
                    }
                });
                self::fail($label . ': a damaged archive was published');
            } catch (BackupException $e) {
                self::assertStringContainsString('failed verification', $e->getMessage(), $label);
            }
            self::assertCount(1, $sabotaged, $label . ': the archive was damaged before verification');
            self::assertSame([], array_values(array_diff(scandir($this->backups), ['.', '..'])), $label . ': no final archive, no .sha256 and no temporary file remains');
        }
        self::assertSame($before, $this->fingerprint(), 'A backup never changes the database');

        // Cleanup sees nothing to protect or to remove, and a later backup works.
        self::assertSame(0, $this->console(['backup:create'])[0]);
        self::assertCount(1, glob($this->backups . '/dla-backup-*.tar.gz.sha256') ?: []);
    }

    public function testTheIncompleteRestoreMarkerCannotBeBypassed(): void
    {
        $mode = new \DigitalLibrary\Core\MaintenanceMode($this->flag);
        $mode->markRestoreIncomplete(['file' => 'x.tar.gz', 'database' => $this->database(), 'started_at' => '2026-11-11T10:00:00Z', 'safety_backup' => '']);
        self::assertFileDoesNotExist($this->flag, 'Only the marker; no maintenance flag');

        // Ordinary requests: everything but the health probe gets 503, with or without a session.
        self::$configOverrides = ['MAINTENANCE_FLAG_PATH' => $this->flag];
        $browser = $this->browser();
        foreach ([['GET', '/'], ['GET', '/login'], ['GET', '/books'], ['GET', '/invite/redeem'], ['GET', '/admin/users'], ['GET', '/export/catalogue.json'], ['POST', '/login'], ['POST', '/invite/redeem']] as [$method, $path]) {
            self::assertSame(503, $this->request($browser, $method, $path, ['email' => 'alice@example.org', 'password' => self::PASSWORD])->status(), "{$method} {$path}");
        }
        self::assertSame(200, $this->request($browser, 'GET', '/health')->status(), 'Only the container probe answers');
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.login'"), 'No sign-in happened');

        // Ordinary maintenance commands do not clear it.
        $commands = [
            ['maintenance:off'], ['maintenance:on'], ['maintenance:off'],
            ['maintenance:off', '--acknowledge-incomplete-restore'],
            ['maintenance:off', '--acknowledge-incomplete-restore=library'],
            ['maintenance:off', '--acknowledge-incomplete-restore=' . strtoupper($this->database())],
            ['cleanup', '--apply'], ['backup:create'],
        ];
        foreach ($commands as $command) {
            $this->console($command);
            self::assertNotNull($mode->restoreIncomplete(), implode(' ', $command) . ' cleared the marker');
            self::assertTrue($mode->isActive(), implode(' ', $command));
        }
        self::assertStringContainsString('INCOMPLETE RESTORE: x.tar.gz into ' . $this->database(), $this->console(['maintenance:status'])[1]);
        // A restore into another database (here refused: no rights) leaves it alone too.
        $archive = (string) (glob($this->backups . '/dla-backup-*.tar.gz')[0] ?? '');
        self::assertNotSame(0, $this->console(['backup:restore', '--file=' . $archive, '--database=other_db', '--confirm=other_db', '--replace'])[0]);
        self::assertNotNull($mode->restoreIncomplete());
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'maintenance.restore_marker_acknowledged'"));

        // Explicit acknowledgement: exact database name; audited first; never reported as a recovery.
        [$code, $out, $err] = $this->console(['maintenance:off', '--acknowledge-incomplete-restore=' . $this->database()]);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('the restore did NOT complete', $err);
        self::assertStringContainsString('this is not a successful recovery', $err);
        self::assertStringNotContainsString('RESTORED', $out . $err);
        self::assertNull($mode->restoreIncomplete());
        self::assertFalse($mode->isActive());
        $details = json_decode((string) $this->pdo()->query("SELECT details FROM audit_log WHERE action = 'maintenance.restore_marker_acknowledged'")->fetchColumn(), true);
        $expected = ['database' => $this->database(), 'file' => 'x.tar.gz', 'outcome' => 'restore not completed; database served as found, without verification', 'started_at' => '2026-11-11T10:00:00Z'];
        ksort($details);
        self::assertSame($expected, $details, 'Audited with what was acknowledged');
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'backup.restored'"), 'Nothing claims a restore succeeded');

        // If the acknowledgement cannot be audited, it is refused and the marker stays.
        $mode->markRestoreIncomplete(['file' => 'y.tar.gz', 'database' => $this->database(), 'started_at' => '2026-11-11T11:00:00Z']);
        $this->schemaTouched = true;
        $this->migrationPdo()->exec('DROP TABLE audit_log');
        [$code, , $err] = $this->console(['maintenance:off', '--acknowledge-incomplete-restore=' . $this->database()]);
        self::assertSame(1, $code);
        self::assertStringContainsString('could not be recorded in the audit log', $err);
        self::assertNotNull($mode->restoreIncomplete());
        self::assertTrue($mode->isActive());
    }

    public function testFailedSafetyBackupChangesNothing(): void
    {
        $archive = $this->creator()->create($this->backups)['path'];
        $before = $this->fingerprint();
        unlink($archive . '.sha256');
        rename($archive, $this->work . '/source.tar.gz');
        // The safety backup cannot be written: the backup directory is unusable.
        $this->removeTree($this->backups);
        file_put_contents($this->backups, 'not a directory');
        self::assertSame(0, $this->console(['maintenance:on'])[0]);
        [$code, , $err] = $this->console(['backup:restore', '--file=' . $this->work . '/source.tar.gz', '--confirm=' . $this->database(), '--replace']);
        self::assertSame(1, $code);
        self::assertStringContainsString('safety backup of the current target failed, so nothing was changed', $err);
        self::assertSame($before, $this->fingerprint());
        self::assertStringNotContainsString('INCOMPLETE', $this->console(['maintenance:status'])[1], 'No marker: nothing was changed');
    }

    public function testOperationsCommandsAreRefusedOutsideTheOpsRole(): void
    {
        $archive = $this->creator()->create($this->backups)['path'];
        $before = $this->fingerprint();
        $files = scandir($this->backups);
        $db = $this->database();
        $commands = [
            ['migrate'], ['user:create', '--email=new@example.org', '--name=New'], ['backup:create'],
            ['backup:restore', '--file=' . $archive, '--confirm=' . $db, '--replace', '--skip-maintenance-check'],
            ['maintenance:on'], ['maintenance:off'], ['cleanup', '--apply'],
        ];
        self::assertSame(Console::OPS_COMMANDS, array_map(static fn (array $c): string => $c[0], $commands), 'Every ops command is covered');
        foreach ([
            'web role (testing)' => [['APP_ROLE' => 'web'], 'cannot run in the web container'],
            'web role (production)' => [['APP_ROLE' => 'web', 'APP_ENV' => 'production'], 'cannot run in the web container'],
            'production, no role' => [['APP_ENV' => 'production'], 'requires APP_ROLE=ops in production'],
            'unset APP_ENV, no role' => [['APP_ENV' => ''], 'requires APP_ROLE=ops in production'],
            'unknown role' => [['APP_ROLE' => 'admin'], 'unrecognised value'],
        ] as $label => [$overrides, $message]) {
            foreach ($commands as $command) {
                [$code, $out, $err] = $this->console($command, $overrides);
                self::assertSame(1, $code, "{$label}: {$command[0]}");
                self::assertStringContainsString($message, $err, "{$label}: {$command[0]}");
                self::assertSame('', $out, "{$label}: {$command[0]} did nothing");
            }
        }
        self::assertSame($before, $this->fingerprint(), 'Refused commands changed nothing');
        self::assertSame($files, scandir($this->backups), 'No backup written or removed');
        self::assertFileDoesNotExist($this->flag);
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM users WHERE email = 'new@example.org'"));

        // Read-only commands stay available to the web role.
        foreach ([['backup:verify', '--file=' . $archive], ['maintenance:status'], ['migrate:status']] as $command) {
            self::assertSame(0, $this->console($command, ['APP_ROLE' => 'web'])[0], $command[0]);
        }
        // The ops role may run them in production.
        [$code, , $err] = $this->console(['backup:create'], ['APP_ROLE' => 'ops', 'APP_ENV' => 'production']);
        self::assertSame(0, $code, $err);
    }

    public function testOperationsDoNotOverlapAndStaleLocksRecover(): void
    {
        $tmp = $this->work . '/tmp';
        $lock = OperationLock::acquire($tmp, 'backup:restore');
        foreach ([['backup:create'], ['cleanup', '--apply'], ['migrate'], ['maintenance:off'], ['backup:restore', '--file=' . __FILE__, '--confirm=x']] as $command) {
            [$code, , $err] = $this->console($command);
            self::assertSame(1, $code, $command[0]);
            self::assertStringContainsString('Another operation is running (backup:restore, since ', $err, $command[0]);
        }
        self::assertSame(0, $this->console(['maintenance:status'])[0], 'Read-only commands do not wait for the lock');
        self::assertSame([], glob($this->backups . '/dla-backup-*') ?: [], 'No backup was written while locked');
        $lock->release();
        self::assertSame(0, $this->console(['backup:create'])[0]);
        self::assertSame(0600, fileperms($tmp . '/' . OperationLock::FILE) & 0777);

        // A holder that dies (SIGKILL, container stop) never leaves a stale lock.
        $child = proc_open(
            [PHP_BINARY, '-r', 'require $argv[1]; $l = DigitalLibrary\Console\OperationLock::acquire($argv[2], "cleanup"); echo "locked\n"; sleep(60);', dirname(__DIR__, 2) . '/vendor/autoload.php', $tmp],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertIsResource($child);
        self::assertSame("locked\n", fgets($pipes[1]));
        self::assertStringContainsString('(cleanup, since', $this->console(['backup:create'])[2]);
        proc_terminate($child, 9);
        fclose($pipes[1]);
        proc_close($child);
        self::assertSame(0, $this->console(['backup:create'])[0], 'The lock died with its holder');
    }

    public function testBackupIsAConsistentSnapshotWhileDataAndCoversChange(): void
    {
        // Rows written by another session while the dump runs are not included.
        $works = $this->countRows('SELECT COUNT(*) FROM works');
        $writer = $this->migrationPdo();
        HookStream::$onFirstWrite = static function () use ($writer): void {
            $writer->exec("INSERT INTO works (title, created_by, created_at, updated_at) SELECT 'Written during the dump', id, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM users ORDER BY id LIMIT 1");
        };
        stream_wrapper_register('dlahook', HookStream::class);
        try {
            $out = fopen('dlahook://dump', 'w');
            $result = (new DatabaseDumper($this->pdo()))->dump($out);
            fclose($out);
        } finally {
            stream_wrapper_unregister('dlahook');
        }
        self::assertSame($works + 1, $this->countRows('SELECT COUNT(*) FROM works'), 'The concurrent write happened');
        self::assertSame($works, $result['tables']['works'], 'The dump is the snapshot from before it');
        self::assertStringNotContainsString('Written during the dump', HookStream::$buffer);

        // A referenced cover deleted while the archive is written: the backup fails and leaves nothing.
        $creator = $this->creator();
        try {
            $creator->create($this->backups, false, function (string $line): void {
                if (str_starts_with($line, 'Writing archive')) {
                    unlink($this->covers . '/cd/cd000000000000000000000000000002.jpg');
                }
            });
            self::fail('A backup succeeded after a cover vanished');
        } catch (BackupException $e) {
            self::assertStringContainsString('Cannot read', $e->getMessage());
        }
        self::assertSame([], array_values(array_diff(scandir($this->backups), ['.', '..'])), 'No archive, checksum or temporary file is left');

        // A cover added while the archive is written is simply not included.
        file_put_contents($this->covers . '/cd/cd000000000000000000000000000002.jpg', random_bytes(2048));
        $late = 'ef/ef000000000000000000000000000009.jpg';
        $result = $this->creator()->create($this->backups, false, function (string $line) use ($late): void {
            if (str_starts_with($line, 'Writing archive')) {
                @mkdir($this->covers . '/ef', 0750);
                file_put_contents($this->covers . '/' . $late, 'late');
            }
        });
        self::assertNotContains('covers/' . $late, array_column($result['manifest']['files'], 'path'));
    }

    public function testSpaceAndWriteFailuresLeaveNothingBehind(): void
    {
        $tiny = static fn (string $dir): float => 1024.0;
        try {
            (new BackupCreator($this->pdo(), $this->covers, dirname(__DIR__, 2) . '/public', $tiny))->create($this->backups);
            self::fail('Backup ignored a full disk');
        } catch (BackupException $e) {
            self::assertStringContainsString('Not enough free space', $e->getMessage());
        }
        self::assertSame([], array_values(array_diff(scandir($this->backups), ['.', '..'])));

        $archive = $this->creator()->create($this->backups)['path'];
        $before = $this->fingerprint();
        foreach ([[$tiny, 'temporary directory'], [static fn (string $dir): float => str_contains($dir, 'restore-') ? 1e12 : 1024.0, 'covers directory']] as [$space, $where]) {
            try {
                (new BackupRestorer($this->migrationPdo(), $this->work . '/target-covers-' . bin2hex(random_bytes(3)), $this->work . '/tmp', $space))->restore($archive, true);
                self::fail('Restore ignored a full disk: ' . $where);
            } catch (BackupException $e) {
                self::assertStringContainsString('Not enough free space in the ' . $where, $e->getMessage());
                self::assertStringContainsString('Nothing was changed', $e->getMessage());
            }
        }
        self::assertSame($before, $this->fingerprint());

        if (is_writable('/dev/full')) {
            try {
                $tar = new TarWriter('/dev/full');
                $tar->addString('database.sql', random_bytes(4 << 20));
                $tar->close();
                self::fail('Writing to a full device succeeded');
            } catch (BackupException $e) {
                self::assertMatchesRegularExpression('/Write error|Cannot finish archive/', $e->getMessage());
            }
        }
    }

    public function testRestoreAcceptsOnlyLiteralDataAndConsistentForeignKeys(): void
    {
        $before = $this->fingerprint();
        $target = $this->work . '/forged-covers';
        mkdir($target);
        foreach ([
            'subquery' => 'INSERT INTO `settings` (`setting_key`, `value`) VALUES ((SELECT password_hash FROM users LIMIT 1), 1);',
            'function' => 'INSERT INTO `settings` (`setting_key`, `value`) VALUES (SLEEP(5), 1);',
            'variable' => 'INSERT INTO `settings` (`setting_key`, `value`) VALUES (@@hostname, 1);',
            'comment' => "INSERT INTO `settings` (`setting_key`, `value`) VALUES ('a', 1) /* x */;",
            'column count' => "INSERT INTO `settings` (`setting_key`, `value`) VALUES ('a');",
        ] as $label => $sql) {
            try {
                (new BackupRestorer($this->migrationPdo(), $target, $this->work . '/tmp'))->restore($this->forgedArchive('literal', ['SET FOREIGN_KEY_CHECKS = 0;', $sql]), true);
                self::fail('Restored a non-literal INSERT: ' . $label);
            } catch (BackupException $e) {
                self::assertStringContainsString('never writes', $e->getMessage(), $label);
            }
        }
        self::assertSame($before, $this->fingerprint(), 'Refused before any change');

        $this->schemaTouched = true;
        $orphans = $this->forgedArchive('orphans', [
            'SET FOREIGN_KEY_CHECKS = 0;',
            "CREATE TABLE `child` (\n  `id` int NOT NULL,\n  `parent_id` int DEFAULT NULL,\n  PRIMARY KEY (`id`),\n  KEY `parent_id` (`parent_id`),\n  CONSTRAINT `fk_child_parent` FOREIGN KEY (`parent_id`) REFERENCES `parent` (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            "CREATE TABLE `parent` (\n  `id` int NOT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            "INSERT INTO `parent` (`id`) VALUES (1);",
            "INSERT INTO `child` (`id`, `parent_id`) VALUES (1, 1),\n(2, NULL),\n(3, 99);",
            'SET FOREIGN_KEY_CHECKS = 1;',
        ], ['child' => 3, 'parent' => 1]);
        try {
            (new BackupRestorer($this->migrationPdo(), $target, $this->work . '/tmp'))->restore($orphans, true);
            self::fail('Restored rows that violate a foreign key');
        } catch (BackupException $e) {
            self::assertStringContainsString('INCOMPLETE: foreign-key violations', $e->getMessage());
            self::assertStringContainsString('child.fk_child_parent (1 rows)', $e->getMessage(), 'NULL keys are not violations');
        }
    }

    /**
     * Representative data: users of every role, books with authors, copies,
     * loans (active and returned), a wishlist, a collection, an invitation,
     * audit events, and cover files with thumbnails.
     */
    private function seed(): void
    {
        $alice = $this->createUser('alice@example.org', Role::Member);
        $bob = $this->createUser('bob@example.org', Role::Viewer);
        $this->createUser('admin@example.org', Role::Admin);
        $pdo = $this->pdo();
        $books = [];
        foreach ([['Ñandú “quoted” \\ backslash', '9788437604947', ['Émile O\'Brien 😀']], ['Second', null, ['Ana García']], ['Third', '9780306406157', []]] as $i => [$title, $isbn, $authors]) {
            $books[] = $this->makeBook($alice, $title, $isbn, $authors, ['year' => 2000 + $i], [], $i === 1 ? $bob : $alice);
        }
        $pdo->exec("INSERT INTO loans (copy_id, borrower_user_id, loaned_on, due_on, created_at, updated_at) VALUES ({$books[0]['copy_id']}, {$bob}, '2026-01-01', '2026-02-01', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $pdo->exec("INSERT INTO loans (copy_id, borrower_name, loaned_on, returned_on, created_at, updated_at) VALUES ({$books[1]['copy_id']}, 'Línea\nnueva', '2025-01-01', '2025-02-01', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $pdo->exec("INSERT INTO wishlist_items (user_id, title, created_at, updated_at) VALUES ({$alice}, 'Wanted', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $pdo->exec("INSERT INTO collections (owner_user_id, name, visibility, created_at, updated_at) VALUES ({$alice}, 'Shelf', 'shared', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $pdo->exec("INSERT INTO collection_items (collection_id, edition_id, position, added_at) VALUES (LAST_INSERT_ID(), {$books[2]['edition_id']}, 1, UTC_TIMESTAMP())");
        $pdo->exec("INSERT INTO invitations (email, role_id, token_hash, expires_at, created_at) VALUES ('new@example.org', 3, '" . hash('sha256', 'x') . "', UTC_TIMESTAMP() + INTERVAL 2 DAY, UTC_TIMESTAMP())");
        $pdo->exec("INSERT INTO audit_log (action, details, created_at) VALUES ('test.event', JSON_OBJECT('k', 'välue'), UTC_TIMESTAMP())");
        foreach ([['ab', '01', 0, true], ['cd', '02', 1, false], ['0f', '03', 2, true]] as [$bucket, $n, $book, $thumb]) {
            $key = $bucket . '/' . $bucket . str_repeat('0', 28) . $n . '.jpg';
            @mkdir($this->covers . '/' . $bucket, 0750);
            file_put_contents($this->covers . '/' . $key, random_bytes(4096));
            if ($thumb) {
                file_put_contents($this->covers . '/' . substr($key, 0, -4) . '.thumb.jpg', random_bytes(512));
            }
            $pdo->exec("INSERT INTO covers (edition_id, storage_key, mime_type, width, height, byte_size, sha256, created_at)
                VALUES ({$books[$book]['edition_id']}, '{$key}', 'image/jpeg', 10, 10, 4096, '" . str_repeat('0', 64) . "', UTC_TIMESTAMP())");
        }
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $overrides
     *
     * @return array{int, string, string}
     */
    private function console(array $args, array $overrides = []): array
    {
        self::$configOverrides = ['MAINTENANCE_FLAG_PATH' => $this->flag, 'BACKUP_PATH' => $this->backups, 'COVER_STORAGE_PATH' => $this->covers, 'TEMP_PATH' => $this->work . '/tmp'];
        $config = self::config();
        foreach ($overrides as $key => $value) {
            $config = $config->with($key, $value);
        }
        $services = new Services($config, dirname(__DIR__, 2));
        $services->set(PDO::class, $this->pdo());
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $stdin = fopen('php://memory', 'r');
        $code = (new Console($services, $stdin, $out, $err))->run(array_merge(['console'], $args));
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    /**
     * @return list<string>
     */
    private function auditActions(): array
    {
        return array_map('strval', $this->migrationPdo()->query('SELECT action FROM audit_log ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    private function database(): string
    {
        return (string) self::config()->get('DB_DATABASE');
    }

    private function creator(): BackupCreator
    {
        return new BackupCreator($this->pdo(), $this->covers, dirname(__DIR__, 2) . '/public');
    }

    private function onlyArchive(): string
    {
        $archives = glob($this->backups . '/dla-backup-*.tar.gz') ?: [];
        self::assertCount(1, $archives);

        return $archives[0];
    }

    private function dropAllTables(): void
    {
        $pdo = $this->migrationPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * CHECKSUM TABLE and row count of every table (login attempts excluded:
     * deliberately not backed up).
     *
     * @param list<string> $except
     *
     * @return array<string, string>
     */
    private function fingerprint(array $except = []): array
    {
        $out = [];
        foreach ($this->migrationPdo()->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if ($table === 'login_attempts' || in_array($table, $except, true)) {
                continue;
            }
            $row = $this->migrationPdo()->query('CHECKSUM TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
            $out[(string) $table] = (string) $row[1] . '/' . $this->countRows('SELECT COUNT(*) FROM `' . $table . '`');
        }
        ksort($out);

        return $out;
    }

    /**
     * @return array<string, string> relative path => SHA-256
     */
    private function coverChecksums(string $dir): array
    {
        $sums = [];
        foreach (glob($dir . '/*/*') ?: [] as $file) {
            $sums[substr($file, strlen($dir) + 1)] = (string) hash_file('sha256', $file);
        }
        ksort($sums);

        return $sums;
    }

    /**
     * A structurally valid archive (matching checksums) around arbitrary statements.
     *
     * @param list<string>       $statements
     * @param array<string, int> $tables     row counts for the manifest
     */
    private function forgedArchive(string $name, array $statements, array $tables = []): string
    {
        $archive = $this->work . '/' . $name . '.tar.gz';
        @unlink($archive);
        $dump = $this->work . '/' . $name . '.sql';
        $text = DatabaseDumper::HEADER . "\n";
        foreach ($statements as $sql) {
            $text .= '-- dla:statement ' . strlen($sql) . "\n" . $sql . "\n";
        }
        $text .= '-- dla:end ' . count($statements) . "\n";
        file_put_contents($dump, $text);
        $tar = new TarWriter($archive);
        $hash = $tar->addFile('database.sql', $dump);
        $tar->addString('manifest.json', (string) json_encode([
            'format' => 'dla-backup', 'format_version' => 1, 'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'application' => ['schema_version' => 'x', 'migrations' => []],
            'database' => ['file' => 'database.sql', 'server_version' => (string) $this->pdo()->query('SELECT VERSION()')->fetchColumn(), 'tables' => (object) $tables],
            'covers' => ['referenced' => 0, 'files' => 0, 'missing' => []],
            'files' => [['path' => 'database.sql', 'size' => strlen($text), 'sha256' => $hash]],
        ]));
        $tar->close();
        unlink($dump);

        return $archive;
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}

/**
 * Write-only stream that runs a callback on its first write (to change the
 * database while a dump is in progress) and keeps what was written.
 */
final class HookStream
{
    /** @var resource|null */
    public $context;
    public static string $buffer = '';
    /** @var (\Closure(): void)|null */
    public static ?\Closure $onFirstWrite = null;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        self::$buffer = '';

        return true;
    }

    public function stream_write(string $data): int
    {
        if (self::$onFirstWrite !== null) {
            $hook = self::$onFirstWrite;
            self::$onFirstWrite = null;
            $hook();
        }
        self::$buffer .= $data;

        return strlen($data);
    }

    public function stream_close(): void
    {
    }
}
