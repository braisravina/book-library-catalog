<?php

declare(strict_types=1);

namespace DigitalLibrary\Http;

/**
 * An exception that maps directly to an HTTP status. The message is a
 * translation key, never internal detail.
 */
class HttpException extends \RuntimeException
{
    public function __construct(private readonly int $status, string $messageKey = '')
    {
        parent::__construct($messageKey !== '' ? $messageKey : 'errors.' . $status . '.message', $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public static function notFound(): self
    {
        return new self(404);
    }

    public static function forbidden(): self
    {
        return new self(403);
    }

    public static function methodNotAllowed(): self
    {
        return new self(405);
    }
}
