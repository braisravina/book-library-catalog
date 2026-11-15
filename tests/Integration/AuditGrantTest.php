<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Audit\AuditLogger;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Database\ConnectionFactory;
use DigitalLibrary\Households\HouseholdInput;
use DigitalLibrary\Security\Role;
use DigitalLibrary\Tests\Support\TestDatabaseGuard;
use PDO;
use PDOException;

final class AuditGrantTest extends IntegrationTestCase
{
    public function testRuntimeCannotChangeExistingAuditRowsIncludingDuplicateInsertForms(): void
    {
        $actor = $this->createUser('actor@example.org', Role::Admin);
        (new AuditLogger($this->pdo()))->log('test.retained', $actor, 'user', $actor);
        $id = (int) $this->pdo()->lastInsertId();
        $before = $this->pdo()->query('SELECT * FROM audit_log ORDER BY id')->fetchAll();
        self::assertCount(1, $before, 'INSERT and SELECT work under real runtime grants.');
        foreach ([
            "UPDATE audit_log SET action='changed' WHERE id={$id}",
            "DELETE FROM audit_log WHERE id={$id}",
            "REPLACE INTO audit_log (id, action, created_at) VALUES ({$id}, 'changed', UTC_TIMESTAMP())",
            "INSERT INTO audit_log (id, action, created_at) VALUES ({$id}, 'changed', UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE action='changed'",
            'TRUNCATE TABLE audit_log',
            'DROP TABLE audit_log',
            'ALTER TABLE audit_log ADD COLUMN forbidden INT NULL',
            "DELETE FROM users WHERE id={$actor}",
            "REPLACE INTO users SELECT * FROM users WHERE id={$actor}",
        ] as $sql) {
            $this->denied($sql);
            self::assertSame($before, $this->pdo()->query('SELECT * FROM audit_log ORDER BY id')->fetchAll(), $sql);
        }
        // Prove the fixture is capable of the dangerous referential action:
        // no other RESTRICT reference masks audit's ON DELETE SET NULL.
        $this->migrationPdo()->exec("DELETE FROM users WHERE id={$actor}");
        self::assertNull($this->pdo()->query("SELECT user_id FROM audit_log WHERE id={$id}")->fetchColumn());
    }

    public function testNewTablesAreReadableForBackupsButNotAutomaticallyWritable(): void
    {
        $this->migrationPdo()->exec('CREATE TABLE grant_new_table (id INT PRIMARY KEY) ENGINE=InnoDB');
        try {
            self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM grant_new_table')->fetchColumn());
            $this->denied('INSERT INTO grant_new_table VALUES (1)');
            $this->denied('UPDATE grant_new_table SET id=2');
            $this->denied('DELETE FROM grant_new_table');
        } finally {
            $this->migrationPdo()->exec('DROP TABLE grant_new_table');
        }
    }

    public function testReadOnlyRolesSupportRequiredLocksWithoutWritePrivileges(): void
    {
        $actor = $this->createUser('admin@example.org', Role::Admin);
        $this->denied("UPDATE roles SET code=code WHERE id=1");
        $this->denied('DELETE FROM roles WHERE id=999999');
        $this->pdo()->beginTransaction();
        try {
            self::assertNotEmpty($this->pdo()->query('SELECT code FROM roles LOCK IN SHARE MODE')->fetchAll());
            $s = new Services(self::config(), dirname(__DIR__, 2));
            $s->set(PDO::class, $this->pdo());
            self::assertContains($actor, $s->users()->lockActiveAdminIds());
        } finally {
            $this->pdo()->rollBack();
        }
    }

    public function testGenuineMissingAuditInsertPrivilegeRollsBackDomainMutation(): void
    {
        self::$configOverrides['HOUSEHOLDS_ENABLED'] = 'true';
        $actor = $this->createUser('admin@example.org', Role::Admin);
        $config = self::config();
        // Test-only account provisioned with the same manifest, except audit INSERT.
        $pdo = ConnectionFactory::create($config, $config->require('DB_USERNAME') . '_no_audit', $config->require('DB_PASSWORD'));
        TestDatabaseGuard::verifyConnection($pdo, $config->require('DB_DATABASE'), self::baseConfig()->get('DB_DATABASE'), true);
        $s = new Services($config, dirname(__DIR__, 2));
        $s->set(PDO::class, $pdo);
        $admin = $s->users()->findById($actor);
        $before = $this->countRows('SELECT COUNT(*) FROM households');
        try {
            $s->householdService()->create($admin, new HouseholdInput('Must roll back', ''));
            self::fail('Missing audit INSERT was ignored.');
        } catch (PDOException $e) {
            self::assertSame(1142, $e->errorInfo[1]);
            self::assertStringContainsString('audit_log', $e->errorInfo[2], 'Failure must be at the audit INSERT, after the domain INSERT.');
        }
        self::assertFalse($pdo->inTransaction());
        self::assertSame($before, $this->countRows('SELECT COUNT(*) FROM households'));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action='household.created'"));
    }

    private function denied(string $sql): void
    {
        try {
            $this->pdo()->exec($sql);
            self::fail('Runtime unexpectedly allowed: ' . $sql);
        } catch (PDOException $e) {
            self::assertContains($e->errorInfo[1], [1142, 1143], $sql);
        }
    }
}
