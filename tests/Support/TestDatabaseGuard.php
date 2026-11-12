<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Support;

use DigitalLibrary\Core\Config;
use PDO;

/**
 * Fail-closed safeguards for the integration suite, which drops and
 * truncates every table in the database it runs against.
 *
 * Before any connection is opened, testConfig() refuses to continue unless:
 *  - the environment file (if any) does not declare a production
 *    environment;
 *  - DB_TEST_DATABASE is a plain identifier ending in "_test";
 *  - DB_TEST_DATABASE differs from DB_DATABASE;
 *  - dedicated test accounts (DB_TEST_USERNAME / DB_TEST_MIGRATION_USERNAME),
 *    when configured, are complete and differ from the application's
 *    accounts. DB_TEST_REQUIRE_DEDICATED_ACCOUNTS=true makes them mandatory.
 *
 * After connecting, verifyConnection() checks that the server reports the
 * expected current database and, with dedicated accounts, that the
 * development database is not even visible to the test accounts.
 *
 * Violations throw TestDatabaseSafetyException, which the suite reports as
 * an error (never as a skipped test).
 */
final class TestDatabaseGuard
{
    public const SUFFIX = '_test';

    /**
     * @param string|null $declaredAppEnv APP_ENV as written in the environment
     *                                    file, before PHPUnit forces "testing";
     *                                    null when no environment file exists
     */
    public static function testConfig(Config $base, ?string $declaredAppEnv, bool $envFileExists): Config
    {
        if ($envFileExists && !in_array($declaredAppEnv, ['development', 'testing'], true)) {
            throw new TestDatabaseSafetyException(sprintf(
                'Refusing to run integration tests: the environment file declares APP_ENV=%s. '
                . 'Only "development" or "testing" environments may run the destructive integration suite.',
                $declaredAppEnv ?? '(unset, which means production)',
            ));
        }

        $test = $base->get('DB_TEST_DATABASE');
        if ($test === null) {
            throw new TestDatabaseSafetyException('DB_TEST_DATABASE is not configured.');
        }
        if (preg_match('/^[a-z0-9_]{1,59}' . self::SUFFIX . '$/', $test) !== 1) {
            throw new TestDatabaseSafetyException(sprintf(
                'Refusing to run integration tests: DB_TEST_DATABASE "%s" must be a lower-case identifier ending in "%s".',
                self::printable($test),
                self::SUFFIX,
            ));
        }
        $dev = $base->get('DB_DATABASE');
        if ($dev !== null && strcasecmp($dev, $test) === 0) {
            throw new TestDatabaseSafetyException(
                'Refusing to run integration tests: DB_TEST_DATABASE must differ from DB_DATABASE (the application database).'
            );
        }

        $config = $base->with('DB_DATABASE', $test);
        $accounts = self::dedicatedAccounts($base);
        if ($accounts !== null) {
            foreach ($accounts as $key => $value) {
                $config = $config->with($key, $value);
            }
        } elseif ($base->bool('DB_TEST_REQUIRE_DEDICATED_ACCOUNTS')) {
            throw new TestDatabaseSafetyException(
                'Refusing to run integration tests: DB_TEST_REQUIRE_DEDICATED_ACCOUNTS is set but '
                . 'DB_TEST_USERNAME / DB_TEST_MIGRATION_USERNAME are not configured.'
            );
        }

        return $config;
    }

    public static function usesDedicatedAccounts(Config $base): bool
    {
        return self::dedicatedAccounts($base) !== null;
    }

    /**
     * Checks a live connection opened with the configuration from testConfig().
     */
    public static function verifyConnection(PDO $pdo, string $expectedDatabase, ?string $applicationDatabase, bool $dedicatedAccounts): void
    {
        $current = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($current !== $expectedDatabase) {
            throw new TestDatabaseSafetyException(sprintf(
                'Refusing to run integration tests: the connection reports database "%s", expected "%s".',
                self::printable((string) $current),
                $expectedDatabase,
            ));
        }
        if ($dedicatedAccounts && $applicationDatabase !== null && strcasecmp($applicationDatabase, $expectedDatabase) !== 0) {
            // information_schema lists only schemas the account holds privileges on.
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :name');
            $stmt->execute(['name' => $applicationDatabase]);
            if ((int) $stmt->fetchColumn() !== 0) {
                throw new TestDatabaseSafetyException(sprintf(
                    'Refusing to run integration tests: the dedicated test account can access the application database "%s". '
                    . 'Grant test accounts privileges on the test database only.',
                    $applicationDatabase,
                ));
            }
        }
    }

    /**
     * @return array<string, string>|null credentials to use instead of the application's, or null if not configured
     */
    private static function dedicatedAccounts(Config $base): ?array
    {
        $app = $base->get('DB_TEST_USERNAME');
        $migrator = $base->get('DB_TEST_MIGRATION_USERNAME');
        if ($app === null && $migrator === null) {
            return null;
        }
        if ($app === null || $migrator === null) {
            throw new TestDatabaseSafetyException('Configure both DB_TEST_USERNAME and DB_TEST_MIGRATION_USERNAME, or neither.');
        }
        $production = array_filter([$base->get('DB_USERNAME'), $base->get('DB_MIGRATION_USERNAME')], static fn (?string $v): bool => $v !== null);
        foreach ([$app, $migrator] as $user) {
            foreach ($production as $other) {
                if (strcasecmp($user, $other) === 0) {
                    throw new TestDatabaseSafetyException('Dedicated test accounts must differ from DB_USERNAME and DB_MIGRATION_USERNAME.');
                }
            }
        }
        if (strcasecmp($app, $migrator) === 0) {
            throw new TestDatabaseSafetyException('DB_TEST_USERNAME and DB_TEST_MIGRATION_USERNAME must differ.');
        }

        try {
            $appPassword = $base->require('DB_TEST_PASSWORD');
            $migratorPassword = $base->require('DB_TEST_MIGRATION_PASSWORD');
        } catch (\Throwable) {
            throw new TestDatabaseSafetyException('DB_TEST_PASSWORD and DB_TEST_MIGRATION_PASSWORD are required with dedicated test accounts.');
        }

        return [
            'DB_USERNAME' => $app,
            'DB_PASSWORD' => $appPassword,
            'DB_MIGRATION_USERNAME' => $migrator,
            'DB_MIGRATION_PASSWORD' => $migratorPassword,
        ];
    }

    private static function printable(string $value): string
    {
        return (string) preg_replace('/[^\x20-\x7E]/', '?', mb_strimwidth($value, 0, 80, '…'));
    }
}
