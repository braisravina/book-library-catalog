<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Database\Migrator;

final class MigrationRecoveryTest extends IntegrationTestCase
{
    public function testModifiedAppliedChecksumRefusesBeforePendingDdl(): void
    {
        $pdo = $this->migrationPdo();
        $version = (string) $pdo->query('SELECT version FROM schema_migrations ORDER BY version LIMIT 1')->fetchColumn();
        $checksum = (string) $pdo->query('SELECT checksum FROM schema_migrations ORDER BY version LIMIT 1')->fetchColumn();
        $pdo->prepare('UPDATE schema_migrations SET checksum = ? WHERE version = ?')->execute([str_repeat('0', 64), $version]);
        try {
            (new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
            self::fail('A modified checksum was skipped');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('checksum/history mismatch', $e->getMessage());
            self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM schema_migration_attempts'));
        } finally {
            $pdo->prepare('UPDATE schema_migrations SET checksum = ? WHERE version = ?')->execute([$checksum, $version]);
        }
    }

    public function testPartialDdlAndHistoryInsertFailuresLeaveDurableIntentAndRefuseReplay(): void
    {
        $pdo = $this->migrationPdo();
        $directory = sys_get_temp_dir() . '/dla-migration-recovery-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        // Include all released files, preserving the real history checksums.
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') as $file) {
            copy($file, $directory . '/' . basename($file));
        }
        mkdir($directory . '/mariadb');
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/mariadb/*.sql') as $file) {
            copy($file, $directory . '/mariadb/' . basename($file));
        }
        $version = '2099_01_01_000000_interruption_fixture';
        $file = $directory . '/' . $version . '.sql';
        try {
            foreach (['second DDL', 'history insert'] as $failure) {
                $sql = 'CREATE TABLE recovery_fixture (id INT PRIMARY KEY) ENGINE=InnoDB;';
                $sql .= $failure === 'second DDL'
                    ? 'ALTER TABLE recovery_fixture ADD COLUMN extra INT; ALTER TABLE recovery_fixture ADD COLUMN extra INT;'
                    : "INSERT INTO schema_migrations (version, checksum, applied_at) VALUES ('{$version}', '" . str_repeat('0', 64) . "', UTC_TIMESTAMP());";
                file_put_contents($file, $sql);
                $migrator = new Migrator($pdo, $directory);
                try {
                    $migrator->migrate();
                    self::fail('Expected injected interruption');
                } catch (\PDOException) {
                    self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'recovery_fixture\''));
                    self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM schema_migration_attempts'));
                }
                // The exact attempt checksum is durable; a second run must
                // refuse before executing even the first CREATE again.
                if ($failure === 'history insert') {
                    // Remove only the synthetic history row injected to force
                    // the completion INSERT failure; not an actual recovery.
                    $pdo->prepare('DELETE FROM schema_migrations WHERE version = ?')->execute([$version]);
                }
                foreach ([fn () => $migrator->migrate(), fn () => $migrator->status()] as $retry) {
                    try {
                        $retry();
                        self::fail('An incomplete attempt was replayed');
                    } catch (\RuntimeException $e) {
                        self::assertStringContainsString('Incomplete migration attempt', $e->getMessage());
                    }
                }
                // Disposable fixture cleanup only. Production recovery must
                // restore a verified backup or follow an owner-reviewed repair.
                $pdo->exec('DROP TABLE recovery_fixture');
                $pdo->prepare('DELETE FROM schema_migration_attempts WHERE version = ?')->execute([$version]);
            }
            unlink($file);
            self::assertSame([], (new Migrator($pdo, $directory))->migrate());
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS recovery_fixture');
            $pdo->prepare('DELETE FROM schema_migrations WHERE version = ?')->execute([$version]);
            $pdo->prepare('DELETE FROM schema_migration_attempts WHERE version = ?')->execute([$version]);
            foreach (glob($directory . '/mariadb/*.sql') as $path) { unlink($path); }
            foreach (glob($directory . '/*.sql') as $path) { unlink($path); }
            rmdir($directory . '/mariadb');
            rmdir($directory);
        }
    }
}
