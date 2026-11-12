<?php

declare(strict_types=1);

namespace DigitalLibrary\Database;

use PDO;

/**
 * Applies versioned SQL migrations from a directory, in filename order.
 *
 * MySQL commits DDL implicitly, so a migration cannot be rolled back as a
 * whole. Each file is therefore kept small and focused, recorded only after
 * every statement succeeds, and checksummed to detect later edits.
 */
final class Migrator
{
    private const LOCK_NAME = 'digital_library_migrations';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    /**
     * @return list<string> Versions applied by this run.
     */
    public function migrate(?callable $log = null): array
    {
        $this->ensureTable();
        $this->lock();
        try {
            $applied = $this->appliedVersions();
            $ran = [];
            foreach ($this->available() as $version => $file) {
                if (isset($applied[$version])) {
                    continue;
                }
                $log && $log("Migrating {$version}");
                $sql = (string) file_get_contents($file);
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
     * @return list<array{version: string, applied: bool, modified: bool}>
     */
    public function status(): array
    {
        $this->ensureTable();
        $applied = $this->appliedVersions();
        $rows = [];
        foreach ($this->available() as $version => $file) {
            $checksum = hash('sha256', (string) file_get_contents($file));
            $rows[] = [
                'version' => $version,
                'applied' => isset($applied[$version]),
                'modified' => isset($applied[$version]) && !hash_equals($applied[$version], $checksum),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, string> version => absolute path
     */
    public function available(): array
    {
        $files = glob(rtrim($this->directory, '/') . '/*.sql') ?: [];
        $out = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (!preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/', $version)) {
                throw new \RuntimeException(sprintf('Invalid migration filename "%s".', basename($file)));
            }
            $out[$version] = $file;
        }
        ksort($out, SORT_STRING);

        return $out;
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
