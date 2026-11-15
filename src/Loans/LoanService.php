<?php

declare(strict_types=1);

namespace DigitalLibrary\Loans;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Auth\UserRepository;
use DigitalLibrary\Catalogue\Repository\Repository;
use DigitalLibrary\Households\WriteActor;
use DigitalLibrary\Households\WriteBusyException;
use DigitalLibrary\Households\WriteDeniedException;
use DigitalLibrary\Households\WriteGuard;
use DigitalLibrary\Security\Authorizer;

/**
 * Lending and returning physical copies.
 *
 * Every operation is one guarded transaction (Households\WriteGuard): the
 * actor and their memberships are read again under the household locks,
 * the copy row is locked (then the loan row), and authorization is checked
 * against those rows:
 *
 *  - a copy outside the actor's households does not exist for them (the
 *    same refusal as a missing copy), so a lender whose membership was
 *    removed can no longer lend or take back that household's copies;
 *  - lending and returning stay with the copy's owner (loan.manage_own)
 *    and administrators (loan.manage_any);
 *  - a borrower with an account must be an active member of the copy's
 *    household at that moment, whoever records the loan (administrators
 *    included); a free-text name is always possible. Without households
 *    every active account can borrow, as before.
 *
 * copies.status is kept in step with the loans table, and the unique
 * active-loan index is the last line of defence against concurrent
 * lending. Loans are never rewritten when memberships change: the history
 * stays with the copy.
 */
final class LoanService
{
    /** Refusals that mean "this copy or loan does not exist for you" (answered with 404). */
    public const NOT_FOUND = ['loans.error.copy_not_found', 'loans.error.not_found'];

    /** The chosen account cannot borrow this copy (shown as an invalid choice, like an unknown account). */
    public const BORROWER_REFUSED = 'loans.error.borrower';

    public function __construct(
        private readonly WriteGuard $guard,
        private readonly LoanRepository $loans,
        private readonly UserRepository $users,
        private readonly Authorizer $authorizer,
    ) {
    }

    public static function today(): string
    {
        return gmdate('Y-m-d');
    }

    /**
     * Accounts a copy may be lent to (besides a free-text name): the
     * active members of the copy's household, or every active account
     * while households are not in use. The copy's owner is left out.
     *
     * @param array<string, mixed> $copy with owner_user_id and household_id
     *
     * @return list<User>
     */
    public function eligibleBorrowers(array $copy): array
    {
        if (!$this->guard->enabled()) {
            $users = array_values(array_filter($this->users->all(), static fn (User $u): bool => $u->isActive));
        } else {
            $users = ($copy['household_id'] ?? null) === null ? [] : $this->users->activeMembersOf((int) $copy['household_id']);
        }
        $ownerId = (int) $copy['owner_user_id'];

        return array_values(array_filter($users, static fn (User $u): bool => $u->id !== $ownerId));
    }

    /**
     * @throws LoanException
     */
    public function lend(User $actor, int $copyId, LoanInput $input): int
    {
        return $this->guard(fn (): int => $this->guard->run($actor->id, function (WriteActor $actor) use ($copyId, $input): int {
            $copy = $this->managedCopy($actor, $copyId);
            $borrowerId = $input->borrowerUserId();
            if ($borrowerId !== null && !$this->mayBorrow($copy, $borrowerId)) {
                throw new LoanException(self::BORROWER_REFUSED);
            }
            if ($this->loans->hasActiveLoan($copyId) || $copy['status'] === 'on_loan') {
                throw new LoanException('loans.error.already_on_loan');
            }
            if ($copy['status'] !== 'available') {
                throw new LoanException('loans.error.copy_unavailable');
            }
            $loanId = $this->loans->create(
                $copyId,
                $borrowerId,
                $input->borrowerName(),
                $input->loanedOn,
                $input->dueOn === '' ? null : $input->dueOn,
                $input->notes === '' ? null : $input->notes,
                $actor->user->id,
            );
            $this->loans->setCopyStatus($copyId, 'on_loan');

            $this->guard->audit('loan.created', $actor->user->id, 'loan', $loanId, ['copy_id' => $copyId, 'borrower_user_id' => $borrowerId, 'due_on' => $input->dueOn === '' ? null : $input->dueOn]);
            return $loanId;
        }, accountIds: $input->borrowerUserId() === null ? [] : [$input->borrowerUserId()]));
    }

    /**
     * @throws LoanException
     */
    public function returnLoan(User $actor, int $loanId): void
    {
        $this->guard(fn () => $this->guard->run($actor->id, function (WriteActor $actor) use ($loanId): void {
            // Lock order: the copy, then its loan.
            $copyId = $this->loans->copyIdOf($loanId) ?? throw new LoanException('loans.error.not_found');
            $copy = $this->managedCopy($actor, $copyId);
            $loan = $this->loans->lockLoan($loanId);
            if ($loan === null || (int) $loan['copy_id'] !== $copyId) {
                throw new LoanException('loans.error.not_found');
            }
            if ($loan['returned_on'] !== null) {
                throw new LoanException('loans.error.already_returned');
            }
            // A loan recorded with today's date can be returned the same day;
            // never record a return before the loan started.
            $returnedOn = max(self::today(), (string) $loan['loaned_on']);
            $this->loans->markReturned($loanId, $returnedOn, $actor->user->id);
            if ($copy['status'] === 'on_loan') {
                $this->loans->setCopyStatus($copyId, 'available');
            }
            $this->guard->audit('loan.returned', $actor->user->id, 'loan', $loanId, ['copy_id' => $copyId]);
        }));
    }

    /**
     * The copy, locked, once the actor has been shown to reach it and to
     * be allowed to manage its loans. A copy of another household is
     * refused exactly like one that does not exist.
     *
     * @return array<string, mixed>
     */
    private function managedCopy(WriteActor $actor, int $copyId): array
    {
        $copy = $this->loans->lockCopy($copyId);
        if ($copy === null || !$actor->reaches($copy['household_id'] === null ? null : (int) $copy['household_id'])) {
            throw new LoanException('loans.error.copy_not_found');
        }
        if (!$this->authorizer->canManageLoans($actor->user, (int) $copy['owner_user_id'])) {
            throw new LoanException('loans.error.forbidden');
        }

        return $copy;
    }

    /**
     * Whether the account may borrow the locked copy now: active, not the
     * owner and, with households, a member of the copy's household (the
     * household locks keep that true until the loan is written).
     *
     * @param array<string, mixed> $copy
     */
    private function mayBorrow(array $copy, int $borrowerId): bool
    {
        $borrower = $this->users->findById($borrowerId);
        if ($borrower === null || !$borrower->isActive || $borrower->id === (int) $copy['owner_user_id']) {
            return false;
        }
        if (!$this->guard->enabled()) {
            return true;
        }

        return $copy['household_id'] !== null && $this->guard->isMember((int) $copy['household_id'], $borrowerId);
    }

    /**
     * Maps database-level concurrency outcomes onto domain errors.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function guard(callable $work): mixed
    {
        try {
            return $work();
        } catch (\PDOException $e) {
            if (Repository::isDuplicateKey($e) && str_contains($e->getMessage(), 'uq_loans_active_copy')) {
                throw new LoanException('loans.error.already_on_loan');
            }
            throw $e;
        } catch (WriteDeniedException) {
            // The account was deactivated while the request was in flight.
            throw new LoanException('loans.error.forbidden');
        } catch (WriteBusyException) {
            // Lock wait timeout, or a deadlock on every attempt: nothing was written.
            throw new LoanException('loans.error.busy');
        }
    }
}
