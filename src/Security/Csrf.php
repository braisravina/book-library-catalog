<?php

declare(strict_types=1);

namespace DigitalLibrary\Security;

/**
 * Synchroniser-token CSRF protection. One token per session, rotated on login.
 */
final class Csrf
{
    public const FIELD = '_csrf';
    public const HEADER = 'X-CSRF-Token';
    private const SESSION_KEY = '_csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function rotate(): void
    {
        $this->session->remove(self::SESSION_KEY);
        $this->token();
    }

    public function isValid(?string $submitted): bool
    {
        $expected = $this->session->get(self::SESSION_KEY);
        if (!is_string($expected) || $expected === '' || $submitted === null || $submitted === '') {
            return false;
        }

        return hash_equals($expected, $submitted);
    }
}
