<?php

declare(strict_types=1);

namespace DigitalLibrary\Auth;

use DigitalLibrary\Database\Transaction;
use DigitalLibrary\Database\Engine;
use DigitalLibrary\Households\WriteBusyException;
use DigitalLibrary\Households\HouseholdRepository;
use DigitalLibrary\Households\WriteDeniedException;
use DigitalLibrary\Security\Role;
use PDO;

/** Database-only administrative work. Lock households before sorted accounts. */
final class AdministratorGuard
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Account administration locks the complete account set exclusively in PK
     * order, before inspecting the active-admin set. No shared-to-exclusive
     * upgrades and no target-before-actor ordering. Other operations lock only
     * the actor and explicitly required accounts, shared, in the same order.
     * Callbacks must not perform filesystem, session or network work.
     */
    public function run(int $actorId, callable $work, ?HouseholdRepository $households = null, bool $exclusiveHouseholds = false, bool $accounts = false, array $accountIds = []): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Administrative writes must open their own transaction.');
        }
        for ($attempt = 1; ; $attempt++) {
            try {
                $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');

                return Transaction::run($this->pdo, function () use ($actorId, $work, $households, $exclusiveHouseholds, $accounts, $accountIds): mixed {
                    if ($households !== null) {
                        $exclusiveHouseholds ? $households->lockAllExclusive() : $households->lockAllShared();
                    }
                    $users = new UserRepository($this->pdo);
                    if ($accounts) {
                        $this->pdo->query('SELECT id FROM users ORDER BY id FOR UPDATE')->fetchAll();
                    } else {
                        $ids = array_unique([$actorId, ...$accountIds]);
                        sort($ids, SORT_NUMERIC);
                        foreach ($ids as $id) {
                            $users->findByIdLocked($id);
                        }
                    }
                    $actor = $users->findById($actorId);
                    if ($actor === null || !$actor->isActive || $actor->role !== Role::Admin) {
                        throw WriteDeniedException::forbidden();
                    }

                    return $work($actor);
                });
            } catch (\PDOException $e) {
                if (!Engine::isLockFailure($e)) {
                    throw $e;
                }
                if (!Engine::isDeadlock($e) || $attempt >= 3) {
                    throw new WriteBusyException($e);
                }
                usleep(random_int(5_000, 25_000) * $attempt);
            }
        }
    }
}
