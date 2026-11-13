<?php

declare(strict_types=1);

namespace DigitalLibrary\Backup;

use DigitalLibrary\Database\Engine;
use PDO;

/**
 * Restores a verified backup into a database and a covers directory.
 * CLI only (bin/console backup:restore), which adds the confirmation,
 * maintenance-mode and production guards.
 *
 * Order of work, so that nothing is changed until everything checks out:
 *  1. full verification (BackupVerifier);
 *  2. extraction into a private temporary directory, re-hashing every
 *     member against the manifest (the archive cannot be swapped between
 *     verification and use), validating the whole dump's framing and that
 *     every statement has a shape the dumper writes (DumpReader::isAllowed);
 *  3. pre-flight: free space for the extraction (temporary directory) and
 *     the cover files (covers directory); the target database must be
 *     empty unless $replace, and no existing cover file may differ from
 *     the backup unless $replace; then the caller's $beforeChanges hook
 *     (Operations: safety backup of the current state, restore marker);
 *  4. database: optional DROP of existing tables, then the dump;
 *  5. covers: each file written to a temporary name and renamed into
 *     place; unrelated files are never touched or deleted;
 *  6. post-check: per-table row counts, foreign-key integrity and
 *     referenced covers.
 *
 * NOT atomic: MariaDB/MySQL DDL (DROP/CREATE TABLE) commits implicitly and cannot
 * be rolled back, so a failure in step 4 or 5 leaves the target PARTIALLY
 * restored. Such failures are reported as PARTIAL/INCOMPLETE (exit 2 from
 * the console), never as success; recovery is to restore again, or to
 * restore the safety backup taken before step 4.
 */
final class BackupRestorer
{
    /** Head-room kept free on each filesystem (bytes). */
    public const SPACE_MARGIN = 16 * 1024 * 1024;

    /** @var \Closure(string): (float|false) */
    private readonly \Closure $freeSpace;

