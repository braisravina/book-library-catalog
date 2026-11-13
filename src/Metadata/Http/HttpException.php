<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata\Http;

/**
 * A failed outbound request. $kind tells the cause apart so the lookup can
 * explain it accurately (and back off only where that helps):
 *
 *   status    the provider answered with an unexpected HTTP status ($status)
 *   network   the connection could not be made or broke off
 *   dns       the host name could not be resolved
 *   timeout   no complete answer within the time limit
 *   tls       certificate or TLS verification failed (never bypassed)
 *   too_large the response exceeded its size limit
 *   policy    the URL or a redirect hop is not allowed (OutboundPolicy)
 *   redirect  an unusable redirect or too many of them
 *   invalid   the response is not the JSON structure the provider documents
 *   paced     not sent: no request slot in time (FileRequestPacer)
 *
 * $status and $retryAfter are set only for status failures. Messages carry
 * no response bodies, credentials or personal data.
 */
final class HttpException extends \RuntimeException
{
    public const STATUS = 'status';
    public const NETWORK = 'network';
    public const DNS = 'dns';
    public const TIMEOUT = 'timeout';
    public const TLS = 'tls';
    public const TOO_LARGE = 'too_large';
    public const POLICY = 'policy';
    public const REDIRECT = 'redirect';
    public const INVALID = 'invalid';
    /** Not sent: this application's own request pacing had no slot in time. */
    public const PACED = 'paced';

    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $retryAfter = null,
        public readonly string $kind = self::NETWORK,
    ) {
        parent::__construct($message);
    }

    /**
     * A non-200 answer from a provider. Only the status and the Retry-After
     * header (or a provider-specific hint) are kept; the body is never
     * logged or shown.
     */
    public static function forStatus(string $provider, HttpResponse $response, ?string $retryAfter = null): self
    {
        return new self($provider . ' returned HTTP ' . $response->status, $response->status, $retryAfter ?? $response->header('retry-after'), self::STATUS);
    }

    public static function invalid(string $provider, string $what): self
    {
        return new self($provider . ' returned ' . $what, null, null, self::INVALID);
    }
}
