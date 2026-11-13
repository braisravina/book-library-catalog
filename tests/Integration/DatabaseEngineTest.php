<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Console\Console;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Database\ConnectionFactory;
use DigitalLibrary\Database\Engine;
use DigitalLibrary\Database\MigrationEngineMismatchException;
use DigitalLibrary\Database\Migrator;
use DigitalLibrary\Security\Role;
use PDO;
use PDOException;

/**
 * Runs on whichever engine the suite is connected to: MariaDB 11.4
 * (production, CI job "test") and MySQL 8.4 (CI job "test-mysql"). Every
 * assertion holds on both, so neither engine is skipped.
 */
final class DatabaseEngineTest extends IntegrationTestCase
{
    private const WISHLIST = '2026_10_13_155739_create_wishlist_items_table';

    /** TEMP_PATH of the console runs (operation lock), removed after each test. */
    private ?string $tmp = null;

    protected function tearDown(): void
    {
        if ($this->tmp !== null) {
            @unlink($this->tmp . '/operations.lock');
            @rmdir($this->tmp);
            $this->tmp = null;
        }
        parent::tearDown();
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->migrationPdo(), dirname(__DIR__, 2) . '/database/migrations');
    }

    private function engine(): string
    {
        return Engine::of($this->pdo());
    }

    public function testTheEngineIsIdentifiedPositively(): void
    {
        $version = (string) $this->pdo()->query('SELECT VERSION()')->fetchColumn();
        self::assertSame(str_contains($version, '-MariaDB') ? Engine::MARIADB : Engine::MYSQL, $this->engine(), $version);
        self::assertSame($this->engine(), $this->migrator()->engine());
        if ($this->engine() === Engine::MARIADB) {
            self::assertTrue(version_compare(explode('-', $version)[0], '11.4.5', '>='), 'utf8mb4_0900_* collations need MariaDB 11.4.5 or later: ' . $version);
        } else {
            self::assertStringStartsWith('8.4.', $version);
        }
    }

    public function testEachVersionRanExactlyOneVariantWithItsOwnChecksum(): void
    {
        $migrator = $this->migrator();
        $expected = $this->engine() === Engine::MARIADB ? 'mariadb' : 'mysql';
        foreach ($migrator->status() as $row) {
            self::assertTrue($row['applied'], $row['version']);
            self::assertFalse($row['modified'], $row['version']);
            self::assertFalse($row['other_engine'], $row['version']);
        }
        $rows = $this->migrationPdo()->query("SELECT version, checksum FROM schema_migrations WHERE version = '" . self::WISHLIST . "'")->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertCount(1, $rows, 'One history row, never one per variant');
        $file = $migrator->fileFor(self::WISHLIST);
        self::assertSame(hash('sha256', (string) file_get_contents($file)), $rows[self::WISHLIST], 'The checksum of the file actually executed');
        self::assertSame($expected === 'mariadb', str_contains($file, '/mariadb/'));
        self::assertSame($expected, array_values(array_filter($migrator->status(), static fn (array $r): bool => $r['version'] === self::WISHLIST))[0]['variant']);
        $checksums = $migrator->checksums(self::WISHLIST);
        self::assertNotSame($checksums['mysql'], $checksums['mariadb']);
        self::assertSame('a877a32c260ae3700498fb4b60fecc0463b3afc161e80d62601fae343db2827b', $checksums['mysql'], 'The released file is unchanged');
        $definition = (string) $this->migrationPdo()->query("SELECT GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wishlist_items' AND COLUMN_NAME = 'wanted_isbn_13'")->fetchColumn();
        self::assertSame($expected === 'mariadb', stripos($definition, 'rtrim') !== false, $definition);
    }

    public function testHistoryFromTheOtherEngineIsRefusedAndNeverRewritten(): void
    {
        $migrator = $this->migrator();
        $checksums = $migrator->checksums(self::WISHLIST);
        $other = $checksums[$this->engine() === Engine::MARIADB ? 'mysql' : 'mariadb'];
        $own = $checksums[$this->engine()];
        $pdo = $this->migrationPdo();
        $pdo->prepare('UPDATE schema_migrations SET checksum = :c WHERE version = :v')->execute(['c' => $other, 'v' => self::WISHLIST]);
        try {
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            try {
                $migrator->migrate();
                self::fail('A history written by the other engine was accepted');
            } catch (MigrationEngineMismatchException $e) {
                self::assertSame([self::WISHLIST], $e->versions);
                self::assertStringContainsString('Nothing was changed', $e->getMessage());
                self::assertStringContainsString('Do not edit schema_migrations', $e->getMessage());
            }
            self::assertSame($other, $pdo->query("SELECT checksum FROM schema_migrations WHERE version = '" . self::WISHLIST . "'")->fetchColumn(), 'History is never rewritten');
            self::assertSame($tables, $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
            $row = array_values(array_filter($migrator->status(), static fn (array $r): bool => $r['version'] === self::WISHLIST))[0];
            self::assertTrue($row['other_engine']);
            self::assertFalse($row['modified'], 'Reported as the other engine, not as an edit');

            [$code, $out] = $this->console(['migrate:status']);
            self::assertSame(1, $code);
            self::assertMatchesRegularExpression('/^OTHER-ENGINE +' . self::WISHLIST . '/m', $out);
            self::assertStringContainsString('migrate refuses to run', $out);
            [$code, , $err] = $this->console(['migrate']);
            self::assertSame(1, $code);
            self::assertStringContainsString('migration history written by', $err);
        } finally {
            $pdo->prepare('UPDATE schema_migrations SET checksum = :c WHERE version = :v')->execute(['c' => $own, 'v' => self::WISHLIST]);
        }
        [$code, $out] = $this->console(['migrate:status']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Database engine: ' . ($this->engine() === Engine::MARIADB ? 'MariaDB' : 'MySQL'), $out);
    }

    public function testWantedIsbnGenerationIndexAndUniquenessAreUnchanged(): void
    {
        $this->assertWishlistBehaviour();
    }

    /**
     * PAD_CHAR_TO_FULL_LENGTH changes how CHAR values are read; it is why
     * MariaDB refuses the released expression (MySQL 8.4 still accepts the
     * mode, deprecated). Generated values, lookups and uniqueness must not
     * depend on it on either engine.
     */
    public function testWantedIsbnBehavesTheSameWithPadCharToFullLength(): void
    {
        $pdo = $this->pdo();
        $mode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        try {
            $pdo->exec("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',PAD_CHAR_TO_FULL_LENGTH')");
            self::assertStringContainsString('PAD_CHAR_TO_FULL_LENGTH', (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn());
            $this->assertWishlistBehaviour();
        } finally {
            $pdo->prepare('SET SESSION sql_mode = :m')->execute(['m' => $mode]);
        }
        self::assertStringNotContainsString('PAD_CHAR_TO_FULL_LENGTH', (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn());
        $this->assertWishlistBehaviour();
    }

    public function testCollationsKeepTheirMySql0900Semantics(): void
    {
        $pdo = $this->pdo();
        $check = static fn (string $a, string $b, string $collation): bool => (bool) $pdo->query(
            'SELECT ' . $pdo->quote($a) . ' = ' . $pdo->quote($b) . ' COLLATE ' . $collation
        )->fetchColumn();
        self::assertTrue($check('García Márquez', 'GARCIA MARQUEZ', 'utf8mb4_0900_ai_ci'), 'case and accent insensitive');
        self::assertTrue($check('Ñandú', 'nandu', 'utf8mb4_0900_ai_ci'));
        self::assertTrue($check('JOSÉ', 'josé', 'utf8mb4_0900_as_ci'), 'as_ci: case insensitive');
        self::assertFalse($check('José', 'Jose', 'utf8mb4_0900_as_ci'), 'as_ci: accents distinguish authors');
        self::assertFalse($check('a', 'a ', 'utf8mb4_0900_ai_ci'), 'NO PAD: trailing spaces are significant');
        foreach (['authors', 'works', 'editions', 'wishlist_items'] as $table) {
            $stmt = $pdo->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
            $stmt->execute(['t' => $table]);
            self::assertSame('utf8mb4_0900_ai_ci', $stmt->fetchColumn(), $table);
        }
        // Authors are matched accent-sensitively (AuthorRepository): two people.
        $this->createUser('member@example.org', Role::Member);
        $userId = (int) $this->pdo()->query("SELECT id FROM users WHERE email = 'member@example.org'")->fetchColumn();
        $this->makeBook($userId, 'One', null, ['José Pérez']);
        $this->makeBook($userId, 'Two', null, ['Jose Perez']);
        $this->makeBook($userId, 'Three', null, ['JOSÉ PÉREZ']);
        self::assertSame(2, $this->countRows('SELECT COUNT(*) FROM authors'));
    }

    public function testCheckConstraintsAndJsonValidityAreEnforced(): void
    {
        $pdo = $this->pdo();
        foreach ([
            'ISBN pattern' => ["INSERT INTO wishlist_items (user_id, title, isbn_13, created_at, updated_at) VALUES (:u, 'x', '12345', UTC_TIMESTAMP(), UTC_TIMESTAMP())", Engine::CHECK_VIOLATION_ERRORS],
            'invalid JSON' => ["INSERT INTO audit_log (action, details, created_at) VALUES ('x', '{not json', UTC_TIMESTAMP())", [3140, 4025]],
        ] as $label => [$sql, $codes]) {
            $userId = $this->createUser($label === 'ISBN pattern' ? 'c1@example.org' : 'c2@example.org', Role::Member);
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute(str_contains($sql, ':u') ? ['u' => $userId] : []);
                self::fail('Accepted: ' . $label);
            } catch (PDOException $e) {
                self::assertContains($e->errorInfo[1], $codes, $label . ': ' . $e->getMessage());
            }
        }
        $pdo->exec("INSERT INTO audit_log (action, details, created_at) VALUES ('engine.test', '{\"a\": [1, \"é\"]}', UTC_TIMESTAMP())");
        self::assertSame(['a' => [1, 'é']], json_decode((string) $pdo->query("SELECT details FROM audit_log WHERE action = 'engine.test'")->fetchColumn(), true));
    }

    public function testBoundedSelectsAreInterruptedOnBothEngines(): void
    {
        $sql = Engine::boundedSelect($this->pdo(), 'SELECT COUNT(*) FROM information_schema.COLUMNS a, information_schema.COLUMNS b, information_schema.COLUMNS c WHERE a.TABLE_NAME LIKE :x', 200);
        self::assertStringStartsWith($this->engine() === Engine::MARIADB ? 'SET STATEMENT max_statement_time = 0.200 FOR SELECT ' : 'SELECT /*+ MAX_EXECUTION_TIME(200) */ ', $sql);
        $start = microtime(true);
        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute(['x' => '%']);
            $stmt->fetchAll();
            self::fail('The statement was not interrupted');
        } catch (PDOException $e) {
            self::assertTrue(Engine::isTimeout($e), (string) $e->errorInfo[1]);
        }
        self::assertLessThan(10, microtime(true) - $start, 'Interrupted near the limit, not run to completion');
        // The connection stays usable afterwards.
        self::assertSame(1, (int) $this->pdo()->query('SELECT 1')->fetchColumn());
    }

    public function testApplicationAndMigrationAccountsAreSeparateAndLeastPrivilege(): void
    {
        $config = self::config();
        $app = ConnectionFactory::fromConfig($config);
        $migration = ConnectionFactory::forMigrations($config);
        self::assertNotSame($app->query('SELECT CURRENT_USER()')->fetchColumn(), $migration->query('SELECT CURRENT_USER()')->fetchColumn());

        foreach ([
            'CREATE TABLE engine_probe (id INT PRIMARY KEY)' => [1142],
            'SELECT COUNT(*) FROM mysql.user' => [1142],
            'CREATE DATABASE engine_probe_db' => [1044],
            "GRANT SELECT ON " . $config->get('DB_DATABASE') . ".* TO CURRENT_USER()" => [1044, 1045, 1410],
        ] as $sql => $codes) {
            try {
                $app->exec($sql);
                self::fail('Application account was allowed: ' . $sql);
            } catch (PDOException $e) {
                self::assertContains($e->errorInfo[1], $codes, $sql . ': ' . $e->getMessage());
            }
        }

        $migration->exec('CREATE TABLE engine_probe (id INT PRIMARY KEY)');
        $migration->exec('DROP TABLE engine_probe');
        foreach (['CREATE DATABASE engine_probe_db' => [1044], 'SELECT COUNT(*) FROM mysql.user' => [1142]] as $sql => $codes) {
            try {
                $migration->exec($sql);
                self::fail('Migration account was allowed: ' . $sql);
            } catch (PDOException $e) {
                self::assertContains($e->errorInfo[1], $codes, $sql . ': ' . $e->getMessage());
            }
        }
        // Neither account sees any schema but its own (dedicated test
        // accounts; MySQL lists performance_schema to every account).
        foreach ([$app, $migration] as $pdo) {
            $visible = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME NOT IN ('information_schema', 'performance_schema') ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
            self::assertSame([$pdo->query('SELECT DATABASE()')->fetchColumn()], $visible, 'Only the test schema is visible');
        }
    }

    private function assertWishlistBehaviour(): void
    {
        $pdo = $this->pdo();
        $email = 'wish' . bin2hex(random_bytes(3)) . '@example.org';
        $userId = $this->createUser($email, Role::Member);
        $insert = $pdo->prepare("INSERT INTO wishlist_items (user_id, title, isbn_13, status, created_at, updated_at) VALUES (:u, 't', :i, :s, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $insert->execute(['u' => $userId, 'i' => '9788437604947', 's' => 'wanted']);
        $id = (int) $pdo->lastInsertId();
        $row = $pdo->query("SELECT isbn_13, wanted_isbn_13, LENGTH(wanted_isbn_13) AS len, wanted_isbn_13 = isbn_13 AS same FROM wishlist_items WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('9788437604947', rtrim((string) $row['wanted_isbn_13']), 'Generated value');
        self::assertSame(13, (int) $row['len'], 'No padding stored');
        self::assertSame(1, (int) $row['same'], 'Compares equal to the source column');
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM wishlist_items WHERE wanted_isbn_13 = '9788437604947' AND user_id = {$userId}")->fetchColumn(), 'Lookup by literal');

        try {
            $insert->execute(['u' => $userId, 'i' => '9788437604947', 's' => 'wanted']);
            self::fail('A second wanted copy of the same ISBN was accepted');
        } catch (PDOException $e) {
            self::assertSame(1062, $e->errorInfo[1]);
            self::assertStringContainsString('uq_wishlist_wanted_isbn', $e->getMessage());
        }
        $insert->execute(['u' => $userId, 'i' => '9788437604947', 's' => 'acquired']);
        $insert->execute(['u' => $userId, 'i' => '9788437604947', 's' => 'archived']);
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM wishlist_items WHERE user_id = {$userId} AND wanted_isbn_13 IS NOT NULL"), 'Only wanted rows are generated');
        $pdo->exec("UPDATE wishlist_items SET status = 'acquired' WHERE id = {$id}");
        self::assertNull($pdo->query("SELECT wanted_isbn_13 FROM wishlist_items WHERE id = {$id}")->fetchColumn());
        $insert->execute(['u' => $userId, 'i' => '9788437604947', 's' => 'wanted']);

        $plan = $pdo->query("EXPLAIN SELECT id FROM wishlist_items WHERE user_id = {$userId} AND wanted_isbn_13 = '9788437604947'")->fetch(PDO::FETCH_ASSOC);
        self::assertContains('uq_wishlist_wanted_isbn', explode(',', (string) ($plan['possible_keys'] ?? '')), 'The unique index serves lookups');
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function console(array $args): array
    {
        // No APP_ROLE: development and testing may run operations commands.
        if ($this->tmp === null) {
            $this->tmp = sys_get_temp_dir() . '/dla-engine-test-' . bin2hex(random_bytes(6));
            mkdir($this->tmp, 0700);
        }
        $services = new Services(self::config()->with('TEMP_PATH', $this->tmp), dirname(__DIR__, 2));
        $services->set(PDO::class, $this->pdo());
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $code = (new Console($services, fopen('php://memory', 'r'), $out, $err))->run(array_merge(['console'], $args));
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }
}
