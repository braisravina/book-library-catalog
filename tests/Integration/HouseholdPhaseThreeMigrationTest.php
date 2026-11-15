<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Database\Migrator;
use DigitalLibrary\Database\SqlSplitter;
use DigitalLibrary\Security\Role;
use PDO;

/**
 * Upgrades a database as phase H2 left it (the schema production runs
 * once H1/H2 are deployed) with the phase H3 migrations: inventory codes
 * become unique per household, shared collections get a household and
 * invitations can carry household assignments. Nothing existing is lost
 * or rewritten, and the previous application version keeps working on
 * the new schema. Uses the disposable test database only, on whichever
 * engine the suite runs against.
 */
final class HouseholdPhaseThreeMigrationTest extends IntegrationTestCase
{
    private const BEFORE = '2026_11_13_180002_backfill_default_household';
    private const H3 = [
        '2026_11_14_100000_scope_inventory_codes_to_households',
        '2026_11_14_100001_add_household_to_collections',
        '2026_11_14_100002_backfill_shared_collection_households',
        '2026_11_14_100003_create_invitation_households',
    ];

    protected function tearDown(): void
    {
        // Leave a fully migrated schema behind for the other test classes.
        (new Migrator($this->migrationPdo(), dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        parent::tearDown();
    }

    public function testPhaseTwoDatabaseUpgradesWithoutDataLoss(): void
    {
        $pdo = $this->migrationPdo();
        $this->dropEverything($pdo);
        $this->migrateUpTo($pdo, self::BEFORE);
        self::assertSame(0, $this->columnCount('copies', 'inventory_scope_id'));
        self::assertSame(0, $this->columnCount('collections', 'household_id'));
        self::assertSame(['uq_copies_inventory_code'], $this->uniqueIndexes('copies'));

        // Data as an installation with households (H1/H2) holds it.
        $default = $this->defaultHouseholdId();
        $adminId = $this->createUser('admin@example.org', Role::Admin);
        $memberId = $this->createUser('member@example.org', Role::Member);
        $other = $this->createHousehold('Second household', [$memberId]);
        $book = $this->makeBook($adminId, 'Before phase three', '9788437604947', ['Author']);
        $copy = function (?int $household, ?string $code) use ($book, $memberId): int {
            $this->pdo()->prepare("INSERT INTO copies (edition_id, owner_user_id, household_id, inventory_code, status, notes, created_at, updated_at)
                VALUES (:e, :o, :h, :c, 'available', 'kept', '2026-11-01 10:00:00', '2026-11-02 11:00:00')")->execute(['e' => $book['edition_id'], 'o' => $memberId, 'h' => $household, 'c' => $code]);

            return (int) $this->pdo()->lastInsertId();
        };
        $inDefault = $copy($default, 'INV-1');
        $inOther = $copy($other, 'INV-2');
        $unassigned = $copy(null, 'INV-3');
        $copy($default, null);
        $copy($default, null);
        $copy(null, null);
        $this->pdo()->exec("INSERT INTO loans (copy_id, borrower_name, loaned_on, created_at, updated_at) VALUES ({$inDefault}, 'Someone', '2026-01-01', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $collection = function (int $owner, string $name, string $visibility): int {
            $this->pdo()->prepare("INSERT INTO collections (owner_user_id, name, description, visibility, created_at, updated_at) VALUES (:o, :n, 'kept', :v, '2026-11-01 10:00:00', '2026-11-02 11:00:00')")->execute(['o' => $owner, 'n' => $name, 'v' => $visibility]);

            return (int) $this->pdo()->lastInsertId();
        };
        $private = $collection($memberId, 'Private list', 'private');
        $shared = $collection($memberId, 'Shared list', 'shared');
        $sharedByAdmin = $collection($adminId, 'Admin picks', 'shared');
        $this->pdo()->exec("INSERT INTO collection_items (collection_id, edition_id, position, added_at) VALUES ({$shared}, {$book['edition_id']}, 1, UTC_TIMESTAMP())");
        $this->pdo()->exec("INSERT INTO invitations (email, role_id, token_hash, invited_by, expires_at, created_at) VALUES ('pending@example.org', 2, REPEAT('a', 64), {$adminId}, UTC_TIMESTAMP() + INTERVAL 5 DAY, UTC_TIMESTAMP())");

        $untouched = $this->checksums();
        $copiesBefore = $this->rows('copies');
        $collectionsBefore = $this->rows('collections');

        $applied = $this->migrateUpTo($pdo, self::H3[3]);
        self::assertSame(self::H3, $applied);

        // Copies: every row as it was (codes, households, versions, timestamps), plus the derived scope.
        self::assertSame($untouched, $this->checksums(), 'Users, catalogue, loans, wishlists, items, invitations, households and locations are unchanged');
        self::assertSame($copiesBefore, $this->rows('copies', ['inventory_scope_id']), 'Existing inventory codes and copies are preserved');
        foreach ($this->pdo()->query('SELECT household_id, inventory_scope_id FROM copies')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            self::assertSame((int) ($row['household_id'] ?? 0), (int) $row['inventory_scope_id']);
        }
        self::assertSame(['uq_copies_scope_inventory_code'], $this->uniqueIndexes('copies'), 'The global index is replaced by the household-scoped one');
        self::assertSame('YES', $this->nullable('copies', 'household_id'), 'household_id stays nullable (NOT NULL is phase H4)');
        self::assertStringContainsString('GENERATED', strtoupper((string) $this->pdo()->query("SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'copies' AND COLUMN_NAME = 'inventory_scope_id'")->fetchColumn()));

        // The same code may now exist in another household; inside one scope it stays unique,
        // and copies without a household are one scope (NULL does not escape the index).
        $this->pdo()->exec("UPDATE copies SET inventory_code = 'INV-1' WHERE id = {$inOther}");
        foreach ([[$default, 'INV-1'], [$other, 'INV-1'], [null, 'INV-3']] as [$household, $code]) {
            try {
                $copy($household, $code);
                self::fail("A second {$code} was accepted in the same scope");
            } catch (\PDOException $e) {
                self::assertSame(1062, $e->errorInfo[1]);
                self::assertStringContainsString('uq_copies_scope_inventory_code', $e->getMessage());
            }
        }
        // Moving a copy into a household where its code is taken is refused by the database as well.
        try {
            $this->pdo()->exec("UPDATE copies SET household_id = {$default} WHERE id = {$inOther}");
            self::fail('A move created a duplicate code');
        } catch (\PDOException $e) {
            self::assertSame(1062, $e->errorInfo[1]);
        }
        self::assertSame('INV-3', $this->pdo()->query("SELECT inventory_code FROM copies WHERE id = {$unassigned}")->fetchColumn());

        // Collections: private ones untouched; shared ones went to the default household; nothing else changed.
        self::assertSame($collectionsBefore, $this->rows('collections', ['household_id']), 'Names, owners, visibility and timestamps are unchanged');
        $households = $this->pdo()->query('SELECT id, household_id FROM collections ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertNull($households[$private], 'A private collection is never given a household');
        self::assertSame([$default, $default], [(int) $households[$shared], (int) $households[$sharedByAdmin]]);
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM collections WHERE visibility = 'private'"), 'No private collection became shared');
        self::assertSame('YES', $this->nullable('collections', 'household_id'));

        // Invitations: the pending one carries no household; the new table is empty.
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM invitation_households'));
        $rules = $this->pdo()->query("SELECT CONSTRAINT_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME IN ('fk_collections_household', 'fk_invitation_households_invitation', 'fk_invitation_households_household') ORDER BY CONSTRAINT_NAME")->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame(['fk_collections_household' => 'RESTRICT', 'fk_invitation_households_household' => 'CASCADE', 'fk_invitation_households_invitation' => 'CASCADE'], $rules);

        // The previous application version keeps working on this schema: its statements name
        // their columns, and never the generated one or the new household columns.
        $legacyCopy = $this->insertLegacyCopy($book['edition_id'], $memberId);
        self::assertSame('0', (string) $this->pdo()->query("SELECT inventory_scope_id FROM copies WHERE id = {$legacyCopy}")->fetchColumn());
        $this->pdo()->exec("UPDATE copies SET owner_user_id = {$memberId}, household_id = {$default}, location_id = NULL, inventory_code = 'OLD-APP', copy_condition = NULL, status = 'available', is_signed = 0,
            acquired_on = NULL, acquisition_source = NULL, purchase_price_cents = NULL, currency = NULL, notes = NULL, updated_by = {$memberId}, updated_at = UTC_TIMESTAMP(), row_version = row_version + 1 WHERE id = {$legacyCopy}");
        self::assertSame((string) $default, (string) $this->pdo()->query("SELECT inventory_scope_id FROM copies WHERE id = {$legacyCopy}")->fetchColumn());
        $legacyShared = $collection($adminId, 'Written by the previous version', 'shared');
        self::assertNull($this->pdo()->query("SELECT household_id FROM collections WHERE id = {$legacyShared}")->fetchColumn());

        // The backfill can be run again: it adopts that collection and touches nothing else.
        $before = $this->rows('collections', [], "id <> {$legacyShared}");
        foreach (SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . self::H3[2] . '.sql')) as $statement) {
            $pdo->exec($statement);
        }
        self::assertSame($before, $this->rows('collections', [], "id <> {$legacyShared}"));
        self::assertSame((string) $default, (string) $this->pdo()->query("SELECT household_id FROM collections WHERE id = {$legacyShared}")->fetchColumn());
        self::assertNull($this->pdo()->query("SELECT household_id FROM collections WHERE id = {$private}")->fetchColumn());

        // A household with shared collections cannot be deleted at the database level either.
        try {
            $pdo->exec("DELETE FROM households WHERE id = {$default}");
            self::fail('A household with inventory and collections was deleted');
        } catch (\PDOException $e) {
            self::assertSame(1451, $e->errorInfo[1]);
        }

        // Re-running the migrator is a no-op and nothing is reported as modified.
        $migrator = new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations');
        self::assertSame(['2026_11_15_100000_create_inventory_transfers', '2026_11_15_100001_add_location_row_versions'], $migrator->migrate());
        self::assertSame([], $migrator->migrate());
        foreach ($migrator->status() as $row) {
            self::assertTrue($row['applied'] && !$row['modified'] && !$row['other_engine'], $row['version']);
        }
    }

    /**
     * inventory_scope_id is derived by the database from household_id. No
     * statement of the application names it in an INSERT or UPDATE (the
     * server would refuse), and backups leave generated columns out.
     */
    public function testGeneratedScopeColumnIsNeverWrittenByTheApplication(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $source = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression('/(INSERT INTO copies|UPDATE copies)[^;]*inventory_scope_id\s*(=|,)/s', $source, $file->getFilename());
        }
        self::assertSame(1, $this->columnCount('copies', 'inventory_scope_id'));
        $columns = $this->pdo()->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'copies' AND EXTRA NOT LIKE '%GENERATED%' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
        self::assertNotContains('inventory_scope_id', $columns);
        self::assertContains('household_id', $columns);
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

    private function nullable(string $table, string $column): string
    {
        return (string) $this->pdo()->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$column}'")->fetchColumn();
    }

    /**
     * @return list<string>
     */
    private function uniqueIndexes(string $table): array
    {
        return $this->pdo()->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY' ORDER BY INDEX_NAME")->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @return array<string, string>
     */
    private function checksums(): array
    {
        $out = [];
        foreach (['users', 'works', 'editions', 'authors', 'work_authors', 'loans', 'wishlist_items', 'collection_items', 'audit_log', 'invitations', 'covers', 'households', 'household_members', 'locations'] as $table) {
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
        $rows = $this->pdo()->query("SELECT * FROM {$table} WHERE {$where} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): array => array_diff_key($row, array_flip($without)), $rows);
    }
}
