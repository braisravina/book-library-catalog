<?php

declare(strict_types=1);

namespace DigitalLibrary\Database;

use PDO;

/**
 * The few places where MariaDB (production) and MySQL (development and
 * regression tests) need different SQL. Keep this list short: write portable
 * SQL everywhere else (ARCHITECTURE.md, "Production database").
 *
 * MariaDB is recognised only positively (VERSION() "x.y.z-MariaDB..."); any
 * other server is treated as MySQL, so a misidentified MariaDB fails loudly
 * on MySQL-only syntax instead of silently using MariaDB variants.
 */
final class Engine
{
    public const MYSQL = 'mysql';
    public const MARIADB = 'mariadb';

    /** Statement time limit exceeded: MySQL max_execution_time, MariaDB max_statement_time; query interrupted. */
    public const TIMEOUT_ERRORS = [3024, 1969, 1317];

    /** CHECK constraint violated: MySQL 3819, MariaDB 4025. */
    public const CHECK_VIOLATION_ERRORS = [3819, 4025];

    /** Lock wait timeout exceeded (the statement, not the transaction, is rolled back). */
    public const LOCK_WAIT_TIMEOUT = 1205;

    /** Chosen as the deadlock victim: the whole transaction was rolled back. */
    public const DEADLOCK = 1213;

    /** @var \WeakMap<PDO, string>|null */
    private static ?\WeakMap $cache = null;

    public static function of(PDO $pdo): string
    {
        self::$cache ??= new \WeakMap();

        return self::$cache[$pdo] ??= self::fromVersion((string) $pdo->query('SELECT VERSION()')->fetchColumn());
    }

    public static function fromVersion(string $version): string
    {
        return preg_match('/^\d+\.\d+\.\d+-MariaDB(?:[-+.\w]*)$/', $version) === 1 ? self::MARIADB : self::MYSQL;
    }

    /**
     * A SELECT bounded to $milliseconds of execution time. MySQL: the
     * MAX_EXECUTION_TIME optimizer hint (MariaDB ignores it as a comment).
     * MariaDB: SET STATEMENT max_statement_time = <seconds> FOR SELECT ...
     * Both fail with one of TIMEOUT_ERRORS when the limit is reached.
     */
    public static function boundedSelect(PDO $pdo, string $select, int $milliseconds): string
    {
        if (!str_starts_with($select, 'SELECT ') || $milliseconds < 1) {
            throw new \InvalidArgumentException('boundedSelect() needs a SELECT and a positive limit.');
        }
        if (self::of($pdo) === self::MARIADB) {
            return sprintf('SET STATEMENT max_statement_time = %.3F FOR %s', $milliseconds / 1000, $select);
        }

        return 'SELECT /*+ MAX_EXECUTION_TIME(' . $milliseconds . ') */ ' . substr($select, 7);
    }

    public static function isTimeout(\PDOException $e): bool
    {
        return in_array($e->errorInfo[1] ?? null, self::TIMEOUT_ERRORS, true);
    }

    /**
     * The transaction lost a deadlock (same code on both engines). The
     * server has already rolled it back, so it may be run again.
     */
    public static function isDeadlock(\PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === self::DEADLOCK;
    }

    /**
     * A row lock could not be obtained: deadlock victim or lock wait timeout.
     */
    public static function isLockFailure(\PDOException $e): bool
    {
        return in_array($e->errorInfo[1] ?? null, [self::LOCK_WAIT_TIMEOUT, self::DEADLOCK], true);
    }
}
