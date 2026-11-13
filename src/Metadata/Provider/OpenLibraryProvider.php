<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata\Provider;

use DigitalLibrary\Catalogue\Isbn;
use DigitalLibrary\Metadata\Backoff\RequestPacer;
use DigitalLibrary\Metadata\BookMetadata;
use DigitalLibrary\Metadata\Http\HttpClient;
use DigitalLibrary\Metadata\Http\HttpException;
use DigitalLibrary\Metadata\Http\HttpResponse;
use DigitalLibrary\Metadata\MetadataChecks;
use DigitalLibrary\Metadata\ProviderRecord;

/**
 * Open Library (https://openlibrary.org/developers/api), one provider for
 * caching, backoff and pacing, with at most three requests per lookup:
 *
 *   1. /isbn/{isbn}.json      the exact edition (Open Library redirects to
 *                             /books/OL…M.json); used only if the edition
 *                             itself lists the requested ISBN;
 *   2. /search.json?isbn=     with a minimal field list: author names, the
 *                             edition's work, and every OTHER record that
 *                             claims the ISBN (the same ISBN can be attached
 *                             to unrelated or spam editions); also the
 *                             fallback when there is no edition record, used
 *                             only when exactly one work claims the ISBN;
 *   3. /works/{id}.json       optional, only when the edition has no
 *                             description: work-level enrichment, never in
 *                             place of edition data, and labelled as such.
 *
 * The legacy Books API (/api/books, "may be phased out") is no longer
 * called; payloads it wrote earlier are still read (isCurrent() false, so
 * they are refreshed on the next lookup). Requests are paced to Open
 * Library's published limit for applications without a contact (1/s).
 */
final class OpenLibraryProvider implements MetadataProvider
{
    public const FORMAT = 2;
    private const BASE = 'https://openlibrary.org';
    private const MAX_BYTES = 1_048_576;
    private const SEARCH_FIELDS = 'key,title,subtitle,author_name,first_publish_year,editions,editions.key,editions.title,editions.subtitle,editions.isbn,editions.publisher,editions.publish_date,editions.language';

    public function __construct(
        private readonly HttpClient $http,
        private readonly ?RequestPacer $pacer = null,
    ) {
    }

    public function name(): string
    {
        return 'open_library';
    }

    public static function editionUrl(Isbn $isbn): string
    {
        return self::BASE . '/isbn/' . $isbn->isbn13 . '.json';
    }

    public static function searchUrl(Isbn $isbn): string
    {
        return self::BASE . '/search.json?isbn=' . $isbn->isbn13 . '&fields=' . self::SEARCH_FIELDS . '&limit=5';
    }

    public static function workUrl(string $workId): string
    {
        return self::BASE . '/works/' . $workId . '.json';
    }

    public function isCurrent(array $payload): bool
    {
        return ($payload['v'] ?? null) === self::FORMAT;
    }

    public function fetch(Isbn $isbn): ?array
    {
        $payload = ['v' => self::FORMAT, 'edition' => null, 'search' => null, 'work' => null, 'incomplete' => []];

        // 1. The exact edition.
        $this->pace(false);
        $response = $this->http->get(self::editionUrl($isbn), self::MAX_BYTES);
        if ($response->status === 200) {
            $payload['edition'] = self::trimEdition(self::json($response));
        } elseif ($response->status !== 404) {
            throw HttpException::forStatus('Open Library', $response);
        }

        // 2. Search: required without an edition (it is then the only source).
        $required = $payload['edition'] === null;
        try {
            if (!$this->pace(!$required)) {
                throw new HttpException('Open Library request slot not available', null, null, HttpException::PACED);
            }
            $response = $this->http->get(self::searchUrl($isbn), self::MAX_BYTES);
            if ($response->status !== 200) {
                throw HttpException::forStatus('Open Library', $response);
            }
            $payload['search'] = self::trimSearch(self::json($response));
        } catch (HttpException $e) {
            if ($required) {
                throw $e;
            }
            $payload = self::markIncomplete($payload, 'search', $e);
        }

        // 3. Work-level description, only if the edition has none.
        $workId = self::workId($payload['edition']);
        if ($payload['edition'] !== null && $workId !== null && ($payload['edition']['description'] ?? '') === '') {
            try {
                if ($this->pace(true)) {
                    $response = $this->http->get(self::workUrl($workId), self::MAX_BYTES);
                    if ($response->status !== 200) {
                        throw HttpException::forStatus('Open Library', $response);
                    }
                    $payload['work'] = self::trimWork(self::json($response));
                } else {
                    $payload['incomplete'][] = 'work:paced';
                }
            } catch (HttpException $e) {
                $payload = self::markIncomplete($payload, 'work', $e);
            }
        }

        if ($payload['edition'] === null && self::claimants($payload['search'] ?? [], $isbn) === []) {
            return null; // no edition and no search result lists the ISBN
        }

        return $payload;
    }

