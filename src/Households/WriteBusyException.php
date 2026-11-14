<?php

declare(strict_types=1);

namespace DigitalLibrary\Households;

/**
 * A guarded write could not obtain its row locks: it waited too long for
 * another change, or kept losing deadlocks. Nothing was written (the
 * transaction was rolled back) and the same request may simply be sent
 * again. The message is a translation key.
 */
final class WriteBusyException extends \RuntimeException
{
    public const MESSAGE = 'errors.busy.message';

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(self::MESSAGE, 0, $previous);
    }
}
