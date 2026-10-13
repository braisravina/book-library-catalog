<?php

declare(strict_types=1);

namespace DigitalLibrary\Invitations;

/**
 * A refused invitation operation. The message is a translation key; for
 * redemption it is always the same generic key, whatever the reason.
 */
final class InvitationException extends \RuntimeException
{
    public function __construct(string $messageKey, public readonly string $reason = '')
    {
        parent::__construct($messageKey);
    }
}
