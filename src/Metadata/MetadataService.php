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
 * Raw payloads are cached in metadata_records (positive results for
 * $cacheDays, "not found" for one day), so repeated lookups and the later
 * cover import never depend on the provider being reachable. A failing
 * provider is skipped; it never prevents manual entry. Rate limits and
 * temporary server errors put the provider into a bounded backoff
 * (ProviderBackoff), kept apart from "not found", which is cached instead.
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
     * Fresh cached payloads are used first; otherwise each provider is asked
     * in turn unless it is cooling down after a rate limit or server error.
     * With $network false only the cache is read (no outbound request), for
     * re-displaying a lookup the user already made.
     */
    public function lookup(Isbn $isbn, ?int $userId, bool $network = true): LookupResult
    {
        $merged = new BookMetadata();
        $sources = [];
        $failures = [];
        $throttled = [];
        $byProvider = [];
        $primary = null;

        foreach ($this->providers as $provider) {
            $name = $provider->name();
            $cached = $this->cached($provider, $isbn);
            if ($cached !== null) {
                [$recordId, $payload] = $cached;
            } elseif (!$network) {
                continue;
            } elseif ($this->backoff !== null && $this->backoff->remaining($name) > 0) {
                // Not contacted at all: fall back to the next provider.
                $failures[] = $name;
                $throttled[] = $name;
                continue;
            } else {
                try {
                    [$recordId, $payload] = $this->fetch($provider, $isbn, $userId);
                    $this->backoff?->recordSuccess($name);
                } catch (HttpException $e) {
                    $failures[] = $name;
                    if ($this->backoff !== null && ProviderBackoff::isRetryable($e)) {
                        $pause = $this->backoff->recordFailure($name, $e->retryAfter);
                        $throttled[] = $name;
                        error_log(sprintf('[digital-library] metadata provider %s failed: %s; paused for %d s', $name, $e->getMessage(), $pause));
                    } else {
                        error_log(sprintf('[digital-library] metadata provider %s failed: %s', $name, $e->getMessage()));
                    }
                    continue;
                }
            }
            if ($payload === null) {
                continue;
            }
            $metadata = $provider->normalize($payload, $isbn);
            if ($metadata->isEmpty()) {
                continue;
            }
            $merged = $merged->mergedWith($metadata);
            $sources[] = $name;
            $byProvider[$name] = $metadata;
            $primary ??= $recordId;
        }

        return new LookupResult($merged, $sources, $failures, $primary, $byProvider, $throttled);
    }

    /**
     * Normalised data of a cached record, verified to belong to $isbn.
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
                return $provider->normalize($payload, $isbn);
            }
        }

        return null;
    }

    /**
     * A fresh cache entry: record id and payload (null payload: not found).
     *
     * @return array{int, ?array<string, mixed>}|null null when nothing fresh is cached
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
        $notFound = is_array($payload) && isset($payload[self::NOT_FOUND]);
        $maxAge = ($notFound ? 1 : $this->cacheDays) * 86400;
        if (!is_array($payload) || strtotime($row['fetched_at'] . ' UTC') <= time() - $maxAge) {
            return null;
        }

        return [(int) $row['id'], $notFound ? null : $payload];
    }

    /**
     * @return array{int, ?array<string, mixed>} record id and payload (null payload: not found)
     *
     * @throws HttpException
     */
    private function fetch(MetadataProvider $provider, Isbn $isbn, ?int $userId): array
    {
        $payload = $provider->fetch($isbn);
        $json = json_encode($payload ?? [self::NOT_FOUND => true], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->pdo->prepare(
            'INSERT INTO metadata_records (provider, isbn_13, payload, fetched_by, fetched_at)
             VALUES (:p, :i, :payload, :u, UTC_TIMESTAMP()) AS fresh
             ON DUPLICATE KEY UPDATE payload = fresh.payload, fetched_by = fresh.fetched_by, fetched_at = fresh.fetched_at'
        )->execute(['p' => $provider->name(), 'i' => $isbn->isbn13, 'payload' => $json, 'u' => $userId]);
        $id = $this->pdo->prepare('SELECT id FROM metadata_records WHERE provider = :p AND isbn_13 = :i');
        $id->execute(['p' => $provider->name(), 'i' => $isbn->isbn13]);

        return [(int) $id->fetchColumn(), $payload];
    }
}
