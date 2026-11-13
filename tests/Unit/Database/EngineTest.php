<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Database;

use DigitalLibrary\Database\Engine;
use DigitalLibrary\Database\Migrator;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EngineTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            array_map('unlink', array_merge(glob($this->dir . '/*.sql') ?: [], glob($this->dir . '/mariadb/*.sql') ?: []));
            @rmdir($this->dir . '/mariadb');
            @rmdir($this->dir);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function versions(): iterable
    {
        yield 'official image' => ['11.4.13-MariaDB-ubu2404', Engine::MARIADB];
        yield 'plain' => ['11.4.5-MariaDB', Engine::MARIADB];
        yield 'debian package' => ['10.11.6-MariaDB-0+deb12u1', Engine::MARIADB];
        yield 'with log suffix' => ['11.4.13-MariaDB-log', Engine::MARIADB];
        yield 'mysql' => ['8.4.13', Engine::MYSQL];
        yield 'mysql with suffix' => ['8.0.36-0ubuntu0.22.04.1', Engine::MYSQL];
        yield 'mariadb mentioned elsewhere is not positive' => ['8.4.13-not-MariaDB-really x', Engine::MYSQL];
        yield 'empty' => ['', Engine::MYSQL];
    }

    #[DataProvider('versions')]
    public function testMariaDbIsRecognisedOnlyPositively(string $version, string $engine): void
    {
        self::assertSame($engine, Engine::fromVersion($version));
    }

    private function pdo(string $version): PDO
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn($version);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($stmt);

        return $pdo;
    }

    public function testBoundedSelectUsesEachEnginesOwnLimit(): void
    {
        $select = 'SELECT a FROM t WHERE b = :b';
        self::assertSame('SET STATEMENT max_statement_time = 5.000 FOR SELECT a FROM t WHERE b = :b', Engine::boundedSelect($this->pdo('11.4.13-MariaDB'), $select, 5000));
        self::assertSame('SET STATEMENT max_statement_time = 0.250 FOR SELECT a FROM t WHERE b = :b', Engine::boundedSelect($this->pdo('11.4.13-MariaDB'), $select, 250));
        self::assertSame('SELECT /*+ MAX_EXECUTION_TIME(5000) */ a FROM t WHERE b = :b', Engine::boundedSelect($this->pdo('8.4.13'), $select, 5000));
        $this->expectException(\InvalidArgumentException::class);
        Engine::boundedSelect($this->pdo('8.4.13'), 'DELETE FROM t', 5000);
    }

    public function testTimeoutAndCheckViolationCodesOfBothEngines(): void
    {
        foreach ([3024 => true, 1969 => true, 1317 => true, 1062 => false] as $code => $timeout) {
            $e = new \PDOException('x');
            $e->errorInfo = ['HY000', $code, 'x'];
            self::assertSame($timeout, Engine::isTimeout($e), (string) $code);
        }
        self::assertSame([3819, 4025], Engine::CHECK_VIOLATION_ERRORS);
    }

    public function testTheMigratorSelectsOneVariantPerEngine(): void
    {
        $this->dir = sys_get_temp_dir() . '/dla-variants-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/mariadb', 0700, true);
        file_put_contents($this->dir . '/2026_01_01_000001_first.sql', "CREATE TABLE a (id INT);\n");
        file_put_contents($this->dir . '/2026_01_01_000002_second.sql', "CREATE TABLE b (id CHAR(1));\n");
        file_put_contents($this->dir . '/mariadb/2026_01_01_000002_second.sql', "-- MariaDB\nCREATE TABLE b (id VARCHAR(1));\n");

        $mariadb = new Migrator($this->pdo('11.4.13-MariaDB-ubu2404'), $this->dir);
        $mysql = new Migrator($this->pdo('8.4.13'), $this->dir);
        self::assertSame($this->dir . '/mariadb/2026_01_01_000002_second.sql', $mariadb->fileFor('2026_01_01_000002_second'));
        self::assertSame($this->dir . '/2026_01_01_000002_second.sql', $mysql->fileFor('2026_01_01_000002_second'));
        self::assertSame($this->dir . '/2026_01_01_000001_first.sql', $mariadb->fileFor('2026_01_01_000001_first'), 'No override: the released file');
        self::assertSame(['2026_01_01_000001_first', '2026_01_01_000002_second'], array_keys($mariadb->available()), 'Overrides are never extra versions');
        $checksums = $mysql->checksums('2026_01_01_000002_second');
        self::assertSame(hash('sha256', "CREATE TABLE b (id CHAR(1));\n"), $checksums['mysql']);
        self::assertSame(hash('sha256', "-- MariaDB\nCREATE TABLE b (id VARCHAR(1));\n"), $checksums['mariadb']);
        self::assertSame($checksums['mysql'] === $checksums['mariadb'], false);
        $first = $mysql->checksums('2026_01_01_000001_first');
        self::assertSame($first['mysql'], $first['mariadb'], 'Without an override both engines expect the same file');

        file_put_contents($this->dir . '/mariadb/2026_01_01_000003_orphan.sql', 'SELECT 1;');
        $this->expectExceptionMessage('has no released migration of the same name');
        $mariadb->mariadbOverrides();
    }
}
