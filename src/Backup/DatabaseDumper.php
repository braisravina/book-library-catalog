<?php

declare(strict_types=1);

namespace DigitalLibrary\Backup;

use PDO;

/**
 * Logical MySQL dump written by PHP (no mysqldump binary needed in the
 * application image).
 *
 * Consistency: everything (table definitions, rows and the list of cover
 * files the rows reference) is read inside ONE read-only transaction
 * started WITH CONSISTENT SNAPSHOT at REPEATABLE READ, so the dump is a
 * point-in-time image of the database without locking writers.
 *
 * File format (still valid input for the mysql client): a header line,
 * then each statement preceded by "-- dla:statement <bytes>" so the
 * restorer can read statements exactly, without parsing SQL, and a final
 * "-- dla:end <count>" line.
 */
final class DatabaseDumper
{
    public const HEADER = '-- Digital Library Archive database dump, format 1';
    private const ROWS_PER_INSERT = 250;
    private const MAX_STATEMENT_BYTES = 1 << 20;
    /** Tables whose rows are transient and not backed up (definition only). */
    public const SCHEMA_ONLY = ['login_attempts'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param resource $out
     *
     * @return array{tables: array<string, int>, cover_keys: list<string>, server_version: string, statements: int}
     */
    public function dump($out): array
    {
        $statements = 0;
        $emit = function (string $sql) use ($out, &$statements): void {
            $this->write($out, '-- dla:statement ' . strlen($sql) . "\n" . $sql . "\n");
            $statements++;
        };

        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
            $this->write($out, self::HEADER . "\n-- MySQL " . preg_replace('/[^0-9A-Za-z.\-]/', '', $version) . ', created ' . gmdate('Y-m-d\TH:i:s\Z') . "\n");
            $emit('SET NAMES utf8mb4;');
            $emit("SET time_zone = '+00:00';");
            $emit('SET FOREIGN_KEY_CHECKS = 0;');

            $counts = [];
            foreach ($this->tables() as $table) {
                $create = $this->pdo->query('SHOW CREATE TABLE ' . self::ident($table))->fetch(PDO::FETCH_NUM);
                $emit(rtrim((string) $create[1]) . ';');
                $counts[$table] = in_array($table, self::SCHEMA_ONLY, true) ? 0 : $this->rows($table, $emit);
            }
            $emit('SET FOREIGN_KEY_CHECKS = 1;');
            $coverKeys = array_map('strval', $this->pdo->query('SELECT storage_key FROM covers ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        } finally {
            $this->pdo->exec('COMMIT');
        }
        $this->write($out, '-- dla:end ' . $statements . "\n");

        return ['tables' => $counts, 'cover_keys' => $coverKeys, 'server_version' => $version, 'statements' => $statements];
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $tables = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        $tables = array_map('strval', $tables);
        sort($tables, SORT_STRING);

        return $tables;
    }

    /**
     * @param \Closure(string): void $emit
     */
    private function rows(string $table, \Closure $emit): int
    {
        $columns = $this->pdo->prepare(
            "SELECT COLUMN_NAME, DATA_TYPE, EXTRA, COLUMN_KEY FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t ORDER BY ORDINAL_POSITION"
        );
        $columns->execute(['t' => $table]);
        $writable = [];
        $binary = [];
        foreach ($columns->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (str_contains(strtoupper((string) $column['EXTRA']), 'GENERATED')) {
                continue; // generated columns are recomputed on insert
            }
            $writable[] = (string) $column['COLUMN_NAME'];
            if (in_array(strtolower((string) $column['DATA_TYPE']), ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit'], true)) {
                $binary[(string) $column['COLUMN_NAME']] = true;
            }
        }
        $keys = $this->primaryKey($table);
        $select = 'SELECT ' . implode(', ', array_map(self::ident(...), $writable)) . ' FROM ' . self::ident($table);
        $order = ' ORDER BY ' . implode(', ', array_map(self::ident(...), $keys));
        $prefix = 'INSERT INTO ' . self::ident($table) . ' (' . implode(', ', array_map(self::ident(...), $writable)) . ') VALUES ';

        $count = 0;
        $after = null;
        $batch = [];
        $batchBytes = 0;
        do {
            if ($after === null) {
                $stmt = $this->pdo->query($select . $order . ' LIMIT 1000');
            } else {
                $placeholders = implode(', ', array_map(static fn (int $i): string => ':k' . $i, array_keys($keys)));
                $stmt = $this->pdo->prepare($select . ' WHERE (' . implode(', ', array_map(self::ident(...), $keys)) . ') > (' . $placeholders . ')' . $order . ' LIMIT 1000');
                foreach (array_values($after) as $i => $value) {
                    $stmt->bindValue(':k' . $i, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
                }
                $stmt->execute();
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $values = [];
                foreach ($writable as $column) {
                    $values[] = $this->literal($row[$column], isset($binary[$column]));
                }
                $tuple = '(' . implode(', ', $values) . ')';
                if ($batch !== [] && (count($batch) >= self::ROWS_PER_INSERT || $batchBytes + strlen($tuple) > self::MAX_STATEMENT_BYTES)) {
                    $emit($prefix . implode(",\n", $batch) . ';');
                    $batch = [];
                    $batchBytes = 0;
                }
                $batch[] = $tuple;
                $batchBytes += strlen($tuple) + 2;
                $count++;
            }
            if ($rows !== []) {
                $last = $rows[count($rows) - 1];
                $after = array_map(static fn (string $k): mixed => $last[$k], $keys);
            }
        } while (count($rows) === 1000);
        if ($batch !== []) {
            $emit($prefix . implode(",\n", $batch) . ';');
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    private function primaryKey(string $table): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = 'PRIMARY' ORDER BY SEQ_IN_INDEX"
        );
        $stmt->execute(['t' => $table]);
        $keys = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($keys === []) {
            throw new BackupException('Table without a primary key cannot be dumped consistently: ' . $table);
        }

        return $keys;
    }

    private function literal(mixed $value, bool $binary): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_int($value) => (string) $value,
            $binary => "X'" . bin2hex((string) $value) . "'",
            default => (string) $this->pdo->quote((string) $value),
        };
    }

    public static function ident(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new BackupException('Unexpected identifier: ' . TarPath::printable($name));
        }

        return '`' . $name . '`';
    }

    /**
     * @param resource $out
     */
    private function write($out, string $bytes): void
    {
        if (fwrite($out, $bytes) !== strlen($bytes)) {
            throw new BackupException('Write error while dumping the database (disk full?)');
        }
    }
}
