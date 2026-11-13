<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata\Provider;

use DigitalLibrary\Catalogue\Isbn;
use DigitalLibrary\Metadata\BookMetadata;
use DigitalLibrary\Metadata\Http\HttpClient;
use DigitalLibrary\Metadata\Http\HttpException;
use DigitalLibrary\Metadata\MetadataChecks;
use DigitalLibrary\Metadata\ProviderRecord;

/**
 * Google Books API v1 (volumes search by ISBN). Results are only accepted
 * when the volume actually lists the requested ISBN, because the search can
 * return loosely related volumes.
 */
final class GoogleBooksProvider implements MetadataProvider
{
    private const MAX_BYTES = 1_048_576;

    public function __construct(
        private readonly HttpClient $http,
        private readonly ?string $apiKey = null,
    ) {
    }

    public function name(): string
    {
        return 'google_books';
    }

    public function url(Isbn $isbn): string
    {
        $url = 'https://www.googleapis.com/books/v1/volumes?q=isbn:' . $isbn->isbn13 . '&maxResults=5&projection=full';

        return $this->apiKey !== null && $this->apiKey !== '' ? $url . '&key=' . rawurlencode($this->apiKey) : $url;
    }

    public function fetch(Isbn $isbn): ?array
    {
        $response = $this->http->get($this->url($isbn), self::MAX_BYTES);
        if ($response->status !== 200) {
            // A daily quota (no Retry-After header) will not reset in seconds:
            // ask for the longest bounded pause instead of the 30 s default.
            $daily = $response->status === 429 && preg_match('/per\s+day/i', substr($response->body, 0, 4096)) === 1;
            throw HttpException::forStatus('Google Books', $response, $daily && $response->header('retry-after') === null ? '86400' : null);
        }
        $data = json_decode($response->body, true, 64);
        if (!is_array($data) || (isset($data['items']) && !is_array($data['items']))) {
            throw HttpException::invalid('Google Books', 'invalid JSON');
        }
        foreach ((array) ($data['items'] ?? []) as $item) {
            $info = is_array($item) ? ($item['volumeInfo'] ?? null) : null;
            if (is_array($info) && self::listsIsbn($info, $isbn)) {
                return $info;
            }
        }

        return null;
    }

    public function isCurrent(array $payload): bool
    {
        return true; // the cached payload is the volume's volumeInfo, unchanged
    }

    public function describe(array $payload, Isbn $isbn): ProviderRecord
    {
        $metadata = $this->normalize($payload, $isbn);

        return new ProviderRecord(
            $this->name(),
            $metadata,
            $metadata->isEmpty() ? [] : array_fill_keys(['title', 'subtitle', 'authors', 'publisher', 'publication_year', 'page_count', 'language', 'description', 'cover'], 'volume'),
            [],
            MetadataChecks::inspect($metadata, $isbn),
        );
    }

    public function normalize(array $payload, Isbn $isbn): BookMetadata
    {
        $images = is_array($payload['imageLinks'] ?? null) ? $payload['imageLinks'] : [];
        $cover = (string) ($images['thumbnail'] ?? $images['smallThumbnail'] ?? '');
        // Google serves http:// image links; the same resource is available over https.
        $cover = (string) preg_replace('#^http://#i', 'https://', $cover);

        return BookMetadata::clean(
            $payload['title'] ?? '',
            $payload['subtitle'] ?? '',
            is_array($payload['authors'] ?? null) ? array_values($payload['authors']) : [],
            $payload['publisher'] ?? '',
            $payload['publishedDate'] ?? '',
            $payload['pageCount'] ?? null,
            $payload['language'] ?? '',
            $payload['description'] ?? '',
            $cover,
        );
    }

    /**
     * @param array<string, mixed> $info
     */
    private static function listsIsbn(array $info, Isbn $isbn): bool
    {
        foreach ((array) ($info['industryIdentifiers'] ?? []) as $id) {
            $value = is_array($id) ? Isbn::tryParse((string) ($id['identifier'] ?? '')) : null;
            if ($value !== null && $value->isbn13 === $isbn->isbn13) {
                return true;
            }
        }

        return false;
    }
}
