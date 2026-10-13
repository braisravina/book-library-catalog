<?php

declare(strict_types=1);

namespace DigitalLibrary\Backup;

use PDO;

/**
 * Creates a backup archive: database dump + cover image files + manifest.
 *
 * Consistency strategy ("snapshot, then files"): the database is dumped
 * from a single consistent snapshot that also yields the list of cover
 * files the data references. Cover files are immutable once written
 * (random names, never modified in place), so copying them afterwards is
 * safe; every referenced cover must be present or the backup FAILS (unless
 * explicitly allowed, which is recorded in the manifest). Files uploaded
 * after the snapshot may also be included; they are harmless extras.
 * Normal operation continues during a backup; for a guaranteed quiescent
 * state use maintenance mode (bin/console maintenance:on).
 *
 * Safety: output only outside the web root, umask 077 (files 0600), work
 * files are hidden temporaries in the output directory, the archive is
 * renamed into place only when complete (atomic on one filesystem), and
 * temporaries are always removed. No secrets, environment files or
 * session files are read; transient login-attempt rows are not included.
 */
final class BackupCreator
{
    public const PREFIX = 'dla-backup-';
    public const SUFFIX = '.tar.gz';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $coversDirectory,
        private readonly string $publicDirectory,
    ) {
    }

    /**
     * @param (\Closure(string): void)|null $log
     *
     * @return array{path: string, sha256: string, size: int, manifest: array<string, mixed>, warnings: list<string>}
     */
    public function create(string $outputDirectory, bool $allowMissingCovers = false, ?\Closure $log = null): array
    {
        $log ??= static function (string $line): void {
        };
        $dir = $this->prepareDirectory($outputDirectory);
        $previousUmask = umask(0077);
        $id = bin2hex(random_bytes(8));
        $tmpDump = $dir . '/.tmp-dla-' . $id . '.sql';
        $tmpArchive = $dir . '/.tmp-dla-' . $id . self::SUFFIX;
        $warnings = [];
        try {
            $this->checkSpace($dir);

            $log('Dumping the database (consistent snapshot)…');
            $out = fopen($tmpDump, 'xb');
            if ($out === false) {
                throw new BackupException('Cannot create a temporary file in the backup directory');
            }
            try {
                $dump = (new DatabaseDumper($this->pdo))->dump($out);
            } finally {
                fclose($out);
            }
            $migrations = array_map('strval', $this->pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN));

            $log('Collecting cover files…');
            [$coverFiles, $skipped] = $this->coverFiles();
            foreach ($skipped as $path) {
                $warnings[] = 'Skipped unexpected item in the covers directory: ' . $path;
            }
            $present = array_flip($coverFiles);
            $missing = array_values(array_filter($dump['cover_keys'], static fn (string $key): bool => !isset($present[$key])));
            if ($missing !== [] && !$allowMissingCovers) {
                throw new BackupException(sprintf(
                    '%d cover file(s) referenced by the database are missing (e.g. %s). Fix or remove those covers, or rerun with --allow-missing-covers.',
                    count($missing),
                    TarPath::printable($missing[0]),
                ));
            }
            foreach ($missing as $key) {
                $warnings[] = 'Referenced cover file missing: ' . $key;
            }

            $log(sprintf('Writing archive (%d cover files)…', count($coverFiles)));
            $tar = new TarWriter($tmpArchive);
            $files = [];
            $files[] = ['path' => Manifest::DATABASE, 'size' => (int) filesize($tmpDump), 'sha256' => $tar->addFile(Manifest::DATABASE, $tmpDump)];
            foreach ($coverFiles as $relative) {
                $source = $this->coversDirectory . '/' . $relative;
                $size = filesize($source);
                $files[] = ['path' => 'covers/' . $relative, 'size' => (int) $size, 'sha256' => $tar->addFile('covers/' . $relative, $source)];
            }
            $manifest = [
                'format' => Manifest::FORMAT,
                'format_version' => Manifest::VERSION,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'application' => [
                    'name' => 'Digital Library Archive',
                    'schema_version' => $migrations === [] ? '' : $migrations[count($migrations) - 1],
                    'migrations' => $migrations,
                ],
                'database' => [
                    'file' => Manifest::DATABASE,
                    'server_version' => $dump['server_version'],
                    'statements' => $dump['statements'],
                    'tables' => $dump['tables'],
                    'schema_only_tables' => DatabaseDumper::SCHEMA_ONLY,
                    'consistency' => 'single REPEATABLE READ transaction started WITH CONSISTENT SNAPSHOT',
                ],
                'covers' => [
                    'referenced' => count($dump['cover_keys']),
                    'files' => count($coverFiles),
                    'missing' => $missing,
                ],
                'files' => $files,
                'excluded' => [
                    'environment files and Docker secrets (.env, *_FILE targets)',
                    'database credentials and server configuration',
                    'PHP session files',
                    'login_attempts rows (transient rate-limiting data)',
                    'application code and dependencies',
                ],
            ];
            $tar->addString(Manifest::NAME, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
            $tar->close();
            @chmod($tmpArchive, 0600);
            $this->sync($tmpArchive);

            $sha256 = (string) hash_file('sha256', $tmpArchive);
            $final = $this->finalName($dir);
            if (!rename($tmpArchive, $final)) {
                throw new BackupException('Cannot move the finished archive into place');
            }
            $sidecar = $final . '.sha256';
            $tmpSidecar = $dir . '/.tmp-dla-' . $id . '.sha256';
            file_put_contents($tmpSidecar, $sha256 . '  ' . basename($final) . "\n");
            @chmod($tmpSidecar, 0600);
            rename($tmpSidecar, $sidecar);
            $log('Backup written: ' . basename($final));

            return ['path' => $final, 'sha256' => $sha256, 'size' => (int) filesize($final), 'manifest' => $manifest, 'warnings' => $warnings];
        } finally {
            foreach ([$tmpDump, $tmpArchive, $dir . '/.tmp-dla-' . $id . '.sha256'] as $tmp) {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
            umask($previousUmask);
        }
    }

    /**
     * @return array{list<string>, list<string>} [cover file paths relative to the covers directory, skipped items]
     */
    public function coverFiles(): array
    {
        $files = [];
        $skipped = [];
        if (!is_dir($this->coversDirectory)) {
            return [[], []];
        }
        $root = rtrim($this->coversDirectory, '/');
        foreach (scandir($root) ?: [] as $bucket) {
            if ($bucket === '.' || $bucket === '..' || $bucket === '.gitkeep') {
                continue;
            }
            $bucketPath = $root . '/' . $bucket;
            if (is_link($bucketPath) || !is_dir($bucketPath) || preg_match('/^[0-9a-f]{2}$/', $bucket) !== 1) {
                $skipped[] = $bucket;
                continue;
            }
            foreach (scandir($bucketPath) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $relative = $bucket . '/' . $name;
                $path = $bucketPath . '/' . $name;
                if (is_link($path) || !is_file($path) || preg_match(Manifest::COVER_PATTERN, 'covers/' . $relative) !== 1) {
                    $skipped[] = $relative;
                    continue;
                }
                $files[] = $relative;
            }
        }
        sort($files, SORT_STRING);

        return [$files, $skipped];
    }

    private function prepareDirectory(string $directory): string
    {
        if ($directory === '' || str_contains($directory, "\0")) {
            throw new BackupException('No backup directory configured');
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new BackupException('Cannot create the backup directory');
        }
        $real = realpath($directory);
        $public = realpath($this->publicDirectory);
        if ($real === false) {
            throw new BackupException('Backup directory is not accessible');
        }
        if ($public !== false && ($real === $public || str_starts_with($real . '/', $public . '/'))) {
            throw new BackupException('Refusing to write backups inside the public web root');
        }
        if (!is_writable($real)) {
            throw new BackupException('Backup directory is not writable');
        }
        @chmod($real, 0700);

        return $real;
    }

    private function checkSpace(string $dir): void
    {
        $database = (int) $this->pdo->query('SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
        $covers = 0;
        foreach ($this->coverFiles()[0] as $relative) {
            $covers += (int) filesize($this->coversDirectory . '/' . $relative);
        }
        // Worst case: an uncompressed dump plus the archive; keep a margin.
        $needed = 2 * $database + $covers + 64 * 1024 * 1024;
        $free = @disk_free_space($dir);
        if ($free !== false && $free < $needed) {
            throw new BackupException(sprintf('Not enough free space: about %d MiB needed, %d MiB available', (int) ceil($needed / 1048576), (int) floor($free / 1048576)));
        }
    }

    private function sync(string $path): void
    {
        $handle = fopen($path, 'rb');
        if ($handle !== false) {
            @fsync($handle);
            fclose($handle);
        }
    }

    private function finalName(string $dir): string
    {
        $base = $dir . '/' . self::PREFIX . gmdate('Ymd\THis\Z');
        $candidate = $base . self::SUFFIX;
        for ($i = 2; file_exists($candidate); $i++) {
            $candidate = $base . '-' . $i . self::SUFFIX;
        }

        return $candidate;
    }
}
