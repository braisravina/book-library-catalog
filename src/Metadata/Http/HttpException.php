<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata\Http;

/**
 * Network failure, policy refusal, timeout, oversized response or an
 * unexpected HTTP status. $status and $retryAfter are set only for status
 * failures, so callers can tell a rate limit or server error (worth backing
 * off from) from a transport problem.
 */
final class HttpException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /**
     * A non-200 answer from a provider. Only the status and the Retry-After
     * header are kept; the body is never logged or shown.
     */
    public static function forStatus(string $provider, HttpResponse $response): self
    {
        return new self($provider . ' returned HTTP ' . $response->status, $response->status, $response->header('retry-after'));
    }
}
