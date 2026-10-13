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
}