    /**
     * @param (\Closure(string): (float|false))|null $freeSpace free bytes of a directory's filesystem (tests)
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $coversDirectory,
        private readonly string $tempDirectory,
        ?\Closure $freeSpace = null,
    ) {
        $this->freeSpace = $freeSpace ?? static fn (string $dir): float|false => @disk_free_space($dir);
    }

    /**
     * @param (\Closure(string): void)|null                                    $log
     * @param (\Closure(array{tables: list<string>, covers: int}): void)|null $beforeChanges called once all
     *                                                                         checks passed, before the first change; may throw to abort
     *
     * @return array{tables: array<string, int>, covers_written: int, covers_unchanged: int}
     */
    public function restore(string $archive, bool $replace, ?\Closure $log = null, ?\Closure $beforeChanges = null): array
    {
        $log ??= static function (string $line): void {
        };
        $log('Verifying the backup…');
        $verification = (new BackupVerifier())->verify($archive);
        if (!$verification['ok']) {
            throw new BackupException("The backup failed verification; nothing was changed:\n - " . implode("\n - ", $verification['errors']));
        }
        $manifest = $verification['manifest'];
        // Table definitions are engine-specific (SHOW CREATE TABLE output,
        // engine-specific migration variants): a MySQL backup cannot be
        // restored into MariaDB or the reverse. Refuse before any change.
        $sourceVersion = (string) ($manifest['database']['server_version'] ?? '');
        if ($sourceVersion === '') {
            throw new BackupException('The backup does not record its database server version, so its engine cannot be checked. Nothing was changed.');
        }
        $source = Engine::fromVersion($sourceVersion);
        $target = Engine::of($this->pdo);
        if ($source !== $target) {
            throw new BackupException(sprintf(
                'The backup was taken from %s (%s) and this server is %s; table definitions differ between engines, so it cannot be restored here. Nothing was changed.',
                $source === Engine::MARIADB ? 'MariaDB' : 'MySQL',
                preg_replace('/[^0-9A-Za-z.+\-]/', '', $sourceVersion),
                $target === Engine::MARIADB ? 'MariaDB' : 'MySQL',
            ));
        }
        $expected = [];
        foreach ($manifest['files'] as $file) {
            $expected[$file['path']] = $file;
        }

        $work = $this->workDirectory();
        try {
            $this->requireSpace($work, array_sum(array_column($manifest['files'], 'size')), 'the temporary directory (extraction)');
            $log('Extracting into a temporary directory…');
            $this->extract($archive, $work, $expected);
            $dump = $work . '/' . Manifest::DATABASE;
            $statementCount = 0;
            foreach (DumpReader::statements($dump) as $sql) {
                $statementCount++;
                if (!DumpReader::isAllowed($sql)) {
                    throw new BackupException(sprintf('The database dump contains a statement this application never writes (statement %d); nothing was changed.', $statementCount));
                }
            }

            // Pre-flight checks: still nothing changed.
            $existing = $this->tables();
            if ($existing !== [] && !$replace) {
                throw new BackupException(sprintf('The target database is not empty (%d tables). Restore into an empty database, or pass --replace to drop its tables first.', count($existing)));
            }
            $covers = [];
            foreach (array_keys($expected) as $path) {
                if (str_starts_with($path, 'covers/')) {
                    $covers[] = substr($path, strlen('covers/'));
                }
            }
            $conflicts = [];
            foreach ($covers as $relative) {
                $target = $this->coversDirectory . '/' . $relative;
                if (is_link($target) || (file_exists($target) && !is_file($target))) {
                    throw new BackupException('Refusing to overwrite a non-regular file in the covers directory: ' . $relative);
                }
                if (is_file($target) && !hash_equals($expected['covers/' . $relative]['sha256'], (string) hash_file('sha256', $target))) {
                    $conflicts[] = $relative;
                }
            }
            if ($conflicts !== [] && !$replace) {
                throw new BackupException(sprintf('%d cover file(s) in the target directory differ from the backup (e.g. %s). Pass --replace to overwrite them.', count($conflicts), $conflicts[0]));
            }
            $toWrite = 0;
            foreach ($covers as $relative) {
                if (!is_file($this->coversDirectory . '/' . $relative) || in_array($relative, $conflicts, true)) {
                    $toWrite += $expected['covers/' . $relative]['size'];
                }
            }
            $this->requireSpace($this->coversDirectory, $toWrite, 'the covers directory');
            if ($beforeChanges !== null) {
                $beforeChanges(['tables' => $existing, 'covers' => count($covers)]);
            }

            // Database.
            $log(sprintf('Restoring the database (%d statements)…', $statementCount));
            $done = 0;
            try {
                $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                foreach ($existing as $table) {
                    $this->pdo->exec('DROP TABLE ' . DatabaseDumper::ident($table));
                }
                foreach (DumpReader::statements($dump) as $sql) {
                    // One prepared statement each: multi-statement strings cannot run.
                    $this->pdo->prepare($sql)->execute();
                    $done++;
                }
                $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            } catch (\Throwable $e) {
                throw new BackupException(sprintf(
                    'DATABASE RESTORE FAILED at statement %d of %d (%s). The target database is now PARTIALLY restored and must not be used; fix the cause and restore again with --replace.',
                    $done + 1,
                    $statementCount,
                    $e instanceof \PDOException ? ($e->errorInfo[2] ?? $e->getMessage()) : $e->getMessage(),
                ), 0, $e);
            }
            $counts = [];
            foreach ($manifest['database']['tables'] as $table => $rows) {
                $actual = (int) $this->pdo->query('SELECT COUNT(*) FROM ' . DatabaseDumper::ident((string) $table))->fetchColumn();
                $counts[(string) $table] = $actual;
                if ($actual !== (int) $rows) {
                    throw new BackupException(sprintf('DATABASE RESTORE INCOMPLETE: table %s has %d rows, the backup has %d.', $table, $actual, $rows));
                }
            }
            $violations = self::foreignKeyViolations($this->pdo);
            if ($violations !== []) {
                throw new BackupException('DATABASE RESTORE INCOMPLETE: foreign-key violations after loading (' . implode(', ', array_slice($violations, 0, 5)) . '). The data in the backup is inconsistent; the target database must not be used.');
            }

            // Covers.
            $log(sprintf('Restoring %d cover files…', count($covers)));
            $written = 0;
            $unchanged = 0;
            try {
                foreach ($covers as $relative) {
                    $target = $this->coversDirectory . '/' . $relative;
                    if (is_file($target) && !in_array($relative, $conflicts, true)) {
                        $unchanged++;
                        continue;
                    }
                    $this->place($work . '/covers/' . $relative, $target);
                    $written++;
                }
            } catch (\Throwable $e) {
                throw new BackupException('COVER RESTORE FAILED after ' . $written . ' files (' . $e->getMessage() . '). The database was restored; covers are PARTIALLY restored. Fix the cause and run the restore again.', 0, $e);
            }
            foreach ($this->pdo->query('SELECT storage_key FROM covers')->fetchAll(PDO::FETCH_COLUMN) as $key) {
                if (!is_file($this->coversDirectory . '/' . $key) && !in_array((string) $key, $manifest['covers']['missing'], true)) {
                    throw new BackupException('COVER RESTORE INCOMPLETE: missing ' . $key);
                }
            }

            return ['tables' => $counts, 'covers_written' => $written, 'covers_unchanged' => $unchanged];
        } finally {
            $this->removeTree($work);
        }
    }

