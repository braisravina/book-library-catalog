<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue;

final class DuplicateIsbnException extends \RuntimeException
{
    public function __construct(public readonly int $existingEditionId)
    {
        parent::__construct('An edition with this ISBN already exists.');
    }
}
