<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Core\Application;
use DigitalLibrary\Core\Config;
use DigitalLibrary\Core\DotEnv;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Database\ConnectionFactory;
use DigitalLibrary\Database\Migrator;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\PasswordHasher;
use DigitalLibrary\Security\Role;
use DigitalLibrary\Security\Session;
use DigitalLibrary\Metadata\Backoff\ProviderBackoff;
use DigitalLibrary\Metadata\Backoff\RequestPacer;
use DigitalLibrary\Metadata\Http\HttpClient;
use DigitalLibrary\Tests\Support\FakeHttpClient;
use DigitalLibrary\Tests\Support\InMemoryBackoffStore;
use DigitalLibrary\Tests\Support\RecordingPacer;
use DigitalLibrary\Tests\Support\TestDatabaseGuard;
use DigitalLibrary\Tests\Support\TestDatabaseSafetyException;
use DigitalLibrary\Upload\CoverImageProcessor;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs against a real MySQL database named by DB_TEST_DATABASE, using the
 * same least-privilege accounts as the application. Skipped when no test
 * database is configured or reachable.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected const PASSWORD = 'correct horse battery staple';

    private static bool $migrated = false;
    private static ?PDO $appPdo = null;
    private static ?PDO $migrationPdo = null;
    private static ?string $skipReason = null;

    /**
     * Configuration for the test database. TestDatabaseGuard refuses unsafe
     * settings (production environment file, a test database that is not
     * "*_test" or equals DB_DATABASE, incomplete dedicated test accounts).
     */
    protected static function config(): Config
    {
        $config = TestDatabaseGuard::testConfig(self::baseConfig(), ...self::declaredEnvironment());

        foreach (self::$configOverrides as $key => $value) {
            if (str_starts_with($key, 'DB_')) {
                // Database settings come only from the guarded configuration.
                throw new TestDatabaseSafetyException('Tests may not override ' . $key . '.');
            }
            $config = $config->with($key, $value);
        }

        return $config
            ->with('APP_ENV', 'testing')
            ->with('APP_URL', 'http://library.test')
            ->with('ARGON2_MEMORY_KIB', '8192')
            ->with('ARGON2_TIME_COST', '1');
    }

    /**
     * Per-test configuration overrides (reset in setUp).
     *
     * @var array<string, string>
     */
    protected static array $configOverrides = [];

    /** Offline HTTP client used for every request (reset in setUp). */
    protected static FakeHttpClient $http;

    /** Metadata provider backoff state shared by a test's requests (reset in setUp). */
    protected static InMemoryBackoffStore $backoff;

    /** Seconds added to the backoff clock, to simulate time passing. */
    protected static int $clockOffset = 0;

    /** Open Library request pacing (never waits in tests; reset in setUp). */
    protected static RecordingPacer $pacer;

    protected function setUp(): void
    {
        self::$configOverrides = [];
        self::$http = new FakeHttpClient();
        self::$backoff = new InMemoryBackoffStore();
        self::$clockOffset = 0;
        self::$pacer = new RecordingPacer();
        if (self::$skipReason !== null) {
            self::markTestSkipped(self::$skipReason);
        }
        if (self::baseConfig()->get('DB_TEST_DATABASE') === null) {
            // Not configured at all (e.g. a unit-only checkout): skip, as before.
            self::$skipReason = 'Integration database unavailable: DB_TEST_DATABASE is not configured.';
            self::markTestSkipped(self::$skipReason);
        }
        // Unsafe configuration is an error, never a skip; checked before connecting.
        $config = self::config();
        try {
            $appPdo = self::$appPdo ?? ConnectionFactory::fromConfig($config);
            $migrationPdo = self::$migrationPdo ?? ConnectionFactory::forMigrations($config);
        } catch (\Throwable $e) {
            self::$skipReason = 'Integration database unavailable: ' . $e->getMessage();
            self::markTestSkipped(self::$skipReason);
        }
        if (self::$appPdo === null) {
            // Both connections must report the test database before anything is reset.
            $base = self::baseConfig();
            $dedicated = TestDatabaseGuard::usesDedicatedAccounts($base);
            foreach ([$appPdo, $migrationPdo] as $pdo) {
                TestDatabaseGuard::verifyConnection($pdo, (string) $config->get('DB_DATABASE'), $base->get('DB_DATABASE'), $dedicated);
            }
            self::$appPdo = $appPdo;
            self::$migrationPdo = $migrationPdo;
        }
        if (!self::$migrated) {
            $this->resetSchema();
            (new Migrator(self::$migrationPdo, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
            self::$migrated = true;
        }
        $this->truncateData();
        self::$trustUploads = false;
    }

    /**
     * The environment as the application would see it (process environment
     * over the .env file), before any test-database substitution.
     */
    protected static function baseConfig(): Config
    {
        return Config::fromEnvironment(self::envFile());
    }

    /**
     * APP_ENV as declared in the .env file (PHPUnit forces the process
     * environment to "testing", so it cannot be read from there).
     *
     * @return array{?string, bool}
     */
    private static function declaredEnvironment(): array
    {
        $file = self::envFile();
        if (!is_file($file)) {
            return [null, false];
        }
        $values = DotEnv::parse((string) file_get_contents($file));

        return [isset($values['APP_ENV']) && $values['APP_ENV'] !== '' ? $values['APP_ENV'] : null, true];
    }

    private static function envFile(): string
    {
        return dirname(__DIR__, 2) . '/.env';
    }

    protected function pdo(): PDO
    {
        return self::$appPdo ?? throw new \LogicException('Not connected');
    }

    protected function migrationPdo(): PDO
    {
        return self::$migrationPdo ?? throw new \LogicException('Not connected');
    }

    protected function createUser(string $email, Role $role, string $password = self::PASSWORD, bool $active = true): int
    {
        $hash = (new PasswordHasher(8192, 1))->hash($password);
        $stmt = $this->pdo()->prepare(
            'INSERT INTO users (email, display_name, password_hash, role_id, locale, is_active, created_at, updated_at)
             SELECT :email, :name, :hash, id, :locale, :active, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM roles WHERE code = :role'
        );
        $stmt->execute(['email' => $email, 'name' => ucfirst(strtok($email, '@')), 'hash' => $hash, 'locale' => 'en', 'active' => $active ? 1 : 0, 'role' => $role->value]);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Sends a request through the full middleware stack. Each call uses a
     * fresh service container (as PHP-FPM would) but the given session
     * persists between calls, standing in for the browser's cookie.
     * A query string in $path is parsed into the request's query parameters.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $server
     * @param array<string, mixed>  $files
     */
    protected function request(Session $browser, string $method, string $path, array $body = [], array $server = [], array $files = []): Response
    {
        $services = new Services(self::config(), dirname(__DIR__, 2));
        $services->set(PDO::class, $this->pdo());
        $services->set(Session::class, $browser);
        $services->set(HttpClient::class, self::$http);
        $services->set(ProviderBackoff::class, new ProviderBackoff(self::$backoff, static fn (): int => time() + self::$clockOffset));
        $services->set(RequestPacer::class, self::$pacer);
        $services->set(CoverImageProcessor::class, new CoverImageProcessor(
            self::coverDirectory(),
            isUploadedFile: static fn (string $path): bool => self::$trustUploads,
        ));

        $query = [];
        $parts = explode('?', $path, 2);
        if (isset($parts[1])) {
            parse_str($parts[1], $query);
        }

        return (new Application($services))->handle(new Request($method, $parts[0], $query, $body, $server + ['REMOTE_ADDR' => '192.0.2.10', 'HTTP_HOST' => 'library.test'], [], $files));
    }

    /**
     * Whether the test cover processor treats files as genuine HTTP uploads.
     * Tests set this explicitly; the default exercises the real defence.
     */
    protected static bool $trustUploads = false;

    protected static function coverDirectory(): string
    {
        static $dir = null;
        if ($dir === null) {
            $dir = sys_get_temp_dir() . '/dla-test-covers-' . getmypid();
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
        }

        return $dir;
    }

    /**
     * Logs in and returns a browser session plus a fresh CSRF token.
     *
     * @return array{Session, string}
     */
    protected function signedIn(string $email): array
    {
        $browser = $this->browser();
        $response = $this->login($browser, $email);
        self::assertSame(303, $response->status(), 'Login failed for ' . $email);

        return [$browser, self::csrfFrom($this->request($browser, 'GET', '/account/password'))];
    }

    /**
     * Creates a book directly through the service layer.
     *
     * @param array<string, mixed> $edition overrides for the edition fields
     * @param list<string>         $authors
     * @param list<int>            $categories
     *
     * @return array{work_id: int, edition_id: int, copy_id: ?int}
     */
    protected function makeBook(int $userId, string $title, ?string $isbn = null, array $authors = [], array $edition = [], array $categories = [], ?int $copyOwner = null, ?int $locationId = null, ?int $householdId = null): array
    {
        $services = new Services(self::config(), dirname(__DIR__, 2));
        $services->set(PDO::class, $this->pdo());
        $work = new \DigitalLibrary\Catalogue\Input\WorkInput($title, '', '', '', '', '', array_map(static fn (string $n): array => ['name' => $n, 'role' => 'author'], $authors), $categories);
        $editionInput = new \DigitalLibrary\Catalogue\Input\EditionInput(
            $edition['title'] ?? $title, '', $edition['publisher'] ?? '', (string) ($edition['year'] ?? ''), '', $edition['language'] ?? '',
            '', '', $isbn ?? '', '', '', [],
        );
        $copy = $copyOwner === null ? null : new \DigitalLibrary\Catalogue\Input\CopyInput((string) $copyOwner, $locationId === null ? '' : (string) $locationId, '', '', 'available', false, '', '', '', '', '');

        // A copy belongs to a household: the default one unless the test names another.
        $household = $copy === null ? null : ($householdId ?? $services->households()->defaultId());

        return $services->books()->createBook($work, $editionInput, $copy, $userId, null, $household);
    }

    /**
     * The temporary default household (re-created empty before every test,
     * as the backfill migration leaves it on a new installation).
     */
    protected function defaultHouseholdId(): int
    {
        return (int) $this->pdo()->query("SELECT id FROM households WHERE system_key = 'default'")->fetchColumn();
    }

    /**
     * @param list<int> $memberIds
     */
    protected function createHousehold(string $name, array $memberIds = []): int
    {
        $this->pdo()->prepare('INSERT INTO households (name, created_at, updated_at) VALUES (:n, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['n' => $name]);
        $id = (int) $this->pdo()->lastInsertId();
        foreach ($memberIds as $userId) {
            $this->addHouseholdMember($id, $userId);
        }

        return $id;
    }

    protected function addHouseholdMember(int $householdId, int $userId): void
    {
        $this->pdo()->prepare('INSERT INTO household_members (household_id, user_id, created_at) VALUES (:h, :u, UTC_TIMESTAMP())')->execute(['h' => $householdId, 'u' => $userId]);
    }

    /**
     * A copy as stored before households existed (no household_id column
     * is named), for upgrade tests that run against an older schema.
     */
    protected function insertLegacyCopy(int $editionId, int $ownerId, ?int $locationId = null): int
    {
        $this->pdo()->prepare(
            'INSERT INTO copies (edition_id, owner_user_id, location_id, status, created_by, updated_by, created_at, updated_at)
             VALUES (:e, :o, :l, \'available\', :c, :u, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute(['e' => $editionId, 'o' => $ownerId, 'l' => $locationId, 'c' => $ownerId, 'u' => $ownerId]);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Applies the released migrations up to and including $last (and their
     * MariaDB overrides), from a temporary copy of the migration directory.
     *
     * @return list<string> versions applied by this call
     */
    protected function migrateUpTo(PDO $pdo, string $last): array
    {
        $source = dirname(__DIR__, 2) . '/database/migrations';
        $dir = sys_get_temp_dir() . '/dla-migrations-' . bin2hex(random_bytes(4));
        mkdir($dir);
        mkdir($dir . '/mariadb');
        try {
            foreach (['', '/mariadb'] as $variant) {
                foreach (glob($source . $variant . '/*.sql') ?: [] as $file) {
                    if (basename($file, '.sql') <= $last) {
                        copy($file, $dir . $variant . '/' . basename($file));
                    }
                }
            }

            return (new Migrator($pdo, $dir))->migrate();
        } finally {
            array_map('unlink', array_merge(glob($dir . '/*.sql') ?: [], glob($dir . '/mariadb/*.sql') ?: []));
            rmdir($dir . '/mariadb');
            rmdir($dir);
        }
    }

    /**
     * Current optimistic-locking version of a work, edition or copy.
     */
    protected function rowVersion(string $table, int $id): string
    {
        if (!in_array($table, ['works', 'editions', 'copies'], true)) {
            throw new \InvalidArgumentException($table);
        }

        return (string) $this->pdo()->query("SELECT row_version FROM {$table} WHERE id = {$id}")->fetchColumn();
    }

    protected function countRows(string $sql): int
    {
        return (int) $this->pdo()->query($sql)->fetchColumn();
    }

    protected function browser(): Session
    {
        return Session::inMemory();
    }

    protected static function csrfFrom(Response $response): string
    {
        if (!preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $response->body(), $m)) {
            self::fail('No CSRF token in response');
        }

        return $m[1];
    }

    protected function login(Session $browser, string $email, string $password = self::PASSWORD): Response
    {
        $token = self::csrfFrom($this->request($browser, 'GET', '/login'));

        return $this->request($browser, 'POST', '/login', ['_csrf' => $token, 'email' => $email, 'password' => $password]);
    }

    private function resetSchema(): void
    {
        $pdo = $this->migrationPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '', (string) $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function truncateData(): void
    {
        $pdo = $this->migrationPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            if (!in_array($table, ['schema_migrations', 'roles', 'settings'], true)) {
                $pdo->exec('TRUNCATE TABLE `' . $table . '`');
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        if (in_array('households', $tables, true)) {
            // As on a new installation after the migrations: the default household exists, with no members.
            $pdo->exec("INSERT INTO households (name, system_key, created_at, updated_at) VALUES ('Default household', 'default', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        }
    }
}
