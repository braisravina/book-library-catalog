<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Database\Migrator;
use PDO;
use PDOException;

/**
 * Upgrades a database holding representative Phase 4A data (users, books,
 * loans, wishlists, collections, audit events and invitation rows) with the
 * Phase 4B migration, and checks that data and relationships survive and
 * the new constraints hold. Uses the disposable test database only.
 */
final class Phase4bMigrationTest extends IntegrationTestCase
{
    private const PHASE4B = '2026_10_14_090000_harden_invitations';

    protected function tearDown(): void
    {
        // Leave a fully migrated schema behind for the other test classes.
        (new Migrator($this->migrationPdo(), dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        parent::tearDown();
    }

    public function testPhase4aDatabaseUpgradesWithoutDataLoss(): void
    {
        $pdo = $this->migrationPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        // Schema exactly as of Phase 4A.
        $phase4a = sys_get_temp_dir() . '/dla-phase4a-migrations-' . bin2hex(random_bytes(4));
        mkdir($phase4a);
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') as $file) {
            if (basename($file, '.sql') < self::PHASE4B) {
                copy($file, $phase4a . '/' . basename($file));
            }
        }
        (new Migrator($pdo, $phase4a))->migrate();
        array_map('unlink', glob($phase4a . '/*.sql'));
        rmdir($phase4a);
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invitations' AND COLUMN_NAME = 'open_email'")->fetchColumn());

        // Representative Phase 4A data.
        $this->pdo()->exec("INSERT INTO users (email, display_name, password_hash, role_id, created_at, updated_at) VALUES
            ('admin@example.org', 'Admin', 'x', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()), ('member@example.org', 'Member', 'x', 2, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $book = $this->makeBook(2, 'Before the upgrade', '9788437604947', ['Author'], [], [], 2);
        $this->pdo()->exec("INSERT INTO loans (copy_id, borrower_user_id, loaned_on, created_at, updated_at) VALUES ({$book['copy_id']}, 1, '2026-01-01', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $this->pdo()->exec("INSERT INTO wishlist_items (user_id, title, created_at, updated_at) VALUES (2, 'Wish', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $this->pdo()->exec("INSERT INTO collections (owner_user_id, name, created_at, updated_at) VALUES (2, 'Shelf', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $this->pdo()->exec("INSERT INTO collection_items (collection_id, edition_id, position, added_at) VALUES (1, {$book['edition_id']}, 1, UTC_TIMESTAMP())");
        $this->pdo()->exec("INSERT INTO invitations (email, role_id, token_hash, invited_by, expires_at, accepted_at, accepted_user_id, revoked_at, created_at) VALUES
            ('pending@example.org', 3, '" . str_repeat('a', 64) . "', 1, UTC_TIMESTAMP() + INTERVAL 3 DAY, NULL, NULL, NULL, UTC_TIMESTAMP()),
            ('member@example.org', 2, '" . str_repeat('b', 64) . "', 1, UTC_TIMESTAMP() + INTERVAL 3 DAY, UTC_TIMESTAMP(), 2, NULL, UTC_TIMESTAMP() - INTERVAL 1 DAY),
            ('revoked@example.org', 2, '" . str_repeat('c', 64) . "', 1, UTC_TIMESTAMP() + INTERVAL 3 DAY, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $before = $this->snapshot();

        $applied = (new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        self::assertSame([self::PHASE4B], $applied);
        self::assertSame($before, $this->snapshot(), 'Existing rows are unchanged');

        // Derived column for existing rows.
        self::assertSame(['pending@example.org'], $this->pdo()->query('SELECT open_email FROM invitations WHERE open_email IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));

        // New constraints hold.
        foreach ([
            'admin role' => "INSERT INTO invitations (email, role_id, token_hash, expires_at, created_at) VALUES ('x@example.org', 1, '" . str_repeat('d', 64) . "', UTC_TIMESTAMP() + INTERVAL 1 DAY, UTC_TIMESTAMP())",
            'second open invitation' => "INSERT INTO invitations (email, role_id, token_hash, expires_at, created_at) VALUES ('pending@example.org', 2, '" . str_repeat('e', 64) . "', UTC_TIMESTAMP() + INTERVAL 1 DAY, UTC_TIMESTAMP())",
            'expiry before creation' => "INSERT INTO invitations (email, role_id, token_hash, expires_at, created_at) VALUES ('y@example.org', 2, '" . str_repeat('f', 64) . "', UTC_TIMESTAMP() - INTERVAL 1 DAY, UTC_TIMESTAMP())",
            'duplicate token' => "INSERT INTO invitations (email, role_id, token_hash, expires_at, created_at) VALUES ('z@example.org', 2, '" . str_repeat('a', 64) . "', UTC_TIMESTAMP() + INTERVAL 1 DAY, UTC_TIMESTAMP())",
        ] as $label => $sql) {
            try {
                $this->pdo()->exec($sql);
                self::fail('Accepted: ' . $label);
            } catch (PDOException $e) {
                self::assertContains($e->errorInfo[1], [1062, 3819], $label);
            }
        }
        $rules = $this->pdo()->query("SELECT CONSTRAINT_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'invitations' ORDER BY CONSTRAINT_NAME")->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame(['fk_invitations_accepted_user' => 'SET NULL', 'fk_invitations_invited_by' => 'SET NULL', 'fk_invitations_replaced_by' => 'SET NULL', 'fk_invitations_revoked_by' => 'SET NULL', 'fk_invitations_role' => 'NO ACTION'], $rules);

        // Re-running is a no-op and nothing is reported as modified.
        $migrator = new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations');
        self::assertSame([], $migrator->migrate());
        foreach ($migrator->status() as $row) {
            self::assertTrue($row['applied'] && !$row['modified'], $row['version']);
        }
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $out = [];
        foreach (['users', 'works', 'editions', 'copies', 'loans', 'wishlist_items', 'collections', 'collection_items', 'audit_log'] as $table) {
            $out[$table] = (string) $this->pdo()->query("CHECKSUM TABLE {$table}")->fetch(PDO::FETCH_NUM)[1];
        }
        $out['invitations'] = json_encode($this->pdo()->query('SELECT id, email, role_id, token_hash, invited_by, expires_at, accepted_at, accepted_user_id, revoked_at, created_at FROM invitations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));

        return $out;
    }
}
