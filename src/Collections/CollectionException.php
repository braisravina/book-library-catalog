<?php

declare(strict_types=1);

namespace DigitalLibrary\Collections;

/**
 * A refused collection change; the message is a translation key and
 * $field the form field it belongs to (for refusals shown in the form).
 */
final class CollectionException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'name')
    {
        parent::__construct($message);
    }
}
