<?php

declare(strict_types=1);

namespace DigitalLibrary\Backup;

/**
 * Reads the statements of a DatabaseDumper file by their length frames
 * (no SQL parsing). Rejects anything that is not exactly that format.
 */
final class DumpReader
{
    private const MAX_STATEMENT_BYTES = 16 * 1024 * 1024;

    /**
     * Only the statement shapes DatabaseDumper writes may be restored.
     * (Each statement is also executed as a single prepared statement, so
     * nothing can be appended after a ";".)
     */
    public static function isAllowed(string $sql): bool
    {
        if (in_array($sql, ['SET NAMES utf8mb4;', "SET time_zone = '+00:00';", 'SET FOREIGN_KEY_CHECKS = 0;', 'SET FOREIGN_KEY_CHECKS = 1;'], true)) {
            return true;
        }
        if (preg_match('/^CREATE TABLE `[A-Za-z0-9_]{1,64}` \(\n/', $sql) === 1) {
            return preg_match('/\) ENGINE=InnoDB[^\n;]*;$/', $sql) === 1
                && preg_match('/\b(DATA|INDEX) DIRECTORY\b|\bCONNECTION\s*=|\bFEDERATED\b|\bENGINE=(?!InnoDB)/i', $sql) !== 1;
        }

        return preg_match('/^INSERT INTO `[A-Za-z0-9_]{1,64}` \((`[A-Za-z0-9_]{1,64}`(, )?)+\) VALUES \(/', $sql) === 1 && str_ends_with($sql, ');');
    }

    /**
     * @return \Generator<int, string> statements in order; the generator's
     *                                 return value is the statement count
     */
    public static function statements(string $path): \Generator
    {
        $in = fopen($path, 'rb');
        if ($in === false) {
            throw new BackupException('Cannot read the database dump');
        }
        try {
            if (rtrim((string) fgets($in), "\n") !== DatabaseDumper::HEADER) {
                throw new BackupException('The database dump has an unknown format');
            }
            $count = 0;
            while (($line = fgets($in, 4096)) !== false) {
                if (preg_match('/^-- dla:statement (\d{1,9})\n$/', $line, $m) === 1) {
                    $length = (int) $m[1];
                    if ($length < 1 || $length > self::MAX_STATEMENT_BYTES) {
                        throw new BackupException('Invalid statement length in the database dump');
                    }
                    $sql = $length > 0 ? (string) fread($in, $length) : '';
                    while (strlen($sql) < $length && !feof($in)) {
                        $sql .= (string) fread($in, $length - strlen($sql));
                    }
                    if (strlen($sql) !== $length || fgetc($in) !== "\n") {
                        throw new BackupException('The database dump is truncated');
                    }
                    $count++;
                    yield $sql;
                } elseif (preg_match('/^-- dla:end (\d+)\n$/', $line, $m) === 1) {
                    if ((int) $m[1] !== $count || fgets($in) !== false) {
                        throw new BackupException('The database dump end marker does not match its contents');
                    }

                    return $count;
                } elseif (!str_starts_with($line, '-- ')) {
                    throw new BackupException('Unexpected content in the database dump');
                }
            }
            throw new BackupException('The database dump is truncated (no end marker)');
        } finally {
            fclose($in);
        }
    }
}
