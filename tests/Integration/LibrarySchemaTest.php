<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Security\Role;
use PDO;
use PDOException;

/**
 * Verifies the Phase 4A migrations on the real MySQL server: tables,
 * indexes, generated columns, CHECK constraints and foreign-key actions.
 */
final class LibrarySchemaTest extends IntegrationTestCase
{
    public function testPhase4aMigrationsAreRecorded(): void
    {
        $versions = $this->migrationPdo()->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['2026_10_13_155738_create_loans_table', '2026_10_13_155739_create_wishlist_items_table', '2026_10_13_155740_add_collection_indexes'] as $version) {
            self::assertContains($version, array_map(static fn ($v): string => (string) preg_replace('/\.sql$/', '', (string) $v), $versions), $version);
        }
    }

    public function testIndexesExist(): void
    {
        $expected = [
            'loans' => ['uq_loans_active_copy' => 0, 'idx_loans_copy' => 1, 'idx_loans_borrower' => 1, 'idx_loans_due' => 1],
            'wishlist_items' => ['uq_wishlist_wanted_isbn' => 0, 'idx_wishlist_user_status' => 1, 'idx_wishlist_edition' => 1, 'idx_wishlist_work' => 1],
            'collection_items' => ['idx_collection_items_position' => 1],
            'collections' => ['idx_collections_visibility' => 1],
        ];
        foreach ($expected as $table => $indexes) {
            $stmt = $this->pdo()->prepare('SELECT INDEX_NAME, MIN(NON_UNIQUE) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t GROUP BY INDEX_NAME');
            $stmt->execute(['t' => $table]);
            $actual = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
            foreach ($indexes as $name => $nonUnique) {
                self::assertArrayHasKey($name, $actual, "{$table}.{$name}");
                self::assertSame($nonUnique, $actual[$name], "{$table}.{$name} uniqueness");
            }
        }
    }

    public function testGeneratedColumnsAndConstraints(): void
    {
        $generated = $this->pdo()->query("SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME), EXTRA FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('active_copy_id', 'wanted_isbn_13')")->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame('STORED GENERATED', $generated['loans.active_copy_id'] ?? null);
        self::assertSame('STORED GENERATED', $generated['wishlist_items.wanted_isbn_13'] ?? null);

        $checks = $this->pdo()->query('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = \'CHECK\'')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['chk_loans_borrower', 'chk_loans_due', 'chk_loans_returned', 'chk_wishlist_isbn'] as $check) {
            self::assertContains($check, $checks);
        }

        $rules = $this->pdo()->query('SELECT CONSTRAINT_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN (\'loans\', \'wishlist_items\')')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame([
            'fk_loans_borrower' => 'RESTRICT', 'fk_loans_copy' => 'RESTRICT', 'fk_loans_created_by' => 'SET NULL', 'fk_loans_returned_by' => 'SET NULL',
            'fk_wishlist_edition' => 'SET NULL', 'fk_wishlist_user' => 'CASCADE', 'fk_wishlist_work' => 'SET NULL',
        ], (static function (array $r): array { ksort($r); return $r; })($rules));
    }

    public function testLoanCheckConstraintsRejectInconsistentRows(): void
    {
        $owner = $this->createUser('owner@example.org', Role::Member);
        $copy = (int) $this->makeBook($owner, 'Checked', null, [], [], [], $owner)['copy_id'];
        $insert = fn (string $values) => $this->pdo()->exec(
            "INSERT INTO loans (copy_id, borrower_user_id, borrower_name, loaned_on, due_on, returned_on, created_at, updated_at)
             VALUES ({$copy}, {$values}, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        foreach ([
            'no borrower' => "NULL, NULL, '2026-01-10', NULL, NULL",
            'due before loan' => "NULL, 'A', '2026-01-10', '2026-01-09', NULL",
            'returned before loan' => "NULL, 'A', '2026-01-10', NULL, '2026-01-09'",
            'unknown borrower user' => "999999, NULL, '2026-01-10', NULL, NULL",
        ] as $label => $values) {
            try {
                $insert($values);
                self::fail("Accepted: {$label}");
            } catch (PDOException $e) {
                self::assertContains($e->errorInfo[1], [3819, 1452], $label); // CHECK violated / FK violated
            }
        }
        $insert("NULL, 'A', '2026-01-10', '2026-01-10', '2026-01-10'");
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM loans'));
        self::assertNull($this->pdo()->query('SELECT active_copy_id FROM loans')->fetchColumn(), 'Returned loans release the active slot');
    }

    public function testLoanHistoryProtectsCopiesAndBorrowers(): void
    {
        $owner = $this->createUser('owner@example.org', Role::Member);
        $borrower = $this->createUser('borrower@example.org', Role::Member);
        $copy = (int) $this->makeBook($owner, 'Kept', null, [], [], [], $owner)['copy_id'];
        $this->pdo()->exec("INSERT INTO loans (copy_id, borrower_user_id, loaned_on, created_by, created_at, updated_at)
            VALUES ({$copy}, {$borrower}, '2026-01-10', {$owner}, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        foreach (["DELETE FROM copies WHERE id = {$copy}", "DELETE FROM users WHERE id = {$borrower}"] as $sql) {
            try {
                $this->pdo()->exec($sql);
                self::fail('Deleted a row referenced by loan history: ' . $sql);
            } catch (PDOException $e) {
                self::assertSame(1451, $e->errorInfo[1]);
            }
        }
    }

    public function testWishlistEntriesFollowTheirOwnerAndSurviveCatalogueDeletion(): void
    {
        $user = $this->createUser('wisher@example.org', Role::Member);
        $book = $this->makeBook($user, 'Soon gone', '9780306406157');
        $this->pdo()->exec("INSERT INTO wishlist_items (user_id, edition_id, work_id, title, isbn_13, created_at, updated_at)
            VALUES ({$user}, {$book['edition_id']}, {$book['work_id']}, 'Soon gone', '9780306406157', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $this->pdo()->exec("DELETE FROM editions WHERE id = {$book['edition_id']}");
        $this->pdo()->exec("DELETE FROM works WHERE id = {$book['work_id']}");
        $row = $this->pdo()->query('SELECT edition_id, work_id, title FROM wishlist_items')->fetch();
        self::assertSame([null, null, 'Soon gone'], [$row['edition_id'], $row['work_id'], $row['title']]);

        $this->pdo()->exec("DELETE FROM users WHERE id = {$user}");
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM wishlist_items'), 'Private data goes with its owner');
    }
}