    public function normalize(array $payload, Isbn $isbn): BookMetadata
    {
        return $this->describe($payload, $isbn)->metadata;
    }

    public function describe(array $payload, Isbn $isbn): ProviderRecord
    {
        if (!$this->isCurrent($payload)) {
            return $this->describeLegacy($payload, $isbn);
        }
        $edition = is_array($payload['edition'] ?? null) ? $payload['edition'] : null;
        $docs = is_array($payload['search'] ?? null) ? $payload['search'] : [];
        $claimants = self::claimants($docs, $isbn);
        $origins = [];
        $identifiers = [];
        $candidates = [];
        $signals = ['incomplete' => is_array($payload['incomplete'] ?? null) && $payload['incomplete'] !== []];

        if ($edition !== null && !self::listsIsbn($edition, $isbn)) {
            // The redirect led to an edition that does not carry this ISBN: never used.
            $edition = null;
        }

        if ($edition !== null) {
            $editionId = substr((string) $edition['key'], 7);
            $workId = self::workId($edition);
            $identifiers['ol_edition'] = $editionId;
            if ($workId !== null) {
                $identifiers['ol_work'] = $workId;
            }
            $authors = [];
            foreach ($docs as $doc) {
                if ($workId !== null && $doc['key'] === '/works/' . $workId) {
                    $authors = $doc['author_name'];
                }
            }
            $description = (string) ($edition['description'] ?? '');
            $work = is_array($payload['work'] ?? null) ? $payload['work'] : null;
            if ($description === '' && $work !== null && $workId !== null && $work['key'] === '/works/' . $workId && ($work['description'] ?? '') !== '') {
                $description = (string) $work['description'];
                $origins['description'] = 'work';
            }
            $cover = '';
            foreach ($edition['covers'] ?? [] as $id) {
                if (is_int($id) && $id > 0) {
                    // By cover ID: not rate limited, and ?default=false gives 404 instead of a blank image.
                    $cover = 'https://covers.openlibrary.org/b/id/' . $id . '-L.jpg?default=false';
                    break;
                }
            }
            $metadata = BookMetadata::clean(
                $edition['title'] ?? '',
                $edition['subtitle'] ?? '',
                $authors,
                $edition['publishers'][0] ?? '',
                $edition['publish_date'] ?? '',
                $edition['number_of_pages'] ?? null,
                self::languageOf($edition['languages'][0] ?? ''),
                $description,
                $cover,
            );
            foreach (['title', 'subtitle', 'publisher', 'publication_year', 'page_count', 'language', 'cover'] as $field) {
                $origins[$field] = 'edition';
            }
            $origins['description'] ??= 'edition';
            $origins['authors'] = 'search';
            foreach ($claimants as $claimant) {
                if ($claimant['edition'] !== $editionId && $claimant['work'] !== $workId) {
                    $candidates[] = $claimant;
                }
            }
            $signals['created'] = $edition['created'] ?? null;
            $signals['sources'] = $edition['source_records'] ?? null;
        } else {
            $works = array_values(array_unique(array_column($claimants, 'work')));
            if (count($works) === 1) {
                // No edition record: the one work that lists the ISBN in search.
                $c = $claimants[0];
                $metadata = BookMetadata::clean($c['title'], $c['subtitle'], $c['authors'], $c['publisher'], $c['publish_date'], null, self::languageOf($c['language']), '', '');
                foreach (['title', 'subtitle', 'authors', 'publisher', 'publication_year', 'language'] as $field) {
                    $origins[$field] = 'search';
                }
                $identifiers = array_filter(['ol_edition' => $c['edition'], 'ol_work' => $c['work']]);
            } else {
                // Several unrelated works claim the ISBN: none is chosen.
                $metadata = new BookMetadata();
                $candidates = $claimants;
            }
        }
        $signals['shared'] = $candidates !== [];
        $candidates = array_map(static fn (array $c): array => ['edition' => $c['edition'], 'work' => $c['work'], 'title' => $c['title'], 'authors' => $c['authors']], $candidates);

        return new ProviderRecord(
            $this->name(),
            $metadata,
            $metadata->isEmpty() ? [] : $origins,
            $identifiers,
            MetadataChecks::inspect($metadata, $isbn, $signals),
            $candidates,
            is_array($payload['incomplete'] ?? null) ? array_values(array_map('strval', $payload['incomplete'])) : [],
        );
    }