    /**
     * Rows whose foreign key points at no parent row. The dump is loaded with
     * FOREIGN_KEY_CHECKS=0 (tables arrive in name order), so integrity is
     * checked afterwards for every constraint of the current database.
     * Composite keys follow MySQL's MATCH SIMPLE: a row with any NULL key
     * column is not checked.
     *
     * @return list<string> "table.constraint (N rows)"
     */
    public static function foreignKeyViolations(PDO $pdo): array
    {
        $constraints = [];
        $rows = $pdo->query(
            'SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION'
        )->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as [$table, $name, $column, $parent, $parentColumn]) {
            $key = $table . '.' . $name;
            $constraints[$key]['table'] = (string) $table;
            $constraints[$key]['parent'] = (string) $parent;
            $constraints[$key]['pairs'][] = [(string) $column, (string) $parentColumn];
        }
        $violations = [];
        foreach ($constraints as $key => $c) {
            $notNull = implode(' AND ', array_map(static fn (array $p): string => 'c.' . DatabaseDumper::ident($p[0]) . ' IS NOT NULL', $c['pairs']));
            $match = implode(' AND ', array_map(static fn (array $p): string => 'p.' . DatabaseDumper::ident($p[1]) . ' = c.' . DatabaseDumper::ident($p[0]), $c['pairs']));
            $count = (int) $pdo->query(sprintf(
                'SELECT COUNT(*) FROM %s c WHERE %s AND NOT EXISTS (SELECT 1 FROM %s p WHERE %s)',
                DatabaseDumper::ident($c['table']),
                $notNull,
                DatabaseDumper::ident($c['parent']),
                $match,
            ))->fetchColumn();
            if ($count > 0) {
                $violations[] = sprintf('%s (%d rows)', $key, $count);
            }
        }

        return $violations;
    }

    private function requireSpace(string $directory, int $bytes, string $what): void
    {
        $free = ($this->freeSpace)($directory);
        if ($free !== false && $free < $bytes + self::SPACE_MARGIN) {
            throw new BackupException(sprintf(
                'Not enough free space in %s: about %d MiB needed, %d MiB available. Nothing was changed.',
                $what,
                (int) ceil(($bytes + self::SPACE_MARGIN) / 1048576),
                (int) floor($free / 1048576),
            ));
        }
    }

    /**
     * @param array<string, array{path: string, size: int, sha256: string}> $expected
     */
    private function extract(string $archive, string $work, array $expected): void
    {
        (new TarReader($archive))->each(function (string $name, int $size, \Closure $read) use ($work, $expected): void {
            if ($name === Manifest::NAME) {
                return;
            }
            $entry = $expected[$name] ?? throw new BackupException('Unexpected archive member: ' . $name);
            $target = $work . '/' . $name;
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                throw new BackupException('Cannot create a temporary directory');
            }
            $out = fopen($target, 'xb');
            if ($out === false) {
                throw new BackupException('Cannot write a temporary file');
            }
            $hash = hash_init('sha256');
            $length = 0;
            while (($chunk = $read()) !== null) {
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    fclose($out);
                    throw new BackupException('Write error while extracting (disk full?)');
                }
                hash_update($hash, $chunk);
                $length += strlen($chunk);
            }
            fclose($out);
            if ($length !== $entry['size'] || !hash_equals($entry['sha256'], hash_final($hash))) {
                throw new BackupException('Archive changed after verification: ' . $name);
            }
        });
    }

    private function place(string $source, string $target): void
    {
        $dir = dirname($target);
        if (is_link($dir)) {
            throw new BackupException('Refusing to write through a symbolic link: ' . basename($dir));
        }
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new BackupException('Cannot create ' . basename($dir) . ' in the covers directory');
        }
        $tmp = $dir . '/.restore-' . bin2hex(random_bytes(6));
        if (!copy($source, $tmp)) {
            throw new BackupException('Cannot write ' . basename($target));
        }
        @chmod($tmp, 0640);
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new BackupException('Cannot move ' . basename($target) . ' into place');
        }
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return array_map('strval', $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN));
    }

    private function workDirectory(): string
    {
        if (!is_dir($this->tempDirectory) && !@mkdir($this->tempDirectory, 0700, true) && !is_dir($this->tempDirectory)) {
            throw new BackupException('Cannot create the temporary directory');
        }
        $work = rtrim($this->tempDirectory, '/') . '/restore-' . bin2hex(random_bytes(8));
        if (!mkdir($work, 0700)) {
            throw new BackupException('Cannot create a private working directory');
        }

        return $work;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
