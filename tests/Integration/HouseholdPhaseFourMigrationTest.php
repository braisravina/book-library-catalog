<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Database\Migrator;
use DigitalLibrary\Security\Role;
use PDO;

final class HouseholdPhaseFourMigrationTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        (new Migrator($this->migrationPdo(), dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        parent::tearDown();
    }

    public function testUpgradePreservesEveryInventoryFieldAndNullableCompatibility(): void
    {
        $pdo = $this->migrationPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '', $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->migrateUpTo($pdo, '2026_11_14_100003_create_invitation_households');
        $admin = $this->createUser('migration@example.org', Role::Admin);
        $household = $this->defaultHouseholdId();
        $pdo->exec("INSERT INTO locations (name, kind, household_id, created_at, updated_at) VALUES ('Existing root', 'room', {$household}, '2020-01-01', '2020-01-02')");
        $location = (int) $pdo->lastInsertId();
        $book = $this->makeBook($admin, 'Existing metadata', '9788437604947', [], [], [], $admin, $location);
        $pdo->exec("UPDATE copies SET inventory_code = 'UNCHANGED', notes = 'Keep', acquired_on = '2020-01-01', copy_condition = 'good', purchase_price_cents = 100, currency = 'EUR' WHERE id = {$book['copy_id']}");
        $pdo->exec("INSERT INTO loans (copy_id, borrower_name, loaned_on, created_at, updated_at) VALUES ({$book['copy_id']}, 'Keep borrower', '2020-01-01', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $before = [];
        foreach (['copies', 'works', 'editions', 'loans', 'households', 'users'] as $table) {
            $before[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll();
        }
        $locationsBefore = $pdo->query('SELECT * FROM locations ORDER BY id')->fetchAll();
        $migrator = new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations');
        self::assertSame(['2026_11_15_100000_create_inventory_transfers', '2026_11_15_100001_add_location_row_versions'], $migrator->migrate());
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(), $table);
        }
        $after = $pdo->query('SELECT * FROM locations ORDER BY id')->fetchAll();
        foreach ($after as &$row) { self::assertSame(1, $row['row_version']); unset($row['row_version']); }
        unset($row);
        self::assertSame($locationsBefore, $after);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM inventory_transfers'));
        self::assertSame([], $migrator->migrate());
        foreach (['copies', 'locations'] as $table) {
            $stmt = $pdo->prepare("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = 'household_id'");
            $stmt->execute(['t' => $table]);
            self::assertSame('YES', $stmt->fetchColumn());
        }
        // Legacy writers can still omit household and location-version columns.
        $legacy = $this->insertLegacyCopy($book['edition_id'], $admin);
        self::assertNull($pdo->query("SELECT household_id FROM copies WHERE id = {$legacy}")->fetchColumn());
        $pdo->exec("INSERT INTO locations (name, created_at, updated_at) VALUES ('Legacy root', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM locations WHERE household_id IS NULL AND row_version = 1"));
    }
}
