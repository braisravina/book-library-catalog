<?php

declare(strict_types=1);

namespace DigitalLibrary\Invitations;

/**
 * How a freshly issued invitation link reaches the invitee.
 *
 * Phase 4B ships ManualLinkDelivery only: the link is shown once to the
 * administrator, who passes it on. A future e-mail implementation can be
 * wired in Services::invitationDelivery() without touching the service or
 * controllers. Implementations must never log or persist the link.
 */
interface InvitationDelivery
{
    /**
     * @return bool true when the link was delivered and need not be shown;
     *              false when the administrator must copy it manually
     */
    public function deliver(string $email, #[\SensitiveParameter] string $link, \DateTimeImmutable $expiresAt, string $locale): bool;
}
