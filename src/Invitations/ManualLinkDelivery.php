<?php

declare(strict_types=1);

namespace DigitalLibrary\Invitations;

/**
 * No automatic delivery (no SMTP is assumed): the administrator copies the
 * link shown once after issuing the invitation.
 */
final class ManualLinkDelivery implements InvitationDelivery
{
    public function deliver(string $email, #[\SensitiveParameter] string $link, \DateTimeImmutable $expiresAt, string $locale): bool
    {
        return false;
    }
}
