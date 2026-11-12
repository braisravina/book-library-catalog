<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata\Provider;

use DigitalLibrary\Catalogue\Isbn;
use DigitalLibrary\Metadata\BookMetadata;
use DigitalLibrary\Metadata\Http\HttpClient;
use DigitalLibrary\Metadata\Http\HttpException;

/**
 * Open Library Books API (https://openlibrary.org/dev/docs/api/books),
 * one request per lookup with jscmd=data.
 */
final class OpenLibraryProvider implements MetadataProvider
{
    private const MAX_BYTES = 1_048_576;

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function name(): string
    {
        return 'open_library';
    }

    public static function url(Isbn $isbn): string
    {
        return 'https://openlibrary.org/api/books?bibkeys=ISBN:' . $isbn->isbn13 . '&format=json&jscmd=data';
    }

    public function fetch(Isbn $isbn): ?array
    {
        $response = $this->http->get(self::url($isbn), self::MAX_BYTES);
        if ($response->status !== 200) {
            throw new HttpException('Open Library returned HTTP ' . $response->status);
        }
        $data = json_decode($response->body, true);
        if (!is_array($data)) {
            throw new HttpException('Open Library returned invalid JSON');
        }
        $record = $data['ISBN:' . $isbn->isbn13] ?? null;

        return is_array($record) && isset($record['title']) ? $record : null;
    }

    public function normalize(array $payload, Isbn $isbn): BookMetadata
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

        return BookMetadata::clean(
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
    }
}
