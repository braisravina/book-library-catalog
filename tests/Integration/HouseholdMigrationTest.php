<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Database\Engine;
use DigitalLibrary\Database\Migrator;
use DigitalLibrary\Database\SqlSplitter;
use DigitalLibrary\Security\Role;
use PDO;

/**
 * Upgrades a database holding representative pre-household data with the
 * phase H1 migrations and checks that nothing is lost, that existing
 * inventory lands in the default household, and that only accounts that
 * are active and have signed in keep their access. Uses the disposable
 * test database only.
 */
final class HouseholdMigrationTest extends IntegrationTestCase
{
    private const BEFORE = '2026_10_14_090000_harden_invitations';
    private const H1 = [
        '2026_11_13_180000_create_households',
        '2026_11_13_180001_add_household_to_inventory',
        '2026_11_13_180002_backfill_default_household',
    ];

    protected function tearDown(): void
    {
        // Leave a fully migrated schema behind for the other test classes.
        (new Migrator($this->migrationPdo(), dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        parent::tearDown();
    }

    public function testExistingDatabaseUpgradesWithoutDataLoss(): void
    {
        $pdo = $this->migrationPdo();
        $this->dropEverything($pdo);
        $this->migrateUpTo($pdo, self::BEFORE);
        self::assertSame(0, $this->columnCount('copies', 'household_id'));

        // Representative data from before households existed.
        $adminId = $this->createUser('admin@example.org', Role::Admin);
        $memberId = $this->createUser('member@example.org', Role::Member);
        $neverId = $this->createUser('never@example.org', Role::Member);
        $disabledId = $this->createUser('disabled@example.org', Role::Member, self::PASSWORD, false);
        $viewerId = $this->createUser('viewer@example.org', Role::Viewer);
        $this->pdo()->exec("UPDATE users SET last_login_at = '2026-10-20 10:00:00' WHERE id IN ({$adminId}, {$memberId}, {$disabledId}, {$viewerId})");

        $this->pdo()->exec("INSERT INTO locations (parent_id, name, created_at, updated_at) VALUES (NULL, 'House', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $rootId = (int) $this->pdo()->lastInsertId();
        $this->pdo()->exec("INSERT INTO locations (parent_id, name, created_at, updated_at) VALUES ({$rootId}, 'Shelf', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $shelfId = (int) $this->pdo()->lastInsertId();

        $first = $this->makeBook($memberId, 'Before households', '9788437604947', ['Author']);
        $second = $this->makeBook($adminId, 'Another book', null, ['Other Author']);
        $copyA = $this->insertLegacyCopy($first['edition_id'], $memberId, $shelfId);
        $copyB = $this->insertLegacyCopy($first['edition_id'], $disabledId);
        $copyC = $this->insertLegacyCopy($second['edition_id'], $adminId, $rootId);
        $this->pdo()->exec("UPDATE copies SET notes = 'private note', purchase_price_cents = 1250, currency = 'EUR', inventory_code = 'INV-1' WHERE id = {$copyA}");
        $this->pdo()->exec("INSERT INTO loans (copy_id, borrower_user_id, loaned_on, created_at, updated_at) VALUES ({$copyA}, {$adminId}, '2026-01-01', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $this->pdo()->exec("INSERT INTO wishlist_items (user_id, title, created_at, updated_at) VALUES ({$memberId}, 'Wish', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $this->pdo()->exec("INSERT INTO collections (owner_user_id, name, created_at, updated_at) VALUES ({$memberId}, 'Shelf', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $this->pdo()->exec("INSERT INTO collection_items (collection_id, edition_id, position, added_at) VALUES (1, {$first['edition_id']}, 1, UTC_TIMESTAMP())");

        $untouched = $this->checksums();
        $copiesBefore = $this->rows('copies');
        $locationsBefore = $this->rows('locations');

        $applied = (new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        self::assertSame(self::H1, $applied);

        // Nothing but the new column changed.
        self::assertSame($untouched, $this->checksums(), 'Users, catalogue, loans, wishlists, collections and the audit log are unchanged');
        $default = $this->defaultHouseholdId();
        self::assertGreaterThan(0, $default);
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM households'));
        self::assertSame($copiesBefore, $this->rows('copies', ['household_id']), 'Every copy keeps its data, version and timestamps');
        self::assertSame($locationsBefore, $this->rows('locations', ['household_id']));
        self::assertSame([(string) $default], array_values(array_unique(array_map('strval', $this->pdo()->query('SELECT household_id FROM copies')->fetchAll(PDO::FETCH_COLUMN)))));
        self::assertSame(3, $this->countRows("SELECT COUNT(*) FROM copies WHERE household_id = {$default}"));
        self::assertSame(2, $this->countRows("SELECT COUNT(*) FROM locations WHERE household_id = {$default}"));

        // Existing access is preserved only for accounts that are active and have signed in.
        $members = array_map('intval', $this->pdo()->query("SELECT user_id FROM household_members WHERE household_id = {$default} ORDER BY user_id")->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame([$adminId, $memberId, $viewerId], $members);
        self::assertNotContains($neverId, $members, 'An account that never signed in is not granted access');
        self::assertNotContains($disabledId, $members, 'A disabled account is not granted access');
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM household_members WHERE added_by IS NOT NULL'));

        // Expand phase: the column is nullable and the previous application's INSERT still works.
        foreach (['copies', 'locations'] as $table) {
            self::assertSame('YES', $this->pdo()->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = 'household_id'")->fetchColumn(), $table);
        }
        $legacy = $this->insertLegacyCopy($second['edition_id'], $memberId);
        self::assertNull($this->pdo()->query("SELECT household_id FROM copies WHERE id = {$legacy}")->fetchColumn());
        $this->pdo()->exec("INSERT INTO locations (parent_id, name, created_at, updated_at) VALUES (NULL, 'Written by the previous version', UTC_TIMESTAMP(), UTC_TIMESTAMP())");

        // Accounts created afterwards belong to no household.
        $laterId = $this->createUser('later@example.org', Role::Member);
        $this->pdo()->exec("UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = {$laterId}");
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM household_members WHERE user_id = {$laterId}"));

        // A household that holds inventory or members cannot be deleted at the database level either.
        foreach (["DELETE FROM households WHERE id = {$default}"] as $sql) {
            try {
                $pdo->exec($sql);
                self::fail('The default household was deleted');
            } catch (\PDOException $e) {
                self::assertSame(1451, $e->errorInfo[1]);
            }
        }
        $rules = $this->pdo()->query("SELECT CONSTRAINT_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME IN ('fk_copies_household', 'fk_locations_household', 'fk_household_members_household', 'fk_household_members_user') ORDER BY CONSTRAINT_NAME")->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame(['fk_copies_household' => 'RESTRICT', 'fk_household_members_household' => 'RESTRICT', 'fk_household_members_user' => 'CASCADE', 'fk_locations_household' => 'RESTRICT'], $rules);

        // The backfill can be run again: it adopts rows written by the previous
        // version, duplicates nothing and never adds accounts on its own.
        $before = [$this->rows('households'), $this->rows('household_members'), $copiesBefore];
        foreach (SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . self::H1[2] . '.sql')) as $statement) {
            $pdo->exec($statement);
        }
        self::assertSame($before[0], $this->rows('households'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM copies WHERE household_id IS NULL'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM locations WHERE household_id IS NULL'));
        self::assertSame($copiesBefore, $this->rows('copies', ['household_id'], "id <> {$legacy}"), 'Assigned copies are not touched again');
        $after = array_map('intval', $this->pdo()->query('SELECT user_id FROM household_members ORDER BY user_id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame([$adminId, $memberId, $viewerId, $laterId], $after, 'Only a manual re-run adds accounts that qualify at that moment; the migration itself runs once');

        // Re-running the migrator is a no-op and nothing is reported as modified.
        $migrator = new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations');
        self::assertSame([], $migrator->migrate());
        foreach ($migrator->status() as $row) {
            self::assertTrue($row['applied'] && !$row['modified'], $row['version']);
        }
        self::assertContains(Engine::of($pdo), [Engine::MYSQL, Engine::MARIADB]);
    }

    public function testNewInstallationHasAnEmptyDefaultHousehold(): void
    {
        $pdo = $this->migrationPdo();
        $this->dropEverything($pdo);
        (new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations'))->migrate();

        self::assertSame(['Default household', 'default'], $this->pdo()->query('SELECT name, system_key FROM households')->fetch(PDO::FETCH_NUM));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM household_members'));
        // Ordinary households leave system_key NULL: any number of them may exist.
        $this->createHousehold('One');
        $this->createHousehold('Two');
        self::assertSame(2, $this->countRows('SELECT COUNT(*) FROM households WHERE system_key IS NULL'));
        try {
            $this->pdo()->exec("INSERT INTO households (name, system_key, created_at, updated_at) VALUES ('Second default', 'default', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
            self::fail('A second default household was accepted');
        } catch (\PDOException $e) {
            self::assertSame(1062, $e->errorInfo[1]);
        }
    }

    private function dropEverything(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function columnCount(string $table, string $column): int
    {
        return $this->countRows("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$column}'");
    }

    /**
     * @return array<string, string>
     */
    private function checksums(): array
    {
        $out = [];
        foreach (['users', 'works', 'editions', 'authors', 'work_authors', 'loans', 'wishlist_items', 'collections', 'collection_items', 'audit_log', 'invitations', 'covers'] as $table) {
            $out[$table] = (string) $this->pdo()->query("CHECKSUM TABLE {$table}")->fetch(PDO::FETCH_NUM)[1];
        }

        return $out;
    }

    /**
     * Every row of a table, without the named columns.
     *
     * @param list<string> $without
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, array $without = [], string $where = '1 = 1'): array
    {
        $key = in_array($table, ['household_members'], true) ? 'household_id, user_id' : 'id';
        $rows = $this->pdo()->query("SELECT * FROM {$table} WHERE {$where} ORDER BY {$key}")->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): array => array_diff_key($row, array_flip($without)), $rows);
    }
}
