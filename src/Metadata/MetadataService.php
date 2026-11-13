<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata;

use DigitalLibrary\Catalogue\Isbn;
use DigitalLibrary\Metadata\Backoff\ProviderBackoff;
use DigitalLibrary\Metadata\Http\HttpException;
use DigitalLibrary\Metadata\Provider\MetadataProvider;
use PDO;

/**
 * Looks an ISBN up across the configured providers, in priority order.
 *
 * Every provider ends in one explicit outcome (found, questionable,
 * ambiguous, not found, cooling down, rate-limited, server error, DNS,
 * network, timeout, TLS, invalid response, blocked, paced), so the page can
 * say exactly what happened per provider. Questionable records (see
 * MetadataChecks) are kept for review but never pre-fill the form or supply
 * a cover. Nothing is saved to the catalogue here.
 *
 * Cache (metadata_records, one row per provider and ISBN; the raw payload
 * in the provider's current format): positive results for $cacheDays,
 * "not found" and partial results for one day; failures are not cached.
 * Payloads in an older format are refreshed and never used as a fallback.
 * Rate limits and temporary server errors put the provider into a bounded
 * backoff (ProviderBackoff); a fresh cached answer is still used meanwhile.
 * Each write is one atomic upsert, so concurrent lookups of the same ISBN
 * leave one row with one complete payload. invalidate() makes chosen rows
 * stale without deleting them (editions may reference them).
 */
final class MetadataService
{
    private const NOT_FOUND = '__not_found';

    /**
     * @param list<MetadataProvider> $providers
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $providers,
        private readonly int $cacheDays = 30,
        private readonly ?ProviderBackoff $backoff = null,
    ) {
    }

    public function enabled(): bool
    {
        return $this->providers !== [];
    }

    /**
     * @return list<string>
     */
    public function providerNames(): array
    {
        return array_map(static fn (MetadataProvider $p): string => $p->name(), $this->providers);
    }

    /**
     * With $network false only fresh cache entries are read (no outbound
     * request), for re-displaying a lookup the user already made.
     */
    public function lookup(Isbn $isbn, ?int $userId, bool $network = true): LookupResult
    {
        $merged = new BookMetadata();
        $sources = [];
        $failures = [];
        $throttled = [];
        $byProvider = [];
        $records = [];
        $outcomes = [];
        $primary = null;

        foreach ($this->providers as $provider) {
            $name = $provider->name();
            $outcome = ['provider' => $name, 'status' => 'not_found', 'cached' => false, 'retry_in' => null, 'incomplete' => false];
            $cached = $this->cached($provider, $isbn);
            if ($cached !== null) {
                [$recordId, $payload] = $cached;
                $outcome['cached'] = true;
            } elseif (!$network) {
                continue;
            } elseif ($this->backoff !== null && ($remaining = $this->backoff->remaining($name)) > 0) {
                // Not contacted at all: the next provider and manual entry still work.
                $failures[] = $name;
                $throttled[] = $name;
                $outcomes[] = ['status' => 'cooling_down', 'retry_in' => $remaining] + $outcome;
                continue;
            } else {
                try {
                    [$recordId, $payload] = $this->fetch($provider, $isbn, $userId, $partialFailure);
                    if (!$partialFailure) {
                        // Any complete answer, "not found" included, ends a pause.
                        $this->backoff?->recordSuccess($name);
                    }
                } catch (HttpException $e) {
                    $failures[] = $name;
                    $status = self::statusOf($e);
                    if ($this->backoff !== null && ProviderBackoff::isRetryable($e)) {
                        $pause = $this->backoff->recordFailure($name, $e->retryAfter);
                        $throttled[] = $name;
                        $outcome['retry_in'] = $pause > 0 ? $pause : null;
                        error_log(sprintf('[digital-library] metadata provider %s failed (%s): %s; paused for %d s', $name, $status, $e->getMessage(), $pause));
                    } else {
                        error_log(sprintf('[digital-library] metadata provider %s failed (%s): %s', $name, $status, $e->getMessage()));
                    }
                    $outcomes[] = ['status' => $status] + $outcome;
                    continue;
                }
            }
            if ($payload === null) {
                $outcomes[] = $outcome; // not_found
                continue;
            }
            $record = $provider->describe($payload, $isbn);
            $outcome['incomplete'] = $record->incomplete !== [];
            if ($record->metadata->isEmpty()) {
                if ($record->candidates !== []) {
                    $records[$name] = $record;
                    $outcome['status'] = 'ambiguous';
                }
                $outcomes[] = $outcome;
                continue;
            }
            $records[$name] = $record;
            if ($record->questionable()) {
                $outcomes[] = ['status' => 'questionable'] + $outcome;
                continue;
            }
            $outcomes[] = ['status' => 'found'] + $outcome;
            $merged = $merged->mergedWith($record->metadata);
            $sources[] = $name;
            $byProvider[$name] = $record->metadata;
            $primary ??= $recordId;
        }

        return new LookupResult($merged, $sources, $failures, $primary, $byProvider, $throttled, $records, $outcomes);
    }

