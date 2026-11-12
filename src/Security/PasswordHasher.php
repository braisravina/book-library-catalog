<?php

declare(strict_types=1);

namespace DigitalLibrary\Security;

/**
 * Argon2id password hashing. Parameters follow OWASP's minimum recommendation
 * (m=19 MiB, t=2) with headroom; tune via constructor for slower hosts.
 */
final class PasswordHasher
{
    /** @var array{memory_cost: int, time_cost: int, threads: int} */
    private readonly array $options;

    public function __construct(int $memoryCostKib = 65536, int $timeCost = 3, int $threads = 1)
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            throw new \RuntimeException('Argon2id support is not available in this PHP build.');
        }
        $this->options = ['memory_cost' => $memoryCostKib, 'time_cost' => $timeCost, 'threads' => $threads];
    }

    public function hash(#[\SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, $this->options);
    }

    public function verify(#[\SensitiveParameter] string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options);
    }

    /**
     * Burns comparable CPU time when a user does not exist, so response timing
     * does not reveal which e-mail addresses have accounts.
     */
    public function dummyVerify(#[\SensitiveParameter] string $password): void
    {
        static $dummy = null;
        $dummy ??= $this->hash(bin2hex(random_bytes(16)));
        password_verify($password, $dummy);
    }
}
