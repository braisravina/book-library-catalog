<?php

declare(strict_types=1);

namespace DigitalLibrary\Loans;

use DigitalLibrary\Http\Request;
use DigitalLibrary\Validation\Validator;

/**
 * Lending form data. A borrower is either an application user or a free-text
 * name (no contact details are collected).
 */
final class LoanInput
{
    public const MAX_LOAN_YEARS = 5;

    public function __construct(
        public readonly string $borrowerType,
        public readonly string $borrowerUserId,
        public readonly string $borrowerName,
        public readonly string $loanedOn,
        public readonly string $dueOn,
        public readonly string $notes,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $s = static fn (string $k, string $d = ''): string => trim((string) $request->input($k, $d));

        return new self(
            $s('borrower_type', 'name') === 'user' ? 'user' : 'name',
            $s('borrower_user_id'),
            (string) preg_replace('/\s+/u', ' ', $s('borrower_name')),
            $s('loaned_on'),
            $s('due_on'),
            $s('notes'),
        );
    }

    public static function blank(string $today): self
    {
        return new self('name', '', '', $today, '', '');
    }

    /**
     * @param string $today UTC date (Y-m-d)
     */
    public function validate(Validator $validator, string $today, int $copyOwnerId): void
    {
        $rules = [
            'loaned_on' => ['required', 'date'],
            'due_on' => ['date'],
            'notes' => ['max:1000'],
        ];
        if ($this->borrowerType === 'user') {
            $rules['borrower_user_id'] = ['required', 'integer'];
        } else {
            $rules['borrower_name'] = ['required', 'max:150', 'no_control'];
        }
        $checks = Validator::make([
            'loaned_on' => $this->loanedOn,
            'due_on' => $this->dueOn,
            'notes' => $this->notes,
            'borrower_user_id' => $this->borrowerUserId,
            'borrower_name' => $this->borrowerName,
        ], $rules);
        foreach ($checks->errors() as $field => $errors) {
            foreach ($errors as $error) {
                $validator->addError($field, $error);
            }
        }
        if (isset($checks->errors()['loaned_on'])) {
            return;
        }
        if ($this->loanedOn > $today) {
            $validator->addError('loaned_on', 'loans.validation.loaned_in_future');
        } elseif ($this->loanedOn < '1900-01-01') {
            $validator->addError('loaned_on', 'validation.date');
        }
        if ($this->dueOn !== '' && !isset($checks->errors()['due_on'])) {
            $max = (new \DateTimeImmutable($this->loanedOn))->modify('+' . self::MAX_LOAN_YEARS . ' years')->format('Y-m-d');
            if ($this->dueOn < $this->loanedOn) {
                $validator->addError('due_on', 'loans.validation.due_before_loan');
            } elseif ($this->dueOn > $max) {
                $validator->addError('due_on', 'loans.validation.due_too_far');
            }
        }
        if ($this->borrowerType === 'user' && $this->borrowerUserId === (string) $copyOwnerId) {
            $validator->addError('borrower_user_id', 'loans.validation.self');
        }
    }

    public function borrowerUserId(): ?int
    {
        return $this->borrowerType === 'user' ? (int) $this->borrowerUserId : null;
    }

    public function borrowerName(): ?string
    {
        return $this->borrowerType === 'name' ? $this->borrowerName : null;
    }
}
