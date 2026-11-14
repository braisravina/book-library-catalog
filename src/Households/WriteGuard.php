<?php

declare(strict_types=1);

namespace DigitalLibrary\Households;

use DigitalLibrary\Auth\UserRepository;
use DigitalLibrary\Database\Engine;
use DigitalLibrary\Database\Transaction;
use DigitalLibrary\Security\HouseholdScope;
use DigitalLibrary\Security\Visibility;
use PDO;

/**
 * Transaction-level write authorization (phase H3).
 *
 * A controller decides whether a request may go ahead from what it read
 * when the request started. Between that read and the write a membership
 * can be removed, an account deactivated, a copy moved to another
 * household or a first copy added by another household. Every write that
 * depends on household access therefore runs through run(), which opens
 * the transaction, takes the locks below and hands the work a WriteActor
 * read from the database at that moment. The service then repeats its
 * authorization checks against that actor and against the rows it locks,
 * and only then writes.
 *
 * Lock order (always in this order; a service skips the levels it does
 * not need, it never goes back up):
 *
 *   1. households       every row, shared, in id order (only while the
 *                       household feature is enabled); location mutations
 *                       take exclusive locks even with the feature disabled
 *   2. users            all required accounts in id order (shared; the
 *                       actor is exclusive when the service serialises
 *                       on that account)
 *      H4 transfer operation (after all accounts, before catalogue rows)
 *   3. collections      the collection being changed (exclusive)
 *   4. editions         shared for inventory writes, exclusive for
 *                       metadata edits
 *   5. works            shared for inventory writes, exclusive for
 *                       metadata edits
 *   6. copies           exclusive
 *   7. loans            exclusive
 *   8. locations        shared when a copy is stored in one, exclusive for
 *                       a change of the location tree (in id order)
 *
 * Why the household rows: HouseholdService changes memberships (and
 * deletes households) under an exclusive lock on the household row. A
 * shared lock on every household row means that
 *   - a membership removal waits until the writes in flight have ended,
 *     and a write that starts after the removal sees it: once an
 *     administrator has been told a member was removed, no write
 *     authorized by that membership can still be committed;
 *   - ordinary writes share compatible locks; location mutations serialise;
 *   - a write waits for nothing that HouseholdService needs after its own
 *     household lock, so the two cannot deadlock.
 * A household installation has a handful of rows, so locking all of them
 * is cheaper than discovering which ones a write touches (which would
 * need a read before the lock, and a second look after it).
 *
 * Reads inside run() see the state after the locks. The transaction
 * runs at READ COMMITTED (set for that one transaction only): every
 * statement reads what is committed at that moment, so a check made after
 * a lock was granted sees everything the previous holder of the lock
 * committed. At the default REPEATABLE READ the checks would read a
 * snapshot that can be older than the lock. run() also refuses to join a
 * transaction that is already open. What a check relies on must be
 * protected by a lock held at that point; uniqueness is always backed by
 * a unique index.
 *
 * Failures: a deadlock victim is run again (the server has rolled the
 * transaction back; the work must therefore do nothing but database
 * writes); a lock wait timeout, or a deadlock on every attempt, becomes
 * WriteBusyException after rolling back. Nothing is ever half-written.
 *
 * Except for location tree mutations, while the feature is disabled no
 * household row is locked and
 * the actor is unrestricted, as before households existed; the record
 * locks and the re-read of the account still apply.
 */
final class WriteGuard
{
    public const MAX_ATTEMPTS = 3;

    /**
     * @param \Closure(\DigitalLibrary\Auth\User, HouseholdScope): Visibility $visibility
     *        what the given account may see (Services decides: unrestricted while the feature is disabled)
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly HouseholdRepository $households,
        private readonly UserRepository $users,
        private readonly bool $enabled,
        private readonly \Closure $visibility,
    ) {
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Runs $work in one transaction, after the household and account
     * locks, with the actor as the database describes them now.
     *
     * @template T
     *
     * @param callable(WriteActor): T $work           database work only (it may run more than once)
     * @param bool                    $exclusiveActor lock the actor's account row exclusively
     *                                                (services that serialise a user's own changes)
     *
     * @param bool $exclusiveHouseholds serialise location trees, including when the feature is disabled
     *
     * @param list<int> $accountIds additional accounts locked shared in global id order
     *
     * @return T
     *
     * @throws WriteDeniedException the account no longer exists or is deactivated (forbidden)
     * @throws WriteBusyException   the locks could not be obtained
     */
    public function run(int $actorId, callable $work, bool $exclusiveActor = false, bool $exclusiveHouseholds = false, array $accountIds = []): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('A guarded write must open its own transaction.');
        }
        for ($attempt = 1; ; $attempt++) {
            try {
                // For the next transaction only; the session level is unchanged.
                $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');

                return Transaction::run($this->pdo, function () use ($actorId, $work, $exclusiveActor, $exclusiveHouseholds, $accountIds): mixed {
                    return $work($this->enter($actorId, $exclusiveActor, $exclusiveHouseholds, $accountIds));
                });
            } catch (\PDOException $e) {
                if (!Engine::isLockFailure($e)) {
                    throw $e;
                }
                if (!Engine::isDeadlock($e) || $attempt >= self::MAX_ATTEMPTS) {
                    throw new WriteBusyException($e);
                }
                // Let the transaction that won the deadlock finish first.
                usleep(random_int(5_000, 25_000) * $attempt);
            }
        }
    }

    /**
     * Whether the account is a member of the household now. Only
     * meaningful inside run(), where the household locks keep the answer
     * true until the transaction ends.
     */
    public function isMember(int $householdId, int $userId): bool
    {
        return $this->households->isMember($householdId, $userId);
    }

    private function enter(int $actorId, bool $exclusiveActor, bool $exclusiveHouseholds, array $accountIds): WriteActor
    {
        if ($exclusiveHouseholds) {
            $this->households->lockAllExclusive();
        } elseif ($this->enabled) {
            $this->households->lockAllShared();
        }
        // H4: account IDs only determine lock order, never authorization.
        $ids = array_unique([$actorId, ...$accountIds]);
        sort($ids, SORT_NUMERIC);
        $user = null;
        foreach ($ids as $id) {
            $locked = $this->users->findByIdLocked($id, $id === $actorId && $exclusiveActor);
            if ($id === $actorId) {
                $user = $locked;
            }
        }
        if ($user === null || !$user->isActive) {
            throw WriteDeniedException::forbidden();
        }
        $scope = HouseholdScope::forUser($user, fn (int $id): array => $this->households->idsForUser($id));

        return new WriteActor($user, $scope, ($this->visibility)($user, $scope));
    }
}
