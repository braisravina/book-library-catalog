<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue\Repository;

use PDO;
use PDOStatement;

abstract class Repository
{
    public function __construct(protected readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    protected function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    protected function many(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function scalar(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue(':' . ltrim((string) $name, ':'), is_bool($value) ? (int) $value : $value, $type);
        }
        $stmt->execute();

        return $stmt;
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function insert(string $sql, array $params): int
    {
        $this->run($sql, $params);

        return (int) $this->pdo->lastInsertId();
    }

    public static function isDuplicateKey(\PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
