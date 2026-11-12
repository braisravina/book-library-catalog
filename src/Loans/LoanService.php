<?php

declare(strict_types=1);

namespace DigitalLibrary\Loans;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Catalogue\Repository\Repository;
use DigitalLibrary\Database\Transaction;
use DigitalLibrary\Security\Authorizer;
use PDO;

/**
 * Lending and returning physical copies.
 *
 * Every operation runs in one transaction that locks the copy (and loan)
 * rows, re-checks authorization against the locked copy owner, and keeps
 * copies.status in step with the loans table. The unique active-loan index
 * is the last line of defence against concurrent lending.
 */
final class LoanService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LoanRepository $loans,
        private readonly Authorizer $authorizer,
    ) {
    }

    public static function today(): string
    {
        return gmdate('Y-m-d');
    }

    /**
     * @throws LoanException
     */
    public function lend(User $actor, int $copyId, LoanInput $input): int
    {
        return $this->guard(fn (): int => Transaction::run($this->pdo, function () use ($actor, $copyId, $input): int {
            $copy = $this->loans->lockCopy($copyId) ?? throw new LoanException('loans.error.copy_not_found');
            if (!$this->authorizer->canManageLoans($actor, (int) $copy['owner_user_id'])) {
                throw new LoanException('loans.error.forbidden');
            }
            if ($this->loans->activeForCopy($copyId) !== null || $copy['status'] === 'on_loan') {
                throw new LoanException('loans.error.already_on_loan');
            }
            if ($copy['status'] !== 'available') {
                throw new LoanException('loans.error.copy_unavailable');
            }
            $loanId = $this->loans->create(
                $copyId,
                $input->borrowerUserId(),
                $input->borrowerName(),
                $input->loanedOn,
                $input->dueOn === '' ? null : $input->dueOn,
                $input->notes === '' ? null : $input->notes,
                $actor->id,
            );
            $this->loans->setCopyStatus($copyId, 'on_loan');

            return $loanId;
        }));
    }

    /**
     * @throws LoanException
     */
    public function returnLoan(User $actor, int $loanId): void
    {
        $this->guard(fn () => Transaction::run($this->pdo, function () use ($actor, $loanId): void {
            $loan = $this->loans->lockLoan($loanId) ?? throw new LoanException('loans.error.not_found');
            $copy = $this->loans->lockCopy((int) $loan['copy_id']) ?? throw new LoanException('loans.error.copy_not_found');
            if (!$this->authorizer->canManageLoans($actor, (int) $copy['owner_user_id'])) {
                throw new LoanException('loans.error.forbidden');
            }
            if ($loan['returned_on'] !== null) {
                throw new LoanException('loans.error.already_returned');
            }
            // A loan recorded with today's date can be returned the same day;
            // never record a return before the loan started.
            $returnedOn = max(self::today(), (string) $loan['loaned_on']);
            $this->loans->markReturned($loanId, $returnedOn, $actor->id);
            if ($copy['status'] === 'on_loan') {
                $this->loans->setCopyStatus((int) $copy['id'], 'available');
            }
        }));
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
            $code = $e->errorInfo[1] ?? null;
            if ($code === 1205 || $code === 1213) { // lock wait timeout / deadlock
                throw new LoanException('loans.error.busy');
            }
            throw $e;
        }
    }
}
