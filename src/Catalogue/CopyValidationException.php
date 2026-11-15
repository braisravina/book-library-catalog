<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue;

/**
 * A copy was refused by the checks that run inside the write transaction
 * (owner, household, location, inventory code, loan state). The errors are
 * translation keys by form field, shown like any other validation error.
 * They never name a record of another household.
 */
final class CopyValidationException extends \RuntimeException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('validation.summary');
    }
}