    private function pace(bool $optional): bool
    {
        return $this->pacer === null || $this->pacer->acquire($this->name(), $optional);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function markIncomplete(array $payload, string $endpoint, HttpException $e): array
    {
        $payload['incomplete'][] = $endpoint . ':' . ($e->status !== null ? (string) $e->status : $e->kind);
        if ($e->status !== null && in_array($e->status, [429, 500, 502, 503, 504], true)) {
            // MetadataService records the shared Open Library backoff, then drops this key.
            $payload['_retry_after'] = (string) ($e->retryAfter ?? '');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(HttpResponse $response): array
    {
        $data = json_decode($response->body, true, 32);
        if (!is_array($data)) {
            throw HttpException::invalid('Open Library', 'invalid JSON');
        }

        return $data;
    }

    /**
     * The edition fields this application uses, bounded.
     *
     * @param array<string, mixed> $e
     *
     * @return array<string, mixed>
     */
    private static function trimEdition(array $e): array
    {
        $key = is_string($e['key'] ?? null) ? $e['key'] : '';
        if (preg_match('#^/books/OL\d{1,12}M$#', $key) !== 1) {
            throw HttpException::invalid('Open Library', 'an edition without a valid key');
        }
        $description = $e['description'] ?? '';
        if (is_array($description)) {
            $description = $description['value'] ?? '';
        }

        return [
            'key' => $key,
            'title' => self::str($e['title'] ?? '', 500),
            'subtitle' => self::str($e['subtitle'] ?? '', 500),
            'publishers' => self::strList($e['publishers'] ?? [], 5, 255),
            'publish_date' => self::str($e['publish_date'] ?? '', 64),
            'number_of_pages' => is_int($e['number_of_pages'] ?? null) ? $e['number_of_pages'] : null,
            'isbn_13' => self::strList($e['isbn_13'] ?? [], 20, 32),
            'isbn_10' => self::strList($e['isbn_10'] ?? [], 20, 32),
            'works' => self::keyList($e['works'] ?? [], '#^/works/OL\d{1,12}W$#', 3),
            'languages' => self::keyList($e['languages'] ?? [], '#^/languages/[a-z]{2,3}$#', 5),
            'covers' => array_values(array_filter(array_slice(is_array($e['covers'] ?? null) ? $e['covers'] : [], 0, 5), 'is_int')),
            'description' => self::str($description, 10000),
            'created' => is_array($e['created'] ?? null) ? self::str($e['created']['value'] ?? '', 40) : null,
            'source_records' => is_array($e['source_records'] ?? null) ? count($e['source_records']) : 0,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private static function trimSearch(array $data): array
    {
        if (!is_array($data['docs'] ?? null)) {
            throw HttpException::invalid('Open Library', 'a search response without docs');
        }
        $docs = [];
        foreach (array_slice($data['docs'], 0, 5) as $doc) {
            if (!is_array($doc) || !is_string($doc['key'] ?? null) || preg_match('#^/works/OL\d{1,12}W$#', $doc['key']) !== 1) {
                continue;
            }
            $editions = [];
            foreach (array_slice(is_array($doc['editions']['docs'] ?? null) ? $doc['editions']['docs'] : [], 0, 5) as $ed) {
                if (!is_array($ed) || !is_string($ed['key'] ?? null) || preg_match('#^/books/OL\d{1,12}M$#', $ed['key']) !== 1) {
                    continue;
                }
                $editions[] = [
                    'key' => $ed['key'],
                    'title' => self::str($ed['title'] ?? '', 500),
                    'subtitle' => self::str($ed['subtitle'] ?? '', 500),
                    'isbn' => self::strList($ed['isbn'] ?? [], 20, 32),
                    'publisher' => self::strList($ed['publisher'] ?? [], 3, 255),
                    'publish_date' => self::strList($ed['publish_date'] ?? [], 3, 64),
                    'language' => self::strList($ed['language'] ?? [], 3, 8),
                ];
            }
            $docs[] = [
                'key' => $doc['key'],
                'title' => self::str($doc['title'] ?? '', 500),
                'author_name' => self::strList($doc['author_name'] ?? [], 20, 255),
                'first_publish_year' => is_int($doc['first_publish_year'] ?? null) ? $doc['first_publish_year'] : null,
                'editions' => $editions,
            ];
        }

        return $docs;
    }

    /**
     * @param array<string, mixed> $w
     *
     * @return array<string, mixed>
     */
    private static function trimWork(array $w): array
    {
        $key = is_string($w['key'] ?? null) ? $w['key'] : '';
        if (preg_match('#^/works/OL\d{1,12}W$#', $key) !== 1) {
            throw HttpException::invalid('Open Library', 'a work without a valid key');
        }
        $description = $w['description'] ?? '';
        if (is_array($description)) {
            $description = $description['value'] ?? '';
        }

        return [
            'key' => $key,
            'title' => self::str($w['title'] ?? '', 500),
            'description' => self::str($description, 10000),
            'subjects' => self::strList($w['subjects'] ?? [], 10, 255),
        ];
    }

    /**
     * Every edition in the search results that lists the ISBN, with its work.
     *
     * @param list<array<string, mixed>> $docs
     *
     * @return list<array{edition: string, work: string, title: string, subtitle: string, authors: list<string>, publisher: string, publish_date: string, language: string}>
     */
    private static function claimants(array $docs, Isbn $isbn): array
    {
        $out = [];
        foreach ($docs as $doc) {
            foreach ($doc['editions'] ?? [] as $ed) {
                if (!self::anyIsbn($ed['isbn'] ?? [], $isbn)) {
                    continue;
                }
                $out[] = [
                    'edition' => substr((string) $ed['key'], 7),
                    'work' => substr((string) $doc['key'], 7),
                    'title' => (string) (($ed['title'] ?? '') !== '' ? $ed['title'] : $doc['title']),
                    'subtitle' => (string) ($ed['subtitle'] ?? ''),
                    'authors' => array_values($doc['author_name'] ?? []),
                    'publisher' => (string) ($ed['publisher'][0] ?? ''),
                    'publish_date' => (string) ($ed['publish_date'][0] ?? ''),
                    'language' => (string) ($ed['language'][0] ?? ''),
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $edition
     */
    private static function listsIsbn(array $edition, Isbn $isbn): bool
    {
        return self::anyIsbn(array_merge($edition['isbn_13'] ?? [], $edition['isbn_10'] ?? []), $isbn);
    }

    /**
     * @param list<mixed> $values
     */
    private static function anyIsbn(array $values, Isbn $isbn): bool
    {
        foreach ($values as $value) {
            if (is_string($value) && Isbn::tryParse($value)?->isbn13 === $isbn->isbn13) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $edition
     */
    private static function workId(?array $edition): ?string
    {
        $key = $edition['works'][0] ?? null;

        return is_string($key) && preg_match('#^/works/(OL\d{1,12}W)$#', $key, $m) === 1 ? $m[1] : null;
    }

    private static function languageOf(string $value): string
    {
        return BookMetadata::languageCode((string) preg_replace('#^/languages/#', '', $value));
    }

    private static function str(mixed $value, int $max): string
    {
        return is_string($value) ? mb_substr($value, 0, $max) : '';
    }

    /**
     * @return list<string>
     */
    private static function strList(mixed $values, int $count, int $max): array
    {
        if (!is_array($values)) {
            return [];
        }
        $out = [];
        foreach (array_slice($values, 0, $count) as $value) {
            if (is_string($value) && $value !== '') {
                $out[] = mb_substr($value, 0, $max);
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function keyList(mixed $values, string $pattern, int $count): array
    {
        $out = [];
        foreach (array_slice(is_array($values) ? $values : [], 0, $count) as $value) {
            $key = is_array($value) ? ($value['key'] ?? null) : null;
            if (is_string($key) && preg_match($pattern, $key) === 1) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * Payloads written by the legacy Books API (jscmd=data) before format 2.
     *
     * @param array<string, mixed> $payload
     */
    private function describeLegacy(array $payload, Isbn $isbn): ProviderRecord
    {
        $names = static fn (mixed $list): array => is_array($list)
            ? array_values(array_filter(array_map(static fn ($x): mixed => is_array($x) ? ($x['name'] ?? null) : null, $list)))
            : [];
        $publishers = $names($payload['publishers'] ?? []);
        $cover = is_array($payload['cover'] ?? null) ? ($payload['cover']['large'] ?? $payload['cover']['medium'] ?? '') : '';
        $description = $payload['notes'] ?? '';
        if (is_array($description)) {
            $description = $description['value'] ?? '';
        }
        $metadata = BookMetadata::clean(
            $payload['title'] ?? '',
            $payload['subtitle'] ?? '',
            $names($payload['authors'] ?? []),
            $publishers[0] ?? '',
            $payload['publish_date'] ?? '',
            $payload['number_of_pages'] ?? null,
            '',
            $description,
            $cover,
        );
        $key = is_string($payload['key'] ?? null) && preg_match('#^/books/(OL\d{1,12}M)$#', $payload['key'], $m) === 1 ? $m[1] : null;

        return new ProviderRecord(
            $this->name(),
            $metadata,
            $metadata->isEmpty() ? [] : array_fill_keys(['title', 'subtitle', 'authors', 'publisher', 'publication_year', 'page_count', 'description', 'cover'], 'edition'),
            $key === null ? [] : ['ol_edition' => $key],
            MetadataChecks::inspect($metadata, $isbn),
        );
    }
}