    /**
     * Normalised data of a cached record, verified to belong to $isbn and
     * not questionable (used for linking and cover import).
     */
    public function cachedRecord(int $recordId, Isbn $isbn): ?BookMetadata
    {
        $stmt = $this->pdo->prepare('SELECT provider, isbn_13, payload FROM metadata_records WHERE id = :id');
        $stmt->execute(['id' => $recordId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['isbn_13'] !== $isbn->isbn13) {
            return null;
        }
        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload) || isset($payload[self::NOT_FOUND])) {
            return null;
        }
        foreach ($this->providers as $provider) {
            if ($provider->name() === $row['provider']) {
                $record = $provider->describe($payload, $isbn);

                return $record->usable() ? $record->metadata : null;
            }
        }

        return null;
    }

    /**
     * Makes the cached answers for $isbn stale (all providers, or one), so
     * the next lookup asks again. Rows are kept: editions may reference them.
     *
     * @return int rows marked stale
     */
    public function invalidate(Isbn $isbn, ?string $provider = null): int
    {
        if ($provider !== null && !in_array($provider, $this->providerNames(), true)) {
            throw new \InvalidArgumentException('Unknown metadata provider: ' . $provider);
        }
        $sql = "UPDATE metadata_records SET fetched_at = '2000-01-01 00:00:00' WHERE isbn_13 = :i";
        $params = ['i' => $isbn->isbn13];
        if ($provider !== null) {
            $sql .= ' AND provider = :p';
            $params['p'] = $provider;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    private static function statusOf(HttpException $e): string
    {
        if ($e->status !== null) {
            return match (true) {
                $e->status === 429 => 'rate_limited',
                in_array($e->status, ProviderBackoff::RETRYABLE_STATUSES, true) => 'server_error',
                default => 'unavailable',
            };
        }

        return match ($e->kind) {
            HttpException::DNS => 'dns',
            HttpException::TIMEOUT => 'timeout',
            HttpException::TLS => 'tls',
            HttpException::INVALID, HttpException::TOO_LARGE => 'invalid',
            HttpException::POLICY, HttpException::REDIRECT => 'blocked',
            HttpException::PACED => 'paced',
            default => 'network',
        };
    }

    /**
     * A fresh cache entry in the provider's current format: record id and
     * payload (null payload: not found). Corrupt entries are ignored (and
     * replaced by the next successful lookup).
     *
     * @return array{int, ?array<string, mixed>}|null
     */
    private function cached(MetadataProvider $provider, Isbn $isbn): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, payload, fetched_at FROM metadata_records WHERE provider = :p AND isbn_13 = :i'
        );
        $stmt->execute(['p' => $provider->name(), 'i' => $isbn->isbn13]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload)) {
            return null;
        }
        $notFound = isset($payload[self::NOT_FOUND]);
        if (!$notFound && !$provider->isCurrent($payload)) {
            return null; // older format: refresh, never fall back to it
        }
        $partial = !$notFound && is_array($payload['incomplete'] ?? null) && $payload['incomplete'] !== [];
        $maxAge = ($notFound || $partial ? 1 : $this->cacheDays) * 86400;
        $fetched = strtotime($row['fetched_at'] . ' UTC');
        if ($fetched === false || $fetched <= time() - $maxAge) {
            return null;
        }

        return [(int) $row['id'], $notFound ? null : $payload];
    }

    /**
     * @return array{int, ?array<string, mixed>} record id and payload (null payload: not found)
     *
     * @throws HttpException
     */
    private function fetch(MetadataProvider $provider, Isbn $isbn, ?int $userId, ?bool &$partialFailure = null): array
    {
        $payload = $provider->fetch($isbn);
        $partialFailure = $payload !== null && array_key_exists('_retry_after', $payload);
        if ($partialFailure) {
            // A secondary endpoint was rate-limited or failing: same shared backoff.
            $this->backoff?->recordFailure($provider->name(), $payload['_retry_after'] === '' ? null : (string) $payload['_retry_after']);
            unset($payload['_retry_after']);
        }
        $json = json_encode($payload ?? [self::NOT_FOUND => true], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        // Portable atomic upsert (MariaDB and MySQL): no row alias ("VALUES
        // ... AS fresh" is MySQL-only) and no deprecated VALUES() function;
        // the new values are bound a second time under their own placeholder
        // names.
        $this->pdo->prepare(
            'INSERT INTO metadata_records (provider, isbn_13, payload, fetched_by, fetched_at)
             VALUES (:p, :i, :payload, :u, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE payload = :payload_update, fetched_by = :u_update, fetched_at = UTC_TIMESTAMP()'
        )->execute(['p' => $provider->name(), 'i' => $isbn->isbn13, 'payload' => $json, 'u' => $userId, 'payload_update' => $json, 'u_update' => $userId]);
        $id = $this->pdo->prepare('SELECT id FROM metadata_records WHERE provider = :p AND isbn_13 = :i');
        $id->execute(['p' => $provider->name(), 'i' => $isbn->isbn13]);

        return [(int) $id->fetchColumn(), $payload];
    }
}
