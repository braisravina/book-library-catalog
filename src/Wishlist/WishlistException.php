<?php

declare(strict_types=1);

namespace DigitalLibrary\Wishlist;

/**
 * A wishlist change that was refused. The message is a translation key;
 * for duplicates, $existingId names the entry that is already wanted.
 */
final class WishlistException extends \RuntimeException
{
    public function __construct(string $messageKey, public readonly ?int $existingId = null)
    {
        parent::__construct($messageKey);
    }
}
