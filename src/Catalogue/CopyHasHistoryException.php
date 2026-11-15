<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue;

/**
 * A copy with lending history cannot be deleted (it is withdrawn instead).
 * The message is a translation key.
 */
final class CopyHasHistoryException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('loans.error.copy_has_history');
    }
}
