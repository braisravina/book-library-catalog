<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Tests\Support\TestDatabaseGuard;
use DigitalLibrary\Tests\Support\TestDatabaseSafetyException;

/**
 * The live connections used by the destructive integration suite point at
 * the test database only. Read-only: never touches the application database.
 */
final class TestDatabaseIsolationTest extends IntegrationTestCase
{
    public function testBothConnectionsReportTheTestDatabase(): void
    {
        $test = (string) self::config()->get('DB_DATABASE');
        $application = self::baseConfig()->get('DB_DATABASE');

        self::assertStringEndsWith(TestDatabaseGuard::SUFFIX, $test);
        self::assertNotSame($application, $test);
        self::assertSame($test, $this->pdo()->query('SELECT DATABASE()')->fetchColumn());
        self::assertSame($test, $this->migrationPdo()->query('SELECT DATABASE()')->fetchColumn());
    }

    public function testAnUnexpectedCurrentDatabaseIsRefused(): void
    {
        $this->expectException(TestDatabaseSafetyException::class);
        $this->expectExceptionMessage('expected "some_other_test"');

        TestDatabaseGuard::verifyConnection($this->pdo(), 'some_other_test', null, false);
    }

    public function testApplicationDatabaseVisibilityMatchesTheAccountModel(): void
    {
        $test = (string) self::config()->get('DB_DATABASE');
        $application = self::baseConfig()->get('DB_DATABASE') ?? 'library';
        $dedicated = TestDatabaseGuard::usesDedicatedAccounts(self::baseConfig());

        foreach ([$this->pdo(), $this->migrationPdo()] as $pdo) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :name');
            $stmt->execute(['name' => $application]);
            $visible = (int) $stmt->fetchColumn() > 0;
            if ($dedicated) {
                self::assertFalse($visible, 'Dedicated test accounts must not see the application database');
            }
            // The guard's privilege check refuses exactly when the application database is visible.
            try {
                TestDatabaseGuard::verifyConnection($pdo, $test, $application, true);
                self::assertFalse($visible);
            } catch (TestDatabaseSafetyException $e) {
                self::assertTrue($visible, $e->getMessage());
            }
        }
    }

    public function testTestsCannotOverrideDatabaseSettings(): void
    {
        self::$configOverrides = ['DB_DATABASE' => 'library'];
        try {
            self::config();
            self::fail('A database override was accepted');
        } catch (TestDatabaseSafetyException $e) {
            self::assertStringContainsString('may not override DB_DATABASE', $e->getMessage());
        } finally {
            self::$configOverrides = [];
        }
    }
}
