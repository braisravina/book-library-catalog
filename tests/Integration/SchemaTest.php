<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Database\Migrator;
use DigitalLibrary\Database\Transaction;
use DigitalLibrary\Security\Role;
use PDO;
use PDOException;

final class SchemaTest extends IntegrationTestCase
{
    public function testMigrationsAreAppliedAndIdempotent(): void
    {
        $migrator = new Migrator($this->migrationPdo(), dirname(__DIR__, 2) . '/database/migrations');
        self::assertSame([], $migrator->migrate(), 'Second run applies nothing');
        foreach ($migrator->status() as $row) {
            self::assertTrue($row['applied'], $row['version']);
            self::assertFalse($row['modified'], $row['version']);
        }
    }

    public function testRolesAndDefaultSettingsAreSeeded(): void
    {
        $roles = $this->pdo()->query('SELECT code FROM roles ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(array_column(Role::cases(), 'value'), $roles);

        $mode = $this->pdo()->query("SELECT JSON_UNQUOTE(setting_value) FROM settings WHERE setting_key = 'registration.mode'")->fetchColumn();
        self::assertSame('disabled', $mode, 'Public registration is disabled by default');
    }

    public function testEditionIsbnsAreOptionalButUnique(): void
    {
        $work = $this->insertWork('Rayuela');
        $this->insertEdition($work, null);
        $this->insertEdition($work, null);
        $this->insertEdition($work, '9788437604947');

        $this->expectException(PDOException::class);
        $this->insertEdition($work, '9788437604947');
    }

    public function testCheckConstraintRejectsMalformedIsbn(): void
    {
        $work = $this->insertWork('Bad data');
        $this->expectException(PDOException::class);
        $this->insertEdition($work, '12345');
    }

    public function testSeveralOwnersCanHoldCopiesOfOneEdition(): void
    {
        $alice = $this->createUser('alice@example.org', Role::Member);
        $bob = $this->createUser('bob@example.org', Role::Member);
        $edition = $this->insertEdition($this->insertWork('Cien años de soledad'), '9780307474728');

        $root = $this->insertLocation(null, 'House');
        $shelf = $this->insertLocation($root, 'Study shelf 1');
        foreach ([[$alice, $root], [$alice, $shelf], [$bob, null]] as [$owner, $location]) {
            $this->pdo()->prepare('INSERT INTO copies (edition_id, owner_user_id, location_id, created_at, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
                ->execute([$edition, $owner, $location]);
        }

        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM editions')->fetchColumn());
        self::assertSame(3, (int) $this->pdo()->query("SELECT COUNT(*) FROM copies WHERE edition_id = {$edition}")->fetchColumn());
    }

    public function testCopyOwnersCannotBeDeletedWhileHoldingCopies(): void
    {
        $owner = $this->createUser('owner@example.org', Role::Member);
        $edition = $this->insertEdition($this->insertWork('Os Eoas'), null);
        $this->pdo()->prepare('INSERT INTO copies (edition_id, owner_user_id, created_at, updated_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute([$edition, $owner]);

        $this->expectException(PDOException::class);
        $this->pdo()->exec("DELETE FROM users WHERE id = {$owner}");
    }

    public function testOnlyOnePrimaryCoverPerEdition(): void
    {
        $edition = $this->insertEdition($this->insertWork('Cover test'), null);
        $insert = $this->pdo()->prepare(
            "INSERT INTO covers (edition_id, storage_key, mime_type, width, height, byte_size, sha256, is_primary, created_at)
             VALUES (?, ?, 'image/jpeg', 10, 10, 100, REPEAT('a', 64), ?, UTC_TIMESTAMP())"
        );
        $insert->execute([$edition, 'k1', 1]);
        $insert->execute([$edition, 'k2', 0]);
        $insert->execute([$edition, 'k3', 0]);

        $this->expectException(PDOException::class);
        $insert->execute([$edition, 'k4', 1]);
    }

    public function testTransactionRollsBackOnFailure(): void
    {
        try {
            Transaction::run($this->pdo(), function (PDO $pdo): void {
                $this->insertWork('Rolled back');
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM works')->fetchColumn());
    }

    public function testRuntimeAccountCannotChangeSchema(): void
    {
        try {
            $this->pdo()->exec('CREATE TABLE should_not_exist (id INT)');
            self::fail('The application account must not have DDL privileges');
        } catch (PDOException $e) {
            self::assertStringContainsString('denied', strtolower($e->getMessage()));
        }
        try {
            $this->pdo()->exec('DROP TABLE audit_log');
            self::fail('The application account must not be able to drop tables');
        } catch (PDOException $e) {
            self::assertStringContainsString('denied', strtolower($e->getMessage()));
        }
    }

    private function insertWork(string $title): int
    {
        $this->pdo()->prepare('INSERT INTO works (title, created_at, updated_at) VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute([$title]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function insertEdition(int $work, ?string $isbn13): int
    {
        $this->pdo()->prepare('INSERT INTO editions (work_id, title, isbn_13, created_at, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute([$work, 'Edition', $isbn13]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function insertLocation(?int $parent, string $name): int
    {
        $this->pdo()->prepare('INSERT INTO locations (parent_id, name, created_at, updated_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute([$parent, $name]);

        return (int) $this->pdo()->lastInsertId();
    }
}
