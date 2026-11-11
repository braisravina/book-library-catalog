<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Testing;

use DigitalLibrary\Core\Config;
use DigitalLibrary\Tests\Support\TestDatabaseGuard;
use DigitalLibrary\Tests\Support\TestDatabaseSafetyException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TestDatabaseGuardTest extends TestCase
{
    private const BASE = [
        'DB_DATABASE' => 'library',
        'DB_TEST_DATABASE' => 'library_test',
        'DB_USERNAME' => 'library_app',
        'DB_PASSWORD' => 'app-secret',
        'DB_MIGRATION_USERNAME' => 'library_migrator',
        'DB_MIGRATION_PASSWORD' => 'migrator-secret',
    ];

    public function testSharedAccountsTargetOnlyTheTestDatabase(): void
    {
        $config = TestDatabaseGuard::testConfig(new Config(self::BASE), 'development', true);

        self::assertSame('library_test', $config->get('DB_DATABASE'));
        self::assertSame('library_app', $config->get('DB_USERNAME'));
        self::assertFalse(TestDatabaseGuard::usesDedicatedAccounts(new Config(self::BASE)));
    }

    public function testDedicatedAccountsReplaceTheApplicationCredentials(): void
    {
        $base = new Config(self::BASE + self::dedicated());
        $config = TestDatabaseGuard::testConfig($base, 'development', true);

        self::assertSame('library_test', $config->get('DB_DATABASE'));
        self::assertSame('library_test_app', $config->get('DB_USERNAME'));
        self::assertSame('test-app-secret', $config->get('DB_PASSWORD'));
        self::assertSame('library_test_migrator', $config->get('DB_MIGRATION_USERNAME'));
        self::assertSame('test-migrator-secret', $config->get('DB_MIGRATION_PASSWORD'));
        self::assertTrue(TestDatabaseGuard::usesDedicatedAccounts($base));
    }

    public function testNoEnvironmentFileIsAllowed(): void
    {
        // CI supplies everything through the process environment.
        $config = TestDatabaseGuard::testConfig(new Config(['DB_TEST_DATABASE' => 'library_test'] + self::dedicated()), null, false);

        self::assertSame('library_test', $config->get('DB_DATABASE'));
    }

    /**
     * @return iterable<string, array{array<string, string>, ?string, bool, string}>
     */
    public static function unsafe(): iterable
    {
        yield 'production environment file' => [self::BASE, 'production', true, 'APP_ENV=production'];
        yield 'environment file without APP_ENV (= production)' => [self::BASE, null, true, 'production'];
        yield 'unknown environment' => [self::BASE, 'staging', true, 'APP_ENV=staging'];
        yield 'missing test database' => [['DB_TEST_DATABASE' => ''] + self::BASE, 'development', true, 'not configured'];
        yield 'test database is the application database' => [['DB_TEST_DATABASE' => 'library'] + self::BASE, 'development', true, 'must be a lower-case identifier ending in "_test"'];
        yield 'same name, different case' => [['DB_DATABASE' => 'LIBRARY_TEST'] + self::BASE, 'development', true, 'must differ from DB_DATABASE'];
        yield 'identical names ending in _test' => [['DB_DATABASE' => 'library_test'] + self::BASE, 'development', true, 'must differ from DB_DATABASE'];
        yield 'no _test suffix' => [['DB_TEST_DATABASE' => 'library_testing'] + self::BASE, 'development', true, 'ending in "_test"'];
        yield 'suffix only' => [['DB_TEST_DATABASE' => '_test'] + self::BASE, 'development', true, 'ending in "_test"'];
        yield 'quoted identifier' => [['DB_TEST_DATABASE' => 'library`; DROP DATABASE library; -- _test'] + self::BASE, 'development', true, 'ending in "_test"'];
        yield 'upper case' => [['DB_TEST_DATABASE' => 'Library_Test'] + self::BASE, 'development', true, 'lower-case'];
        yield 'too long' => [['DB_TEST_DATABASE' => str_repeat('a', 60) . '_test'] + self::BASE, 'development', true, 'ending in "_test"'];
        yield 'only the test app account' => [['DB_TEST_USERNAME' => 'library_test_app', 'DB_TEST_PASSWORD' => 'x'] + self::BASE, 'development', true, 'or neither'];
        yield 'only the test migration account' => [['DB_TEST_MIGRATION_USERNAME' => 'library_test_migrator', 'DB_TEST_MIGRATION_PASSWORD' => 'x'] + self::BASE, 'development', true, 'or neither'];
        yield 'test account reuses the app account' => [['DB_TEST_USERNAME' => 'library_app'] + self::dedicated() + self::BASE, 'development', true, 'must differ from DB_USERNAME'];
        yield 'test account reuses the migration account' => [['DB_TEST_MIGRATION_USERNAME' => 'LIBRARY_MIGRATOR'] + self::dedicated() + self::BASE, 'development', true, 'must differ from DB_USERNAME'];
        yield 'one account for both test roles' => [['DB_TEST_MIGRATION_USERNAME' => 'library_test_app'] + self::dedicated() + self::BASE, 'development', true, 'must differ'];
        yield 'missing test password' => [['DB_TEST_PASSWORD' => ''] + self::dedicated() + self::BASE, 'development', true, 'are required'];
        yield 'dedicated accounts required but absent' => [['DB_TEST_REQUIRE_DEDICATED_ACCOUNTS' => 'true'] + self::BASE, 'development', true, 'DB_TEST_REQUIRE_DEDICATED_ACCOUNTS'];
    }

    /**
     * @param array<string, string> $values
     */
    #[DataProvider('unsafe')]
    public function testRefusesUnsafeConfiguration(array $values, ?string $appEnv, bool $envFileExists, string $message): void
    {
        $this->expectException(TestDatabaseSafetyException::class);
        $this->expectExceptionMessage($message);

        TestDatabaseGuard::testConfig(new Config($values), $appEnv, $envFileExists);
    }

    public function testNeverLeaksPasswordsInErrors(): void
    {
        try {
            TestDatabaseGuard::testConfig(new Config(['DB_TEST_USERNAME' => 'library_app'] + self::dedicated() + self::BASE), 'development', true);
            self::fail('Expected a safety exception');
        } catch (TestDatabaseSafetyException $e) {
            foreach (['app-secret', 'migrator-secret', 'test-app-secret', 'test-migrator-secret'] as $secret) {
                self::assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private static function dedicated(): array
    {
        return [
            'DB_TEST_USERNAME' => 'library_test_app',
            'DB_TEST_PASSWORD' => 'test-app-secret',
            'DB_TEST_MIGRATION_USERNAME' => 'library_test_migrator',
            'DB_TEST_MIGRATION_PASSWORD' => 'test-migrator-secret',
        ];
    }
}
