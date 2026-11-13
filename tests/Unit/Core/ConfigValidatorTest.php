<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Core;

use DigitalLibrary\Core\Config;
use DigitalLibrary\Core\ConfigValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigValidatorTest extends TestCase
{
    private const PRODUCTION = [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_URL' => 'https://library.bravina.co.uk',
        'SESSION_SECURE_COOKIE' => 'true',
        'SECURITY_HSTS' => 'true',
        'DB_HOST' => 'db',
        'DB_PORT' => '3306',
        'DB_DATABASE' => 'library',
        'DB_USERNAME' => 'library_app',
        'DB_PASSWORD' => 'Zq8vN3kP7wL2xR9tY4mB6cH1',
        'DB_MIGRATION_USERNAME' => 'library_migrator',
        'DB_MIGRATION_PASSWORD' => 'Fj5sD8gK2hQ7nV4bX1cM9pW3',
        'INVITATION_LINK_FORMAT' => 'fragment',
        'METADATA_PROVIDERS' => 'open_library,google_books',
    ];

    private const DEVELOPMENT = [
        'APP_ENV' => 'development',
        'APP_URL' => 'http://books.test',
        'SESSION_SECURE_COOKIE' => 'false',
        'SECURITY_HSTS' => 'false',
        'DB_DATABASE' => 'library',
        'DB_USERNAME' => 'library_app',
        'DB_PASSWORD' => 'devpassword',
        'DB_MIGRATION_USERNAME' => 'library_migrator',
        'DB_MIGRATION_PASSWORD' => 'devmigrator',
    ];

    public function testValidProductionConfiguration(): void
    {
        $report = $this->validate(self::PRODUCTION);

        self::assertSame([], $report['errors']);
        self::assertSame([], $report['warnings']);
        self::assertNotEmpty($report['notes']);
    }

    public function testLocalHttpDevelopmentIsValid(): void
    {
        $report = $this->validate(self::DEVELOPMENT);

        self::assertSame([], $report['errors'], 'Local development must not require HTTPS');
    }

    /**
     * @return iterable<string, array{array<string, string|null>, string, string}>
     */
    public static function productionErrors(): iterable
    {
        yield 'APP_URL missing' => [['APP_URL' => null], 'APP_URL', 'required in production'];
        yield 'APP_URL without scheme' => [['APP_URL' => 'library.bravina.co.uk'], 'APP_URL', 'absolute'];
        yield 'APP_URL ftp' => [['APP_URL' => 'ftp://library.bravina.co.uk'], 'APP_URL', 'absolute'];
        yield 'APP_URL over http' => [['APP_URL' => 'http://library.bravina.co.uk'], 'APP_URL', 'https://'];
        yield 'APP_URL with credentials' => [['APP_URL' => 'https://user:pw@library.bravina.co.uk'], 'APP_URL', 'credentials'];
        yield 'APP_URL with query' => [['APP_URL' => 'https://library.bravina.co.uk/?x=1'], 'APP_URL', 'query string or fragment'];
        yield 'APP_URL with empty query' => [['APP_URL' => 'https://library.bravina.co.uk?'], 'APP_URL', 'query string or fragment'];
        yield 'APP_URL with fragment' => [['APP_URL' => 'https://library.bravina.co.uk#top'], 'APP_URL', 'query string or fragment'];
        yield 'APP_URL with path' => [['APP_URL' => 'https://library.bravina.co.uk/library'], 'APP_URL', 'site root'];
        yield 'APP_URL with bad host' => [['APP_URL' => 'https://bad_host.example'], 'APP_URL', 'valid host name'];
        yield 'insecure cookies' => [['SESSION_SECURE_COOKIE' => 'false'], 'SESSION_SECURE_COOKIE', 'must be true'];
        yield 'unrecognised boolean' => [['SESSION_SECURE_COOKIE' => 'ture'], 'SESSION_SECURE_COOKIE', 'true or false'];
        yield 'HSTS off' => [['SECURITY_HSTS' => 'false'], 'SECURITY_HSTS', 'must be true'];
        yield 'HSTS unset' => [['SECURITY_HSTS' => null], 'SECURITY_HSTS', 'must be true'];
        yield 'placeholder password' => [['DB_PASSWORD' => 'CHANGE_ME_app'], 'DB_PASSWORD', 'placeholder'];
        yield 'placeholder migration password' => [['DB_MIGRATION_PASSWORD' => 'changeme'], 'DB_MIGRATION_PASSWORD', 'placeholder'];
        yield 'placeholder API key' => [['GOOGLE_BOOKS_API_KEY' => 'CHANGE-ME'], 'GOOGLE_BOOKS_API_KEY', 'placeholder'];
        yield 'missing password' => [['DB_PASSWORD' => null], 'DB_PASSWORD', 'required'];
        yield 'missing database' => [['DB_DATABASE' => null], 'DB_DATABASE', 'required'];
        yield 'odd database name' => [['DB_DATABASE' => 'library`; --'], 'DB_DATABASE', 'plain database name'];
        yield 'migrator without password' => [['DB_MIGRATION_PASSWORD' => null], 'DB_MIGRATION_PASSWORD', 'required when'];
        yield 'root application account' => [['DB_USERNAME' => 'root'], 'DB_USERNAME', 'must not be root'];
        yield 'unknown link format' => [['INVITATION_LINK_FORMAT' => 'path'], 'INVITATION_LINK_FORMAT', '"query" or "fragment"'];
        yield 'unknown metadata provider' => [['METADATA_PROVIDERS' => 'open_library,isbndb'], 'METADATA_PROVIDERS', 'unknown provider'];
        yield 'unknown APP_ENV' => [['APP_ENV' => 'prod'], 'APP_ENV', 'treated as production'];
        yield 'households enabled' => [['HOUSEHOLDS_ENABLED' => 'true'], 'HOUSEHOLDS_ENABLED', 'must not be enabled in production'];
        yield 'relative storage path' => [['BACKUP_PATH' => 'storage/backups'], 'BACKUP_PATH', 'absolute'];
    }

    /**
     * @param array<string, string|null> $changes
     */
    #[DataProvider('productionErrors')]
    public function testProductionErrors(array $changes, string $key, string $fragment): void
    {
        $report = $this->validate(self::with(self::PRODUCTION, $changes));

        self::assertContains($key, array_column($report['errors'], 0), json_encode($report['errors']));
        self::assertStringContainsString($fragment, implode(' | ', array_column(array_filter($report['errors'], static fn (array $e): bool => $e[0] === $key), 1)));
    }

    /**
     * @return iterable<string, array{array<string, string|null>, string}>
     */
    public static function productionWarnings(): iterable
    {
        yield 'query invitation links' => [['INVITATION_LINK_FORMAT' => 'query'], 'INVITATION_LINK_FORMAT'];
        yield 'default invitation links' => [['INVITATION_LINK_FORMAT' => null], 'INVITATION_LINK_FORMAT'];
        yield 'debug flag' => [['APP_DEBUG' => 'true'], 'APP_DEBUG'];
        yield 'short secret' => [['DB_PASSWORD' => 'short'], 'DB_PASSWORD'];
        yield 'shared secrets' => [['DB_MIGRATION_PASSWORD' => self::PRODUCTION['DB_PASSWORD']], 'DB_MIGRATION_PASSWORD'];
        yield 'shared account' => [['DB_MIGRATION_USERNAME' => 'library_app'], 'DB_MIGRATION_USERNAME'];
        yield 'no migration account' => [['DB_MIGRATION_USERNAME' => null, 'DB_MIGRATION_PASSWORD' => null], 'DB_MIGRATION_USERNAME'];
        yield 'root password visible' => [['DB_ROOT_PASSWORD' => 'Kx7mP2qL9vR4tN8wB3cY6hF1'], 'DB_ROOT_PASSWORD'];
        yield 'test settings present' => [['DB_TEST_DATABASE' => 'library_test'], 'DB_TEST_DATABASE'];
        yield 'unusable metadata contact' => [['METADATA_CONTACT' => 'not a contact'], 'METADATA_CONTACT'];
        yield 'not an integer' => [['SESSION_IDLE_TIMEOUT' => '30m'], 'SESSION_IDLE_TIMEOUT'];
        yield 'out of range' => [['INVITATION_MAX_DAYS' => '365'], 'INVITATION_MAX_DAYS'];
        yield 'weak Argon2' => [['ARGON2_MEMORY_KIB' => '8192'], 'ARGON2_MEMORY_KIB'];
        yield 'localhost URL' => [['APP_URL' => 'https://localhost'], 'APP_URL'];
        yield 'absolute shorter than idle' => [['SESSION_IDLE_TIMEOUT' => '3600', 'SESSION_ABSOLUTE_TIMEOUT' => '1800'], 'SESSION_ABSOLUTE_TIMEOUT'];
    }

    /**
     * @param array<string, string|null> $changes
     */
    #[DataProvider('productionWarnings')]
    public function testProductionWarningsDoNotBlock(array $changes, string $key): void
    {
        $report = $this->validate(self::with(self::PRODUCTION, $changes));

        self::assertSame([], $report['errors'], json_encode($report['errors']));
        self::assertContains($key, array_column($report['warnings'], 0));
    }

    public function testMissingAppEnvMeansProductionRules(): void
    {
        $config = new Config(self::with(self::PRODUCTION, ['APP_ENV' => null, 'SESSION_SECURE_COOKIE' => 'false']));
        $report = (new ConfigValidator($this->basePath()))->validate($config);

        self::assertTrue($config->isProduction());
        self::assertContains('SESSION_SECURE_COOKIE', array_column($report['errors'], 0));
        self::assertContains('APP_ENV', array_column($report['warnings'], 0));
    }

    public function testSecureCookieOverHttpBreaksSignInInAnyEnvironment(): void
    {
        $report = $this->validate(self::with(self::DEVELOPMENT, ['SESSION_SECURE_COOKIE' => 'true']));

        self::assertContains('SESSION_SECURE_COOKIE', array_column($report['errors'], 0));
    }

    public function testDevelopmentPlaceholdersAreWarningsOnly(): void
    {
        $report = $this->validate(self::with(self::DEVELOPMENT, ['DB_PASSWORD' => 'CHANGE_ME_app', 'APP_URL' => null]));

        self::assertSame([], $report['errors']);
        self::assertEqualsCanonicalizing(['DB_PASSWORD', 'APP_URL'], array_column($report['warnings'], 0));
    }

    public function testMalformedAppUrlIsAnErrorEvenInDevelopment(): void
    {
        // Phase 4B's local .env had "APP_URL=books.test": the Origin check then refuses every form.
        $report = $this->validate(self::with(self::DEVELOPMENT, ['APP_URL' => 'books.test']));

        self::assertContains('APP_URL', array_column($report['errors'], 0));
    }

    public function testStoragePathsInsideThePublicRootAreRefused(): void
    {
        $public = $this->basePath() . '/public';
        foreach (['COVER_STORAGE_PATH' => $public . '/covers', 'BACKUP_PATH' => $public, 'TEMP_PATH' => $public . '/../public/assets/x', 'MAINTENANCE_FLAG_PATH' => $public . '/maintenance.flag'] as $key => $path) {
            $report = $this->validate(self::with(self::PRODUCTION, [$key => $path]));
            self::assertContains($key, array_column($report['errors'], 0), $key);
        }
        self::assertSame([], $this->validate(self::with(self::PRODUCTION, ['BACKUP_PATH' => '/var/backups/library']))['errors']);
    }

    public function testUnreadableSecretFileIsReportedWithoutItsPath(): void
    {
        $report = $this->validate(self::with(self::PRODUCTION, ['DB_PASSWORD' => null, 'DB_PASSWORD_FILE' => '/nonexistent/secret/db_password']));

        self::assertContains('DB_PASSWORD', array_column($report['errors'], 0));
        self::assertStringNotContainsString('/nonexistent', json_encode($report));
        self::assertCount(1, array_filter($report['errors'], static fn (array $e): bool => str_contains($e[1], 'not readable')), 'Reported once');
    }

    public function testMessagesNeverContainSecretValues(): void
    {
        $secrets = ['DB_PASSWORD' => 'CHANGE_ME_s3cr3t-value', 'DB_MIGRATION_PASSWORD' => 'CHANGE_ME_m1gr4t0r', 'GOOGLE_BOOKS_API_KEY' => 'CHANGE_ME_AIzaKey', 'DB_ROOT_PASSWORD' => 'CHANGE_ME_r00t'];
        foreach ([self::PRODUCTION, self::DEVELOPMENT] as $base) {
            $output = json_encode($this->validate(self::with($base, $secrets)));
            foreach ($secrets as $value) {
                self::assertStringNotContainsString($value, (string) $output);
            }
        }
    }

    public function testWebRoleHoldsOnlyTheRuntimeAccount(): void
    {
        $web = self::with(self::PRODUCTION, ['APP_ROLE' => 'web', 'DB_MIGRATION_USERNAME' => null, 'DB_MIGRATION_PASSWORD' => null]);
        $report = $this->validate($web);
        self::assertSame([], $report['errors']);
        self::assertSame([], $report['warnings'], 'No "migrations would run with the application account" noise in the web container');

        // Compose overrides the shared env file with empty values: still clean.
        self::assertSame([], $this->validate(self::with($web, ['DB_MIGRATION_USERNAME' => '', 'DB_MIGRATION_PASSWORD_FILE' => '']))['errors']);

        foreach (['DB_MIGRATION_USERNAME' => 'library_migrator', 'DB_MIGRATION_PASSWORD' => 'Fj5sD8gK2hQ7nV4bX1cM9pW3'] as $key => $value) {
            self::assertContains($key, array_column($this->validate(self::with($web, [$key => $value]))['errors'], 0), $key);
        }
        $leak = $this->validate(self::with($web, ['DB_ROOT_PASSWORD' => 'Rt7yU2iO9pA4sD6fG1hJ3kL5']));
        self::assertContains('DB_ROOT_PASSWORD', array_column($leak['errors'], 0), 'Root credentials in an application container block production');
    }

    public function testOpsRoleRequiresTheMigrationAccount(): void
    {
        $ops = self::with(self::PRODUCTION, ['APP_ROLE' => 'ops']);
        self::assertSame([], $this->validate($ops)['errors']);
        self::assertSame([], $this->validate($ops)['warnings']);

        foreach (['DB_MIGRATION_USERNAME', 'DB_MIGRATION_PASSWORD'] as $key) {
            self::assertContains($key, array_column($this->validate(self::with($ops, [$key => null]))['errors'], 0), $key);
        }
        self::assertContains('DB_ROOT_PASSWORD', array_column($this->validate(self::with($ops, ['DB_ROOT_PASSWORD' => 'Rt7yU2iO9pA4sD6fG1hJ3kL5']))['errors'], 0));
    }

    public function testUnknownRoleIsAnErrorAndUnsetRoleKeepsCombinedBehaviour(): void
    {
        $report = $this->validate(self::with(self::PRODUCTION, ['APP_ROLE' => 'worker']));
        self::assertContains('APP_ROLE', array_column($report['errors'], 0));
        self::assertCount(1, array_filter($report['errors'], static fn (array $e): bool => $e[0] === 'APP_ROLE'), 'Reported once');

        $combined = $this->validate(self::with(self::PRODUCTION, ['DB_ROOT_PASSWORD' => 'Rt7yU2iO9pA4sD6fG1hJ3kL5']));
        self::assertSame([], $combined['errors']);
        self::assertContains('DB_ROOT_PASSWORD', array_column($combined['warnings'], 0), 'Unchanged without APP_ROLE');
    }

    /**
     * @param array<string, string|null> $values
     *
     * @return array{errors: list<array{string, string}>, warnings: list<array{string, string}>, notes: list<string>}
     */
    private function validate(array $values): array
    {
        return (new ConfigValidator($this->basePath()))->validate(new Config(array_filter($values, static fn (?string $v): bool => $v !== null)));
    }

    /**
     * @param array<string, string>      $base
     * @param array<string, string|null> $changes
     *
     * @return array<string, string|null>
     */
    private static function with(array $base, array $changes): array
    {
        return $changes + $base;
    }

    private function basePath(): string
    {
        return dirname(__DIR__, 3);
    }
}
