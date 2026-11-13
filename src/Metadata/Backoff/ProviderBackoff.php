<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata\Backoff;

use DigitalLibrary\Metadata\Http\HttpException;

/**
 * Bounded, per-provider backoff after rate limiting (HTTP 429) and
 * temporary server errors (500, 502, 503, 504).
 *
 * While a provider is cooling down it is not contacted at all; the lookup
 * falls back to the next provider and to manual entry. Each further
 * consecutive failure doubles the pause (base, 2x base, ... up to max). A
 * Retry-After header (seconds or HTTP date) is honoured, clamped to
 * [min, max]. Any answer that is not such a failure, including "not found",
 * clears the state. Nothing ever sleeps inside a web request.
 */
final class ProviderBackoff
{
    public const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly BackoffStore $store,
        ?\Closure $clock = null,
        private readonly int $baseSeconds = 30,
        private readonly int $maxSeconds = 900,
        private readonly int $minSeconds = 5,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public static function isRetryable(HttpException $e): bool
    {
        return $e->status !== null && in_array($e->status, self::RETRYABLE_STATUSES, true);
    }

    /**
     * Seconds until $provider may be contacted again (0: now).
     */
    public function remaining(string $provider): int
    {
        $state = $this->store->read($provider);

        return $state === null ? 0 : max(0, $state['until'] - ($this->clock)());
    }

    /**
     * Records a retryable failure and returns the pause applied.
     */
    public function recordFailure(string $provider, ?string $retryAfter): int
    {
        $now = ($this->clock)();
        $pause = 0;
        $this->store->update($provider, function (?array $state) use ($now, $retryAfter, &$pause): array {
            $failures = min(30, ($state['failures'] ?? 0) + 1);
            $requested = self::retryAfterSeconds($retryAfter, $now);
            $pause = $requested !== null
                ? max($this->minSeconds, min($this->maxSeconds, $requested))
                : min($this->maxSeconds, $this->baseSeconds * 2 ** min(20, $failures - 1));

            return ['failures' => $failures, 'until' => $now + $pause];
        });

        return $pause;
    }

    public function recordSuccess(string $provider): void
    {
        if ($this->store->read($provider) !== null) {
            $this->store->update($provider, static fn (?array $state): ?array => null);
        }
    }

    /**
     * Retry-After as delta-seconds or an HTTP date; null when absent or unusable.
     */
    public static function retryAfterSeconds(?string $value, int $now): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{1,10}$/', $value)) {
            return (int) $value;
        }
        $date = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $value, new \DateTimeZone('UTC'));
        if ($date === false) {
            return null;
        }

        return max(0, $date->getTimestamp() - $now);
    }
}
