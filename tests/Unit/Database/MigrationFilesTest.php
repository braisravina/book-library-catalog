<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Database;

use DigitalLibrary\Database\SqlSplitter;
use PHPUnit\Framework\TestCase;

/**
 * Static checks on migration files that do not need a database.
 */
final class MigrationFilesTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private static function migrations(): array
    {
        $files = glob(dirname(__DIR__, 3) . '/database/migrations/*.sql') ?: [];
        $out = [];
        foreach ($files as $file) {
            $out[basename($file)] = (string) file_get_contents($file);
        }

        return $out;
    }

    public function testFilenamesAreOrderedAndWellFormed(): void
    {
        $names = array_keys(self::migrations());
        self::assertNotEmpty($names);
        foreach ($names as $name) {
            self::assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.sql$/', $name);
        }
        $sorted = $names;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $names);
    }

    public function testEveryTableIsInnoDbUtf8mb4(): void
    {
        foreach (self::migrations() as $name => $sql) {
            foreach (SqlSplitter::split($sql) as $statement) {
                if (stripos($statement, 'CREATE TABLE') === 0) {
                    self::assertStringContainsString('ENGINE=InnoDB', $statement, $name);
                    self::assertStringContainsString('CHARSET=utf8mb4', $statement, $name);
                }
            }
        }
    }

    public function testExpectedCoreTablesAreDefined(): void
    {
        $all = implode("\n", self::migrations());
        preg_match_all('/CREATE TABLE (\w+)/', $all, $m);
        foreach (['roles', 'users', 'invitations', 'login_attempts', 'settings', 'authors', 'publishers', 'works',
            'work_authors', 'editions', 'edition_contributors', 'categories', 'work_categories', 'covers',
            'metadata_records', 'locations', 'copies', 'collections', 'collection_items', 'audit_log'] as $table) {
            self::assertContains($table, $m[1]);
        }
    }

    /**
     * Migrations already delivered (Phases 1-4A) are frozen: schema changes go
     * in new files. The checksums match what the Migrator records.
     */
    public function testReleasedMigrationsAreUnchanged(): void
    {
        $released = [
            '2026_10_10_000001_create_identity_tables.sql' => '2f490123300665a7c3ecf0afd06469ae1e11daee4486b6668eb88e9540eb60de',
            '2026_10_10_000002_create_catalogue_tables.sql' => '7859589eb533afe4838fdbc2b9c8f4a80ef28fe1c7aad9b2779755b4ab3c1da4',
            '2026_10_10_000003_create_holdings_tables.sql' => '8b7f06d350ee8b01a21c21867405c8aeb4ddcb654dae4db787bcb759a88e9468',
            '2026_10_10_000004_create_audit_log.sql' => '2a9a8dbc844743b8d86486b2a4d9fdc4c98c7258f90bcc36974ba3d7fe1bbab0',
            '2026_10_10_000005_add_catalogue_browse_indexes.sql' => '60a39b163add26a88838aaf443b30bc77b9503f0f94d2b7038780e88f8140646',
            '2026_10_10_215610_add_row_versions.sql' => 'f1bd8dbdf36c75090de099d68ea056c94108c89c38cc5100ccbba9be9228d5f6',
            // Phase 4A (committed by the owner as a2e5040).
            '2026_10_13_155738_create_loans_table.sql' => 'dc27cc81435756651c20a56043de578e6fc71192feacaf60f10945c330af9fce',
            '2026_10_13_155739_create_wishlist_items_table.sql' => 'a877a32c260ae3700498fb4b60fecc0463b3afc161e80d62601fae343db2827b',
            '2026_10_13_155740_add_collection_indexes.sql' => 'a29135da6954550e42f6c3ea5e4864b2bdb5ae05b1a9754be1436fb231943686',
        ];
        $files = self::migrations();
        foreach ($released as $name => $sha256) {
            self::assertArrayHasKey($name, $files);
            self::assertSame($sha256, hash('sha256', $files[$name]), $name . ' was modified after release');
        }
        $newer = array_filter(array_keys($files), static fn (string $n): bool => !isset($released[$n]));
        foreach ($newer as $name) {
            self::assertGreaterThan(array_key_last($released), $name, 'New migrations sort after released ones');
        }
    }

    /**
     * MariaDB overrides (database/migrations/mariadb/, used by the Migrator
     * only on a server positively identified as MariaDB) replace a released
     * migration of the same name with the smallest possible change: a "-- "
     * comment header and exactly the documented replacements. Released
     * overrides are frozen like released migrations.
     */
    public function testMariadbOverridesDifferOnlyByTheDocumentedChange(): void
    {
        $documented = [
            '2026_10_13_155739_create_wishlist_items_table.sql' => [
                'sha256' => '962ff21c936c8bad159c640ce2bf16b2b129d3b00979a587efa7107a67ba3b24',
                // MariaDB error 1901: a stored generated column may not return a CHAR column.
                'replace' => ["IF(status = 'wanted', isbn_13, NULL)" => "IF(status = 'wanted', RTRIM(isbn_13), NULL)"],
            ],
        ];
        $released = self::migrations();
        $overrides = [];
        foreach (glob(dirname(__DIR__, 3) . '/database/migrations/mariadb/*') ?: [] as $file) {
            $overrides[basename($file)] = (string) file_get_contents($file);
        }
        self::assertSame(array_keys($documented), array_keys($overrides), 'Every override is documented here');
        foreach ($documented as $name => $rule) {
            self::assertArrayHasKey($name, $released, $name . ': overrides a released migration of the same name');
            $override = $overrides[$name];
            self::assertSame($rule['sha256'], hash('sha256', $override), $name . ': a released override is frozen');
            $expected = $released[$name];
            foreach ($rule['replace'] as $from => $to) {
                self::assertSame(1, substr_count($expected, $from), $name . ': ' . $from);
                $expected = str_replace($from, $to, $expected);
            }
            self::assertStringEndsWith($expected, $override, $name . ': the released file with only the documented replacements');
            $header = substr($override, 0, strlen($override) - strlen($expected));
            self::assertMatchesRegularExpression('/\A(?:-- [^\n]*\n)+\z/', $header, $name . ': before it, only a "-- " header that explains the override');
            self::assertStringContainsString('error 1901', $header);
            self::assertCount(count(SqlSplitter::split($released[$name])), SqlSplitter::split($override), $name . ': same statements');
        }
    }

    /**
     * Migrations are forward-only and additive: no destructive statements.
     * The single exception is documented below: phase H3 replaces the
     * global inventory-code index by a household-scoped one in one atomic
     * ALTER (no data is removed).
     */
    public function testMigrationsAreForwardOnlyAndNonDestructive(): void
    {
        $allowed = ['2026_11_14_100000_scope_inventory_codes_to_households.sql' => 'DROP INDEX uq_copies_inventory_code'];
        $used = [];
        foreach (self::migrations() as $name => $sql) {
            foreach (SqlSplitter::split($sql) as $statement) {
                if (isset($allowed[$name])) {
                    // Match the complete identifier, never an index whose
                    // name merely starts with the permitted exception.
                    $pattern = '/\\b' . preg_quote($allowed[$name], '/') . '\\b/';
                    if (preg_match_all($pattern, $statement) === 1) {
                        $statement = (string) preg_replace($pattern, '', $statement);
                        $used[$name] = true;
                    }
                }
                self::assertDoesNotMatchRegularExpression('/^\s*(DROP|TRUNCATE|RENAME|DELETE)\b|\bDROP\s+(COLUMN|INDEX|KEY|FOREIGN|CONSTRAINT|CHECK|PRIMARY)\b/i', $statement, $name);
            }
        }
        self::assertSame(array_keys($allowed), array_keys($used), 'The documented exception is used exactly where it is documented');
    }

    /**
     * Household phase H3: inventory codes become unique per household,
     * shared collections get a household and invitations may carry
     * household assignments. Additive, portable, household_id stays
     * nullable, and data is backfilled in a file of its own.
     */
    public function testPhaseThreeHouseholdMigrations(): void
    {
        $files = self::migrations();
        $names = ['2026_11_14_100000_scope_inventory_codes_to_households.sql', '2026_11_14_100001_add_household_to_collections.sql',
            '2026_11_14_100002_backfill_shared_collection_households.sql', '2026_11_14_100003_create_invitation_households.sql'];
        foreach ($names as $name) {
            self::assertArrayHasKey($name, $files);
            // Nothing that MariaDB 11.4 and MySQL 8.4 treat differently (an integer generated column is fine on both).
            self::assertDoesNotMatchRegularExpression('/\/\*\+|->>?|JSON_|\bAS\s+new\b|ON DUPLICATE|INSERT\s+IGNORE|REPLACE\s+INTO|FOR SHARE/i', (string) preg_replace('/^--.*$/m', '', $files[$name]), $name);
            self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/database/migrations/mariadb/' . $name, $name . ' needs no MariaDB override');
            self::assertDoesNotMatchRegularExpression('/NOT NULL[^,]*household_id|household_id[^,\n]*NOT NULL(?!.*PRIMARY)/i', $name === $names[3] ? '' : $files[$name], $name . ': household_id stays nullable');
        }

        $codes = SqlSplitter::split($files[$names[0]]);
        self::assertCount(1, $codes, 'One atomic ALTER: the new index exists before the old one is gone');
        self::assertStringStartsWith('ALTER TABLE copies', $codes[0]);
        self::assertStringContainsString('ADD COLUMN inventory_scope_id INT UNSIGNED GENERATED ALWAYS AS (COALESCE(household_id, 0)) STORED', $codes[0], 'Copies without a household share scope 0: NULL never reaches the unique index');
        self::assertStringContainsString('ADD UNIQUE KEY uq_copies_scope_inventory_code (inventory_scope_id, inventory_code)', $codes[0]);
        self::assertStringContainsString('DROP INDEX uq_copies_inventory_code', $codes[0]);
        self::assertDoesNotMatchRegularExpression('/\b(MODIFY|CHANGE|RENAME|DROP\s+COLUMN)\b|UPDATE|DELETE/i', $codes[0], 'No existing column or row is touched');

        $collections = SqlSplitter::split($files[$names[1]]);
        self::assertCount(1, $collections);
        self::assertStringStartsWith('ALTER TABLE collections', $collections[0]);
        self::assertStringContainsString('ADD COLUMN household_id INT UNSIGNED NULL', $collections[0]);
        self::assertStringContainsString('REFERENCES households (id) ON DELETE RESTRICT', $collections[0]);
        self::assertDoesNotMatchRegularExpression('/\b(MODIFY|CHANGE|DROP|RENAME)\b|UNIQUE/i', $collections[0]);

        $backfill = SqlSplitter::split($files[$names[2]]);
        self::assertCount(1, $backfill);
        self::assertStringStartsWith('UPDATE collections', $backfill[0]);
        self::assertStringEndsWith("WHERE visibility = 'shared' AND household_id IS NULL", $backfill[0], 'Only shared collections without a household; private ones are never touched');
        self::assertStringNotContainsString('visibility =', (string) preg_replace('/WHERE.*/s', '', $backfill[0]), 'No visibility is rewritten');

        $invitations = SqlSplitter::split($files[$names[3]]);
        self::assertCount(1, $invitations);
        self::assertStringStartsWith('CREATE TABLE invitation_households (', $invitations[0]);
        self::assertStringContainsString('PRIMARY KEY (invitation_id, household_id)', $invitations[0]);
        self::assertStringContainsString('REFERENCES invitations (id) ON DELETE CASCADE', $invitations[0]);
        self::assertStringContainsString('REFERENCES households (id) ON DELETE CASCADE', $invitations[0], 'A deleted household disappears from pending invitations');
    }

    public function testPhase4bInvitationMigrationOnlyAddsConstraints(): void
    {
        $sql = self::migrations()['2026_10_14_090000_harden_invitations.sql'] ?? self::fail('Phase 4B migration missing');
        $statements = SqlSplitter::split($sql);
        self::assertCount(1, $statements);
        self::assertStringStartsWith('ALTER TABLE invitations', $statements[0]);
        self::assertStringContainsString('CHECK (role_id IN (2, 3))', $statements[0], 'No administrator invitations');
        self::assertStringContainsString('UNIQUE KEY uq_invitations_open_email', $statements[0]);
        self::assertDoesNotMatchRegularExpression('/\b(DROP|MODIFY|CHANGE|RENAME)\b/i', $statements[0]);
    }

    /**
     * Household phase H1: additive schema (new tables, nullable columns),
     * then an idempotent, data-only backfill. NOT NULL and uniqueness
     * changes are deliberately left to a later phase.
     */
    public function testHouseholdMigrationsAreAdditiveNullableAndPortable(): void
    {
        $files = self::migrations();
        $names = ['2026_11_13_180000_create_households.sql', '2026_11_13_180001_add_household_to_inventory.sql', '2026_11_13_180002_backfill_default_household.sql'];
        foreach ($names as $name) {
            self::assertArrayHasKey($name, $files);
            // Nothing that MariaDB 11.4 and MySQL 8.4 treat differently.
            self::assertDoesNotMatchRegularExpression('/GENERATED|\/\*\+|->>?|JSON_|\bAS\s+new\b|ON DUPLICATE|INSERT\s+IGNORE|REPLACE\s+INTO/i', (string) preg_replace('/^--.*$/m', '', $files[$name]), $name);
            self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/database/migrations/mariadb/' . $name, $name . ' needs no MariaDB override');
        }

        $create = SqlSplitter::split($files[$names[0]]);
        self::assertCount(2, $create);
        self::assertStringStartsWith('CREATE TABLE households (', $create[0]);
        self::assertStringStartsWith('CREATE TABLE household_members (', $create[1]);
        self::assertStringContainsString('UNIQUE KEY uq_households_system_key (system_key)', $create[0]);
        self::assertStringContainsString('PRIMARY KEY (household_id, user_id)', $create[1]);
        self::assertStringContainsString('REFERENCES households (id) ON DELETE RESTRICT', $create[1]);

        $alter = SqlSplitter::split($files[$names[1]]);
        self::assertCount(2, $alter);
        foreach (['copies', 'locations'] as $i => $table) {
            self::assertStringStartsWith('ALTER TABLE ' . $table, $alter[$i]);
            self::assertStringContainsString('ADD COLUMN household_id INT UNSIGNED NULL', $alter[$i], 'Nullable: the previous application version keeps working');
            self::assertStringContainsString('REFERENCES households (id) ON DELETE RESTRICT', $alter[$i]);
            self::assertDoesNotMatchRegularExpression('/\b(MODIFY|CHANGE|DROP|RENAME)\b|NOT NULL|UNIQUE/i', $alter[$i], 'Only additions; no constraint that would break a rollback');
        }

        $backfill = SqlSplitter::split($files[$names[2]]);
        self::assertCount(4, $backfill);
        foreach ($backfill as $statement) {
            self::assertMatchesRegularExpression('/^(INSERT INTO|UPDATE) /', $statement, 'Data only: no DDL in the backfill');
        }
        self::assertStringContainsString("WHERE NOT EXISTS (SELECT 1 FROM households WHERE system_key = 'default')", $backfill[0], 'Creates the default household once');
        self::assertStringEndsWith('WHERE household_id IS NULL', $backfill[1], 'Never reassigns a copy that has a household');
        self::assertStringEndsWith('WHERE household_id IS NULL', $backfill[2]);
        self::assertStringContainsString('u.is_active = 1', $backfill[3], 'Disabled accounts gain no access');
        self::assertStringContainsString('u.last_login_at IS NOT NULL', $backfill[3], 'Accounts that never signed in gain no access');
        self::assertStringContainsString('NOT EXISTS (SELECT 1 FROM household_members m', $backfill[3], 'No duplicate memberships');
    }

    public function testPhase4aTablesAreDefined(): void
    {
        $all = implode("\n", self::migrations());
        foreach (['loans', 'wishlist_items'] as $table) {
            self::assertMatchesRegularExpression('/CREATE TABLE ' . $table . ' \(/', $all);
        }
    }
}
