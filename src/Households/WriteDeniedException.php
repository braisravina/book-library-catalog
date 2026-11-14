<?php

declare(strict_types=1);

namespace DigitalLibrary\Households;

/**
 * A write refused when authorization was checked again inside the
 * transaction (Households\WriteGuard).
 *
 *  - notFound():  the record does not exist, or is outside the actor's
 *                 households. Both are answered identically (404), so a
 *                 refusal never tells that another household holds it.
 *  - forbidden(): the actor can see the record but may not change it (403).
 *
 * Application::renderError() turns it into the same pages as
 * HttpException::notFound() / forbidden().
 */
final class WriteDeniedException extends \RuntimeException
{
    private function __construct(public readonly bool $notFound)
    {
        parent::__construct($notFound ? 'errors.404.message' : 'errors.403.message');
    }

    public static function notFound(): self
    {
        return new self(true);
    }

    public static function forbidden(): self
    {
        return new self(false);
    }
}
