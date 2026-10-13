<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Backup\BackupCreator;
use DigitalLibrary\Backup\BackupException;
use DigitalLibrary\Backup\BackupRestorer;
use DigitalLibrary\Backup\BackupVerifier;
use DigitalLibrary\Backup\DatabaseDumper;
use DigitalLibrary\Backup\Manifest;
use DigitalLibrary\Backup\TarWriter;
use DigitalLibrary\Console\Console;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Database\Migrator;
use DigitalLibrary\Security\Role;
use PDO;

/**
 * Backup creation, verification (including corrupted and malicious
 * archives) and guarded restore. Restores only ever target the disposable
 * integration-test database and temporary directories.
 */
final class BackupTest extends IntegrationTestCase
{
    private string $work;
    private string $covers;
    private string $backups;
    private bool $schemaTouched = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = sys_get_temp_dir() . '/dla-backup-test-' . bin2hex(random_bytes(6));
        mkdir($this->work, 0700);
        $this->covers = $this->work . '/covers';
        $this->backups = $this->work . '/backups';
        mkdir($this->covers, 0750);
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->work);
        if ($this->schemaTouched) {
            // Rebuild the disposable schema for the remaining test classes.
            $pdo = $this->migrationPdo();
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
                $pdo->exec('DROP TABLE `' . $table . '`');
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        }
        parent::tearDown();
    }

    public function testBackupContainsDatabaseCoversAndManifestButNoSecrets(): void
    {
        $result = $this->creator()->create($this->backups);
        self::assertMatchesRegularExpression('#/dla-backup-\d{8}T\d{6}Z\.tar\.gz$#', $result['path']);
        self::assertSame(0600, fileperms($result['path']) & 0777);
        self::assertSame(0700, fileperms($this->backups) & 0777);
        self::assertSame($result['sha256'] . '  ' . basename($result['path']) . "\n", file_get_contents($result['path'] . '.sha256'));
        self::assertSame([basename($result['path']), basename($result['path']) . '.sha256'], array_values(array_diff(scandir($this->backups), ['.', '..'])), 'No temporary files are left behind');

        $m = $result['manifest'];
        self::assertSame(['dla-backup', 1], [$m['format'], $m['format_version']]);
        self::assertSame('2026_10_14_090000_harden_invitations', $m['application']['schema_version']);
        self::assertSame(2, $m['database']['tables']['loans']);
        self::assertSame(0, $m['database']['tables']['login_attempts'], 'Transient login attempts are not backed up');
        self::assertSame(['referenced' => 2, 'files' => 3, 'missing' => []], $m['covers']);
        self::assertSame(['database.sql', 'covers/ab/ab000000000000000000000000000001.jpg', 'covers/ab/ab000000000000000000000000000001.thumb.jpg', 'covers/cd/cd000000000000000000000000000002.jpg'], array_column($m['files'], 'path'));

        $verified = (new BackupVerifier())->verify($result['path']);
        self::assertTrue($verified['ok'], implode('; ', $verified['errors']));
        self::assertSame([], $verified['warnings']);

        $contents = gzdecode((string) file_get_contents($result['path']));
        self::assertStringContainsString("INSERT INTO `wishlist_items`", $contents);
        self::assertStringContainsString('Ñandú “quoted” \\\\ backslash', $contents);
        foreach ([(string) self::config()->get('DB_PASSWORD'), (string) self::config()->get('DB_MIGRATION_PASSWORD'), '192.0.2.99', 'DB_PASSWORD', 'PHPSESSID'] as $secret) {
            if ($secret !== '') {
                self::assertStringNotContainsString($secret, $contents, 'Secret material in the archive');
            }
        }
        self::assertStringNotContainsString('stray.txt', $contents, 'Unexpected files in the covers directory are skipped');
        self::assertStringContainsString('stray.txt', implode(' ', $result['warnings']));
    }

    public function testBackupRefusesUnsafeLocationsAndMissingCovers(): void
    {
        $creator = new BackupCreator($this->pdo(), $this->covers, $this->work);
        try {
            $creator->create($this->work . '/inside-public');
            self::fail('Wrote a backup inside the public web root');
        } catch (BackupException $e) {
            self::assertStringContainsString('public web root', $e->getMessage());
        }

        unlink($this->covers . '/cd/cd000000000000000000000000000002.jpg');
        try {
            $this->creator()->create($this->backups);
            self::fail('Backup succeeded with a referenced cover missing');
        } catch (BackupException $e) {
            self::assertStringContainsString('missing', $e->getMessage());
        }
        self::assertSame([], array_values(array_diff(scandir($this->backups), ['.', '..'])), 'A failed backup leaves nothing behind');

        $allowed = $this->creator()->create($this->backups, true);
        self::assertSame(['cd/cd000000000000000000000000000002.jpg'], $allowed['manifest']['covers']['missing']);
        $verified = (new BackupVerifier())->verify($allowed['path']);
        self::assertTrue($verified['ok']);
        self::assertStringContainsString('missing when this backup was made', implode(' ', $verified['warnings']));
    }

    public function testVerificationRejectsCorruptedAndMaliciousArchives(): void
    {
        $good = $this->creator()->create($this->backups)['path'];
        $verifier = new BackupVerifier();
        $bytes = (string) file_get_contents($good);
        $tar = (string) gzdecode($bytes);

        $cases = [];
        // Damaged data.
        $cases['flipped byte'] = [substr_replace($bytes, chr(ord($bytes[intdiv(strlen($bytes), 2)]) ^ 0xFF), intdiv(strlen($bytes), 2), 1), 'gz'];
        $cases['truncated'] = [substr($bytes, 0, intdiv(strlen($bytes), 3)), 'gz'];
        $cases['not gzip'] = [$tar, 'raw'];
        $cases['modified member'] = [gzencode(str_replace('Ñandú', 'Nandu', $tar)), 'gz'];
        // Structure attacks.
        $cases['path traversal'] = [gzencode($this->craft([['../evil.php', '0', '<?php']]) . $tar), 'gz'];
        $cases['absolute path'] = [gzencode($this->craft([['/etc/cron.d/x', '0', 'x']])), 'gz'];
        $cases['symbolic link'] = [gzencode($this->craft([['covers/link', '2', '', '/etc/passwd']])), 'gz'];
        $cases['hard link'] = [gzencode($this->craft([['covers/hard', '1', '', 'database.sql']])), 'gz'];
        $cases['directory'] = [gzencode($this->craft([['covers/dir', '5', '']])), 'gz'];
        $cases['device'] = [gzencode($this->craft([['covers/dev', '3', '']])), 'gz'];
        $cases['pax header'] = [gzencode($this->craft([['PaxHeaders/x', 'x', "30 path=../../../../etc/passwd\n"]])), 'gz'];
        $cases['duplicate member'] = [gzencode($this->craft([['database.sql', '0', 'a'], ['database.sql', '0', 'b']])), 'gz'];
        $cases['bad checksum'] = [gzencode($this->craft([['database.sql', '0', 'a']], corruptChecksum: true)), 'gz'];
        $cases['backslash name'] = [gzencode($this->craft([['covers\\..\\x', '0', 'a']])), 'gz'];
        $cases['no manifest'] = [gzencode($this->craft([['database.sql', '0', DatabaseDumper::HEADER . "\n"]])), 'gz'];
        $cases['unlisted extra file'] = [gzencode($this->insertBeforeManifest($tar, $this->craft([['covers/ab/ab00000000000000000000000000000f.jpg', '0', 'x']], end: false))), 'gz'];
        $manifest = json_decode($this->member($tar, 'manifest.json'), true);
        $newer = $manifest;
        $newer['format_version'] = 99;
        $cases['newer format'] = [gzencode($this->replaceManifest($tar, $newer)), 'gz'];
        $foreign = $manifest;
        $foreign['format'] = 'something-else';
        $cases['foreign manifest'] = [gzencode($this->replaceManifest($tar, $foreign)), 'gz'];
        $unsafe = $manifest;
        $unsafe['files'][] = ['path' => '../outside', 'size' => 1, 'sha256' => str_repeat('0', 64)];
        $cases['unsafe manifest path'] = [gzencode($this->replaceManifest($tar, $unsafe)), 'gz'];

        foreach ($cases as $label => [$data]) {
            $file = $this->work . '/case.tar.gz';
            file_put_contents($file, $data);
            $result = $verifier->verify($file);
            self::assertFalse($result['ok'], $label . ' was accepted');
            self::assertNotSame([], $result['errors'], $label);
        }

        // A sidecar checksum that does not match is an error.
        file_put_contents($good . '.sha256', str_repeat('0', 64) . '  ' . basename($good) . "\n");
        self::assertContains('Archive checksum does not match ' . basename($good) . '.sha256', $verifier->verify($good)['errors']);

        // The restorer refuses an archive that fails verification, before touching anything.
        $before = $this->fingerprint();
        file_put_contents($this->work . '/bad.tar.gz', $cases['modified member'][0]);
        try {
            $this->restorer()->restore($this->work . '/bad.tar.gz', true);
            self::fail('Restored an invalid archive');
        } catch (BackupException $e) {
            self::assertStringContainsString('nothing was changed', $e->getMessage());
        }
        self::assertSame($before, $this->fingerprint());
    }

    public function testRestoreRecreatesDatabaseAndCoversInADisposableEnvironment(): void
    {
        $this->schemaTouched = true;
        $backup = $this->creator()->create($this->backups)['path'];
        $before = $this->fingerprint();

        // Damage the data after the backup.
        $this->pdo()->exec('DELETE FROM loans');
        $this->pdo()->exec('DELETE FROM wishlist_items');
        $this->pdo()->exec("UPDATE works SET title = 'vandalised'");
        self::assertNotSame($before, $this->fingerprint());

        $target = $this->work . '/restored-covers';
        mkdir($target, 0750);
        file_put_contents($target . '/unrelated.txt', 'keep me');

        // A non-empty database is refused without --replace, and nothing changes.
        $damaged = $this->fingerprint();
        try {
            $this->restorer($target)->restore($backup, false);
            self::fail('Restored over a non-empty database without --replace');
        } catch (BackupException $e) {
            self::assertStringContainsString('not empty', $e->getMessage());
        }
        self::assertSame($damaged, $this->fingerprint());
        self::assertSame(['unrelated.txt'], array_values(array_diff(scandir($target), ['.', '..'])));

        $result = $this->restorer($target)->restore($backup, true);
        self::assertSame(2, $result['tables']['loans']);
        self::assertSame(3, $result['covers_written']);
        self::assertSame($before, $this->fingerprint(), 'Every table matches the backed-up state');
        foreach (['ab/ab000000000000000000000000000001.jpg', 'ab/ab000000000000000000000000000001.thumb.jpg', 'cd/cd000000000000000000000000000002.jpg'] as $file) {
            self::assertFileEquals($this->covers . '/' . $file, $target . '/' . $file);
        }
        self::assertSame('keep me', file_get_contents($target . '/unrelated.txt'), 'Unrelated files are left alone');
        self::assertSame([], glob(sys_get_temp_dir() . '/dla-restore-tmp/restore-*') ?: [], 'Temporary extraction is removed');

        // Generated columns and constraints still work after the restore.
        try {
            $copy = $this->countRows('SELECT copy_id FROM loans WHERE returned_on IS NULL LIMIT 1');
            $this->pdo()->exec("INSERT INTO loans (copy_id, borrower_name, loaned_on, created_at, updated_at) VALUES ({$copy}, 'x', '2026-01-01', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
            self::fail('Unique active-loan index lost in restore');
        } catch (\PDOException $e) {
            self::assertSame(1062, $e->errorInfo[1]);
        }

        // With --replace, a cover that differs is put back; identical ones are left as they are.
        file_put_contents($target . '/cd/cd000000000000000000000000000002.jpg', 'tampered');
        $again = $this->restorer($target)->restore($backup, true);
        self::assertSame([1, 2], [$again['covers_written'], $again['covers_unchanged']]);
        self::assertFileEquals($this->covers . '/cd/cd000000000000000000000000000002.jpg', $target . '/cd/cd000000000000000000000000000002.jpg');
    }

    public function testRestoreIntoAnEmptyDatabaseAndPartialFailureIsReported(): void
    {
        $this->schemaTouched = true;
        $backup = $this->creator()->create($this->backups)['path'];
        $before = $this->fingerprint();
        $pdo = $this->migrationPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $target = $this->work . '/empty-covers';
        mkdir($target . '/cd', 0750, true);

        // A differing cover file in the target is refused without --replace, before any database change.
        file_put_contents($target . '/cd/cd000000000000000000000000000002.jpg', 'someone else\'s file');
        try {
            $this->restorer($target)->restore($backup, false);
            self::fail('Overwrote a differing cover without --replace');
        } catch (BackupException $e) {
            self::assertStringContainsString('differ from the backup', $e->getMessage());
        }
        self::assertSame([], $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame("someone else's file", file_get_contents($target . '/cd/cd000000000000000000000000000002.jpg'));
        unlink($target . '/cd/cd000000000000000000000000000002.jpg');

        $this->restorer($target)->restore($backup, false);
        self::assertSame($before, $this->fingerprint());

        // A structurally valid archive whose dump fails part-way is reported as PARTIAL.
        $broken = $this->forgedArchive('broken', [
            'SET FOREIGN_KEY_CHECKS = 0;',
            "CREATE TABLE `partial_one` (\n  `id` int NOT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            'INSERT INTO `no_such_table` (`id`) VALUES (1);',
        ]);
        self::assertTrue((new BackupVerifier())->verify($broken)['ok']);
        try {
            $this->restorer($target)->restore($broken, true);
            self::fail('A failing restore claimed success');
        } catch (BackupException $e) {
            self::assertStringContainsString('PARTIALLY restored', $e->getMessage());
            self::assertStringContainsString('statement 3 of 3', $e->getMessage());
        }
    }

    public function testForgedDumpStatementsAreRefusedBeforeAnyChange(): void
    {
        $before = $this->fingerprint();
        $target = $this->work . '/forged-covers';
        mkdir($target);
        $forgeries = [
            'drop' => ['DROP TABLE `users`;'],
            'grant' => ["GRANT ALL ON *.* TO 'x'@'%';"],
            'file write' => ["SELECT 1 INTO OUTFILE '/tmp/x';"],
            'other engine' => ["CREATE TABLE `t` (\n  `id` int NOT NULL\n) ENGINE=FEDERATED CONNECTION='mysql://evil/x';"],
            'data directory' => ["CREATE TABLE `t` (\n  `id` int NOT NULL\n) ENGINE=InnoDB DATA DIRECTORY='/var/lib/x';"],
            'other set' => ['SET GLOBAL general_log = 1;'],
        ];
        foreach ($forgeries as $label => $statements) {
            $archive = $this->forgedArchive('forged', $statements);
            try {
                $this->restorer($target)->restore($archive, true);
                self::fail('Restored a forged statement: ' . $label);
            } catch (BackupException $e) {
                self::assertStringContainsString('never writes', $e->getMessage(), $label);
            }
            unlink($archive);
        }
        self::assertSame($before, $this->fingerprint(), 'Nothing was changed');
    }

    public function testConsoleGuardsAndMaintenanceMode(): void
    {
        $backup = $this->creator()->create($this->backups)['path'];
        $flag = $this->work . '/maintenance.flag';
        $db = (string) self::config()->get('DB_DATABASE');
        $run = function (array $args, array $overrides = []) use ($flag): array {
            self::$configOverrides = ['MAINTENANCE_FLAG_PATH' => $flag, 'BACKUP_PATH' => $this->backups, 'COVER_STORAGE_PATH' => $this->covers, 'TEMP_PATH' => $this->work . '/tmp'];
            $config = self::config();
            foreach ($overrides as $key => $value) {
                $config = $config->with($key, $value);
            }
            $services = new Services($config, dirname(__DIR__, 2));
            $services->set(PDO::class, $this->pdo());
            $out = fopen('php://memory', 'w+');
            $err = fopen('php://memory', 'w+');
            $code = (new Console($services, null, $out, $err))->run(array_merge(['console'], $args));
            rewind($out);
            rewind($err);

            return [$code, stream_get_contents($out), stream_get_contents($err)];
        };

        [$code, $out] = $run(['backup:verify', '--file=' . $backup]);
        self::assertSame([0, true], [$code, str_starts_with($out, 'VALID')]);
        file_put_contents($this->work . '/junk.tar.gz', 'junk');
        self::assertSame(1, $run(['backup:verify', '--file=' . $this->work . '/junk.tar.gz'])[0]);

        $refusals = [
            'no confirmation' => [['backup:restore', '--file=' . $backup, '--skip-maintenance-check'], [], 'confirm the target'],
            'wrong confirmation' => [['backup:restore', '--file=' . $backup, '--confirm=library', '--skip-maintenance-check'], [], 'confirm the target'],
            'live application' => [['backup:restore', '--file=' . $backup, '--confirm=' . $db], [], 'maintenance:on'],
            'production' => [['backup:restore', '--file=' . $backup, '--confirm=' . $db], ['APP_ENV' => 'production'], '--allow-production'],
            'production without maintenance check' => [['backup:restore', '--file=' . $backup, '--confirm=' . $db, '--allow-production', '--skip-maintenance-check'], ['APP_ENV' => 'production'], 'not allowed in production'],
            'covers in web root' => [['backup:restore', '--file=' . $backup, '--confirm=' . $db, '--skip-maintenance-check', '--covers-dir=' . dirname(__DIR__, 2) . '/public'], [], 'public web root'],
            'missing file' => [['backup:restore', '--file=/nonexistent.tar.gz', '--confirm=' . $db, '--skip-maintenance-check'], [], 'Usage'],
        ];
        $before = $this->fingerprint();
        foreach ($refusals as $label => [$args, $overrides, $message]) {
            [$code, , $err] = $run($args, $overrides);
            self::assertSame(1, $code, $label);
            self::assertStringContainsString($message, $err, $label);
        }
        self::assertSame($before, $this->fingerprint(), 'Refused restores change nothing');

        // Maintenance mode blocks the web application (except the health check).
        self::assertSame(0, $run(['maintenance:on', '--reason=restore test'])[0]);
        self::assertStringContainsString('ON since', $run(['maintenance:status'])[1]);
        $response = $this->request($this->browser(), 'GET', '/login');
        self::assertSame(503, $response->status());
        self::assertSame('300', $response->header('Retry-After'));
        self::assertSame(200, $this->request($this->browser(), 'GET', '/health')->status());
        self::assertSame(0, $run(['maintenance:off'])[0]);
        self::$configOverrides = ['MAINTENANCE_FLAG_PATH' => $flag];
        self::assertSame(200, $this->request($this->browser(), 'GET', '/login')->status());

        // backup:create through the console records an audit event without secrets.
        [$code, $out] = $run(['backup:create']);
        self::assertSame(0, $code);
        self::assertStringContainsString('verified: yes', $out);
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'backup.created'"));
    }

    /**
     * Representative Phase 4A data, with awkward characters.
     */
    private function seed(): void
    {
        $alice = $this->createUser('alice@example.org', Role::Member);
        $bob = $this->createUser('bob@example.org', Role::Member);
        $this->createUser('admin@example.org', Role::Admin);
        $a = $this->makeBook($alice, 'Ñandú “quoted” \\ backslash', '9788437604947', ['Émile 😀 O\'Brien'], [], [], $alice);
        $b = $this->makeBook($bob, 'Second', null, [], [], [], $bob);
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO loans (copy_id, borrower_user_id, loaned_on, due_on, created_at, updated_at) VALUES ({$a['copy_id']}, {$bob}, '2026-01-01', '2026-02-01', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $pdo->exec("INSERT INTO loans (copy_id, borrower_name, loaned_on, returned_on, notes, created_at, updated_at) VALUES ({$b['copy_id']}, 'Línea\nnueva', '2025-01-01', '2025-02-01', NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $pdo->exec("INSERT INTO wishlist_items (user_id, title, isbn_13, created_at, updated_at) VALUES ({$alice}, 'Wanted', '9780306406157', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $pdo->exec("INSERT INTO collections (owner_user_id, name, visibility, created_at, updated_at) VALUES ({$alice}, 'Shelf', 'private', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $pdo->exec("INSERT INTO collection_items (collection_id, edition_id, position, added_at) VALUES (LAST_INSERT_ID(), {$a['edition_id']}, 1, UTC_TIMESTAMP())");
        $pdo->exec("INSERT INTO invitations (email, role_id, token_hash, expires_at, created_at) VALUES ('new@example.org', 3, '" . hash('sha256', 'x') . "', UTC_TIMESTAMP() + INTERVAL 2 DAY, UTC_TIMESTAMP())");
        $pdo->exec("INSERT INTO login_attempts (identifier_hash, ip_address, succeeded, attempted_at) VALUES ('" . str_repeat('a', 64) . "', '192.0.2.99', 0, UTC_TIMESTAMP())");
        $pdo->exec("INSERT INTO audit_log (action, details, created_at) VALUES ('test.event', JSON_OBJECT('k', 'välue'), UTC_TIMESTAMP())");
        foreach ([['ab', '01', $a['edition_id'], true], ['cd', '02', $b['edition_id'], false]] as [$bucket, $n, $edition, $thumb]) {
            $key = $bucket . '/' . $bucket . str_repeat('0', 28) . $n . '.jpg';
            @mkdir($this->covers . '/' . $bucket, 0750);
            file_put_contents($this->covers . '/' . $key, random_bytes(2048));
            if ($thumb) {
                file_put_contents($this->covers . '/' . substr($key, 0, -4) . '.thumb.jpg', random_bytes(512));
            }
            $pdo->exec("INSERT INTO covers (edition_id, storage_key, mime_type, width, height, byte_size, sha256, created_at)
                VALUES ({$edition}, '{$key}', 'image/jpeg', 10, 10, 2048, '" . str_repeat('0', 64) . "', UTC_TIMESTAMP())");
        }
        file_put_contents($this->covers . '/stray.txt', 'not a cover');
    }

    /**
     * A structurally valid archive (matching checksums) around arbitrary statements.
     *
     * @param list<string> $statements
     */
    private function forgedArchive(string $name, array $statements): string
    {
        $archive = $this->work . '/' . $name . '.tar.gz';
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
            'database' => ['file' => 'database.sql', 'tables' => []],
            'covers' => ['referenced' => 0, 'files' => 0, 'missing' => []],
            'files' => [['path' => 'database.sql', 'size' => strlen($text), 'sha256' => $hash]],
        ]));
        $tar->close();
        unlink($dump);

        return $archive;
    }

    private function creator(): BackupCreator
    {
        return new BackupCreator($this->pdo(), $this->covers, dirname(__DIR__, 2) . '/public');
    }

    private function restorer(?string $covers = null): BackupRestorer
    {
        return new BackupRestorer($this->migrationPdo(), $covers ?? $this->work . '/restore-covers', sys_get_temp_dir() . '/dla-restore-tmp');
    }

    /**
     * Content checksum of every table (CHECKSUM TABLE) plus row counts.
     *
     * @return array<string, string>
     */
    private function fingerprint(): array
    {
        $out = [];
        foreach ($this->pdo()->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if ($table === 'login_attempts') {
                continue;
            }
            $row = $this->pdo()->query('CHECKSUM TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
            $out[(string) $table] = (string) $row[1] . '/' . $this->countRows('SELECT COUNT(*) FROM `' . $table . '`');
        }
        ksort($out);

        return $out;
    }

    /**
     * Hand-built ustar members (for malicious archives).
     *
     * @param list<array{0: string, 1: string, 2: string, 3?: string}> $members [name, type, content, link target]
     */
    private function craft(array $members, bool $corruptChecksum = false, bool $end = true): string
    {
        $out = '';
        foreach ($members as $member) {
            [$name, $type, $content] = $member;
            $header = str_pad(substr($name, 0, 100), 100, "\0") . "0000644\0" . "0000000\0" . "0000000\0"
                . str_pad(decoct(strlen($content)), 11, '0', STR_PAD_LEFT) . "\0" . "00000000000\0" . '        ' . $type
                . str_pad($member[3] ?? '', 100, "\0") . "ustar\0" . '00';
            $header = str_pad($header, 512, "\0");
            $sum = array_sum(array_map('ord', str_split($header))) + ($corruptChecksum ? 1 : 0);
            $header = substr_replace($header, str_pad(decoct($sum), 6, '0', STR_PAD_LEFT) . "\0 ", 148, 8);
            $out .= $header . $content . str_repeat("\0", (512 - strlen($content) % 512) % 512);
        }

        return $end ? $out . str_repeat("\0", 1024) : $out;
    }

    private function member(string $tar, string $wanted): string
    {
        for ($offset = 0; $offset + 512 <= strlen($tar); ) {
            $header = substr($tar, $offset, 512);
            $name = rtrim(substr($header, 0, 100), "\0");
            if ($name === '') {
                break;
            }
            $size = (int) octdec(trim(substr($header, 124, 12), " \0"));
            if ($name === $wanted) {
                return substr($tar, $offset + 512, $size);
            }
            $offset += 512 + (int) ceil($size / 512) * 512;
        }
        self::fail('No member ' . $wanted);
    }

    private function insertBeforeManifest(string $tar, string $members): string
    {
        $position = strpos($tar, "manifest.json\0");

        return substr($tar, 0, $position) . $members . substr($tar, $position);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function replaceManifest(string $tar, array $manifest): string
    {
        $position = strpos($tar, "manifest.json\0");

        return substr($tar, 0, $position) . $this->craft([['manifest.json', '0', (string) json_encode($manifest)]]);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
