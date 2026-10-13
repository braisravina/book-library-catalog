<?php

declare(strict_types=1);

namespace DigitalLibrary\Database;

use PDO;

final class Transaction
{
    /**
     * Runs $work inside a transaction, committing on success and rolling back
     * on any throwable. Nested calls join the outer transaction.
     *
     * @template T
     *
     * @param callable(PDO): T $work
     *
     * @return T
     */
    public static function run(PDO $pdo, callable $work): mixed
    {
        if ($pdo->inTransaction()) {
            return $work($pdo);
        }
        $pdo->beginTransaction();
        try {
            $result = $work($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
