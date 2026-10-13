<?php

declare(strict_types=1);

namespace DigitalLibrary\Invitations;

/**
 * Invitation tokens: 256 random bits, URL-safe base64 (43 characters).
 * Only the SHA-256 hash is ever stored; the raw token exists in the link
 * shown once to the administrator and in the invitee's first request.
 */
final class InvitationToken
{
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }

    public static function hash(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }
}
