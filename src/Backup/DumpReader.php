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

        return self::isLiteralInsert($sql);
    }

    /**
     * INSERT statements exactly as DatabaseDumper writes them: a column list,
     * then tuples with one literal per column (NULL, an integer, a quoted
     * string or X'hex'), separated by ",\n", ending with ";". No
     * expressions, functions, subqueries, variables or comments can appear,
     * so restoring a crafted dump cannot read other tables, call functions
     * or run anything beyond inserting the literal values it carries.
     */
    public static function isLiteralInsert(string $sql): bool
    {
        if (preg_match('/^INSERT INTO `[A-Za-z0-9_]{1,64}` \(((?:`[A-Za-z0-9_]{1,64}`, )*`[A-Za-z0-9_]{1,64}`)\) VALUES /', $sql, $m) !== 1) {
            return false;
        }
        $columns = intdiv(substr_count($m[1], '`'), 2);
        $length = strlen($sql);
        $i = strlen($m[0]);
        while (true) {
            if ($i >= $length || $sql[$i] !== '(') {
                return false;
            }
            $i++;
            for ($c = 0; $c < $columns; $c++) {
                if ($c > 0) {
                    if (substr($sql, $i, 2) !== ', ') {
                        return false;
                    }
                    $i += 2;
                }
                $i = self::literalEnd($sql, $i);
                if ($i === null) {
                    return false;
                }
            }
            if ($i >= $length || $sql[$i] !== ')') {
                return false;
            }
            $i++;
            if ($i === $length - 1 && $sql[$i] === ';') {
                return true;
            }
            if ($i + 2 > $length || substr($sql, $i, 2) !== ",\n") {
                return false;
            }
            $i += 2;
        }
    }

    /**
     * Offset just after the literal starting at $i, or null if there is none.
     */
    private static function literalEnd(string $sql, int $i): ?int
    {
        $length = strlen($sql);
        if ($i >= $length) {
            return null;
        }
        if (substr($sql, $i, 4) === 'NULL') {
            return $i + 4;
        }
        if (preg_match('/\G-?(?:0|[1-9][0-9]{0,19})/', $sql, $m, 0, $i) === 1) {
            return $i + strlen($m[0]);
        }
        if (preg_match("/\\GX'(?:[0-9a-f]{2})*'/", $sql, $m, 0, $i) === 1) {
            return $i + strlen($m[0]);
        }
        if ($sql[$i] !== "'") {
            return null;
        }
        // Quoted string: backslash escapes (PDO::quote) or doubled quotes.
        $j = $i + 1;
        while ($j < $length) {
            $j += strcspn($sql, "'\\", $j);
            if ($j >= $length) {
                return null;
            }
            if ($sql[$j] === '\\') {
                $j += 2;
            } elseif ($j + 1 < $length && $sql[$j + 1] === "'") {
                $j += 2;
            } else {
                return $j + 1;
            }
        }

        return null;
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
