<?php

declare(strict_types=1);

namespace DigitalLibrary\Households;

/**
 * A refused location change. The message is a translation key and $field
 * the form field it belongs to ('' for a refusal that is not about a field).
 */
final class LocationException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $field = '')
    {
        parent::__construct($message);
    }
}
