<?php

declare(strict_types=1);

namespace DigitalLibrary\Database;

use PDO;

/**
 * Applies versioned SQL migrations from a directory, in filename order.
 *
 * MySQL and MariaDB commit DDL implicitly, so a migration cannot be rolled
 * back as a whole. Each file is therefore kept small and focused, recorded
 * only after every statement succeeds, and checksummed to detect later edits.
 *
 * Engine-specific variants: production runs MariaDB 11.4 (the production
 * CPU cannot run MySQL 8.4 images); MySQL 8.4 stays supported for
 * development and regression tests. A released migration is never edited.
 * When one cannot run on MariaDB, an override with the SAME version name is
 * placed in <directory>/mariadb/. It is used instead of the released file
 * only when the server positively identifies itself as MariaDB (VERSION()
 * contains "-MariaDB"); any other server runs the released file. Exactly one
 * variant runs per version and one schema_migrations row is written, with the
 * checksum of the file actually executed. A database whose history holds the
 * other engine's checksum is refused before anything changes (never
 * rewritten): see MigrationEngineMismatchException.
 */
final class Migrator
{
    public const ENGINE_MYSQL = Engine::MYSQL;
    public const ENGINE_MARIADB = Engine::MARIADB;

    private const LOCK_NAME = 'digital_library_migrations';

    private ?string $engine = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    /**
     * The connected server's engine: MariaDB only when positively identified.
     */
    public function engine(): string
    {
        return $this->engine ??= Engine::of($this->pdo);
    }

    /**
     * @return list<string> Versions applied by this run.
     *
     * @throws MigrationEngineMismatchException before any change, when the
     *                                          history was written by the other engine
     */
    public function migrate(?callable $log = null): array
    {
        $this->ensureTable();
        $this->lock();
        try {
            $applied = $this->appliedVersions();
            $this->assertSameEngine($applied);
            $ran = [];
            foreach ($this->available() as $version => $file) {
                if (isset($applied[$version])) {
                    continue;
                }
                $path = $this->fileFor($version);
                $log && $log($path === $file ? "Migrating {$version}" : "Migrating {$version} (MariaDB variant)");
                $sql = (string) file_get_contents($path);
                foreach (SqlSplitter::split($sql) as $statement) {
                    $this->pdo->exec($statement);
                }
                $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (version, checksum, applied_at) VALUES (:v, :c, UTC_TIMESTAMP())');
                $stmt->execute(['v' => $version, 'c' => hash('sha256', $sql)]);
                $ran[] = $version;
            }

            return $ran;
        } finally {
            $this->unlock();
        }
    }

    /**
     * @return list<array{version: string, applied: bool, modified: bool, other_engine: bool, variant: string}>
     *         variant: the engine whose file applies to this server ('mysql' is the released file)
     */
    public function status(): array
    {
        $this->ensureTable();
        $applied = $this->appliedVersions();
        $rows = [];
        foreach (array_keys($this->available()) as $version) {
            $checksums = $this->checksums($version);
            $current = $checksums[$this->engine()];
            $otherEngine = isset($applied[$version]) && $this->isOtherEngine($version, $applied[$version]);
            $rows[] = [
                'version' => $version,
                'applied' => isset($applied[$version]),
                'modified' => isset($applied[$version]) && !$otherEngine && !hash_equals($applied[$version], $current),
                'other_engine' => $otherEngine,
                'variant' => $this->fileFor($version) === $this->released($version) ? self::ENGINE_MYSQL : self::ENGINE_MARIADB,
            ];
        }

        return $rows;
    }

    /**
     * Released migrations: version => absolute path.
     *
     * @return array<string, string>
     */
    public function available(): array
    {
        $files = glob(rtrim($this->directory, '/') . '/*.sql') ?: [];
        $out = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            self::assertVersionName($version, $file);
            $out[$version] = $file;
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * MariaDB overrides: version => absolute path. Each must replace a
     * released migration of the same name.
     *
     * @return array<string, string>
     */
    public function mariadbOverrides(): array
    {
        $released = $this->available();
        $out = [];
        foreach (glob(rtrim($this->directory, '/') . '/' . self::ENGINE_MARIADB . '/*.sql') ?: [] as $file) {
            $version = basename($file, '.sql');
            self::assertVersionName($version, $file);
            if (!isset($released[$version])) {
                throw new \RuntimeException(sprintf('MariaDB override "%s" has no released migration of the same name.', basename($file)));
            }
            $out[$version] = $file;
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * The file this server runs for $version.
     */
    public function fileFor(string $version): string
    {
        if ($this->engine() === self::ENGINE_MARIADB) {
            $overrides = $this->mariadbOverrides();
            if (isset($overrides[$version])) {
                return $overrides[$version];
            }
        }

        return $this->released($version);
    }

    /**
     * @return array{mysql: string, mariadb: string}
     */
    public function checksums(string $version): array
    {
        $released = hash('sha256', (string) file_get_contents($this->released($version)));
        $override = $this->mariadbOverrides()[$version] ?? null;

        return [
            self::ENGINE_MYSQL => $released,
            self::ENGINE_MARIADB => $override === null ? $released : hash('sha256', (string) file_get_contents($override)),
        ];
    }

    private function released(string $version): string
    {
        return $this->available()[$version] ?? throw new \RuntimeException(sprintf('Unknown migration "%s".', $version));
    }

    /**
     * The recorded checksum is the other engine's variant (and not this one's).
     */
    private function isOtherEngine(string $version, string $recorded): bool
    {
        if (!isset($this->available()[$version])) {
            return false;
        }
        $checksums = $this->checksums($version);
        $other = $this->engine() === self::ENGINE_MARIADB ? self::ENGINE_MYSQL : self::ENGINE_MARIADB;

        return !hash_equals($checksums[$this->engine()], $recorded) && hash_equals($checksums[$other], $recorded);
    }

    /**
     * @param array<string, string> $applied
     */
    private function assertSameEngine(array $applied): void
    {
        $mismatched = [];
        foreach ($applied as $version => $checksum) {
            if ($this->isOtherEngine($version, $checksum)) {
                $mismatched[] = $version;
            }
        }
        if ($mismatched !== []) {
            throw new MigrationEngineMismatchException($this->engine(), $mismatched);
        }
    }

    private static function assertVersionName(string $version, string $file): void
    {
        if (!preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/', $version)) {
            throw new \RuntimeException(sprintf('Invalid migration filename "%s".', basename($file)));
        }
    }

    /**
     * @return array<string, string> version => checksum
     */
    private function appliedVersions(): array
    {
        $rows = $this->pdo->query('SELECT version, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);

        return array_map('strval', $rows);
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(191) NOT NULL PRIMARY KEY,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
        );
    }

    private function lock(): void
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:name, 30)');
        $stmt->execute(['name' => self::LOCK_NAME]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new \RuntimeException('Another migration run is in progress.');
        }
    }

    private function unlock(): void
    {
        $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $stmt->execute(['name' => self::LOCK_NAME]);
    }
}
