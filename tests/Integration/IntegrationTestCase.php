<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Core\Application;
use DigitalLibrary\Core\Config;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Database\ConnectionFactory;
use DigitalLibrary\Database\Migrator;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\PasswordHasher;
use DigitalLibrary\Security\Role;
use DigitalLibrary\Security\Session;
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

    protected static function config(): Config
    {
        $config = Config::fromEnvironment(dirname(__DIR__, 2) . '/.env');
        $test = $config->get('DB_TEST_DATABASE');
        if ($test === null) {
            throw new \RuntimeException('DB_TEST_DATABASE is not configured.');
        }

        return $config
            ->with('DB_DATABASE', $test)
            ->with('APP_ENV', 'testing')
            ->with('APP_URL', 'http://library.test')
            ->with('ARGON2_MEMORY_KIB', '8192')
            ->with('ARGON2_TIME_COST', '1');
    }

    protected function setUp(): void
    {
        if (self::$skipReason !== null) {
            self::markTestSkipped(self::$skipReason);
        }
        try {
            $config = self::config();
            self::$appPdo ??= ConnectionFactory::fromConfig($config);
            self::$migrationPdo ??= ConnectionFactory::forMigrations($config);
        } catch (\Throwable $e) {
            self::$skipReason = 'Integration database unavailable: ' . $e->getMessage();
            self::markTestSkipped(self::$skipReason);
        }
        if (!self::$migrated) {
            $this->resetSchema();
            (new Migrator(self::$migrationPdo, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
            self::$migrated = true;
        }
        $this->truncateData();
        self::$trustUploads = false;
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
        $services->set(CoverImageProcessor::class, new CoverImageProcessor(
            self::coverDirectory(),
            isUploadedFile: static fn (string $path): bool => self::$trustUploads,
        ));

        $query = [];
        $parts = explode('?', $path, 2);
        if (isset($parts[1])) {
            parse_str($parts[1], $query);
        }

        return (new Application($services))->handle(new Request($method, $parts[0], $query, $body, $server + ['REMOTE_ADDR' => '192.0.2.10'], [], $files));
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
    protected function makeBook(int $userId, string $title, ?string $isbn = null, array $authors = [], array $edition = [], array $categories = [], ?int $copyOwner = null, ?int $locationId = null): array
    {
        $services = new Services(self::config(), dirname(__DIR__, 2));
        $services->set(PDO::class, $this->pdo());
        $work = new \DigitalLibrary\Catalogue\Input\WorkInput($title, '', '', '', '', '', array_map(static fn (string $n): array => ['name' => $n, 'role' => 'author'], $authors), $categories);
        $editionInput = new \DigitalLibrary\Catalogue\Input\EditionInput(
            $edition['title'] ?? $title, '', $edition['publisher'] ?? '', (string) ($edition['year'] ?? ''), '', $edition['language'] ?? '',
            '', '', $isbn ?? '', '', '', [],
        );
        $copy = $copyOwner === null ? null : new \DigitalLibrary\Catalogue\Input\CopyInput((string) $copyOwner, $locationId === null ? '' : (string) $locationId, '', '', 'available', false, '', '', '', '', '');

        return $services->books()->createBook($work, $editionInput, $copy, $userId);
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
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (!in_array($table, ['schema_migrations', 'roles', 'settings'], true)) {
                $pdo->exec('TRUNCATE TABLE `' . $table . '`');
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
