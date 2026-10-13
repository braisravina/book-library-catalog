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
     * Migrations already delivered (Phases 1-3) are frozen: schema changes go
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
     * Migrations are forward-only and additive: no destructive statements.
     */
    public function testMigrationsAreForwardOnlyAndNonDestructive(): void
    {
        foreach (self::migrations() as $name => $sql) {
            foreach (SqlSplitter::split($sql) as $statement) {
                self::assertDoesNotMatchRegularExpression('/^\s*(DROP|TRUNCATE|RENAME|DELETE)\b|\bDROP\s+(COLUMN|INDEX|KEY|FOREIGN|CONSTRAINT|CHECK|PRIMARY)\b/i', $statement, $name);
            }
        }
    }

    public function testPhase4aTablesAreDefined(): void
    {
        $all = implode("\n", self::migrations());
        foreach (['loans', 'wishlist_items'] as $table) {
            self::assertMatchesRegularExpression('/CREATE TABLE ' . $table . ' \(/', $all);
        }
    }
}
