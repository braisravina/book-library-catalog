<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue;

/**
 * The record changed after the editing form was loaded.
 */
final class EditConflictException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('catalogue.edit_conflict');
    }
}
