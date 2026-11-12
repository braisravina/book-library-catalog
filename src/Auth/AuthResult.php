<?php

declare(strict_types=1);

namespace DigitalLibrary\Auth;

final class AuthResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?User $user = null,
        public readonly int $retryAfter = 0,
    ) {
    }

    public static function success(User $user): self
    {
        return new self(true, $user);
    }

    public static function failure(): self
    {
        return new self(false);
    }

    public static function throttled(int $retryAfter): self
    {
        return new self(false, null, $retryAfter);
    }

    public function isThrottled(): bool
    {
        return $this->retryAfter > 0;
    }
}
