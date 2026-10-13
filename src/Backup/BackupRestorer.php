<?php

declare(strict_types=1);

namespace DigitalLibrary\Backup;

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
 *  3. pre-flight: the target database must be empty unless $replace, and
 *     no existing cover file may differ from the backup unless $replace;
 *  4. database: optional DROP of existing tables, then the dump;
 *  5. covers: each file written to a temporary name and renamed into
 *     place; unrelated files are never touched or deleted;
 *  6. post-check: per-table row counts and referenced covers.
 *
 * A failure in step 4 or 5 is reported as PARTIAL; the restorer never
 * claims success after a partial failure.
 */
final class BackupRestorer
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $coversDirectory,
        private readonly string $tempDirectory,
    ) {
    }

    /**
     * @param (\Closure(string): void)|null $log
     *
     * @return array{tables: array<string, int>, covers_written: int, covers_unchanged: int}
     */
    public function restore(string $archive, bool $replace, ?\Closure $log = null): array
    {
        $log ??= static function (string $line): void {
        };
        $log('Verifying the backup…');
        $verification = (new BackupVerifier())->verify($archive);
        if (!$verification['ok']) {
            throw new BackupException("The backup failed verification; nothing was changed:\n - " . implode("\n - ", $verification['errors']));
        }
        $manifest = $verification['manifest'];
        $expected = [];
        foreach ($manifest['files'] as $file) {
            $expected[$file['path']] = $file;
        }

        $work = $this->workDirectory();
        try {
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
