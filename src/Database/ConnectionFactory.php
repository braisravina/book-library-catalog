<?php

declare(strict_types=1);

namespace DigitalLibrary\Database;

use DigitalLibrary\Core\Config;
use PDO;

final class ConnectionFactory
{
    /**
     * Runtime connection with the least-privileged (DML-only) account.
     */
    public static function fromConfig(Config $config): PDO
    {
        return self::create(
            $config,
            $config->require('DB_USERNAME'),
            $config->require('DB_PASSWORD'),
        );
    }

    /**
     * Schema-changing connection for migrations. Falls back to the runtime
     * account when no dedicated migration account is configured.
     */
    public static function forMigrations(Config $config): PDO
    {
        $user = $config->get('DB_MIGRATION_USERNAME');
        if ($user === null) {
            return self::fromConfig($config);
        }

        return self::create($config, $user, $config->require('DB_MIGRATION_PASSWORD'));
    }

    public static function create(Config $config, string $user, #[\SensitiveParameter] string $password): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->get('DB_HOST', 'db'),
            $config->int('DB_PORT', 3306),
            $config->require('DB_DATABASE'),
        );

        try {
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (\PDOException $e) {
            // Never propagate the DSN or credentials; keep only the SQLSTATE.
            throw new DatabaseException('Unable to connect to the database (SQLSTATE ' . $e->getCode() . ').', 0);
        }
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY'");

        return $pdo;
    }
}
