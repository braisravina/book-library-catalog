<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata;

/**
 * What the lookup found, field by field, for the user's review: which
 * provider supplied the pre-filled value, which fields no provider supplied,
 * and the differing values other providers returned. It only reports what
 * the providers said; it never invents, combines or chooses values beyond
 * the existing priority order (BookMetadata::mergedWith).
 */
final class MetadataReview
{
    /**
     * Reviewed fields => [label key, id of the form control to jump to (or null)].
     */
    public const FIELDS = [
        'title' => ['fields.title', 'title'],
        'subtitle' => ['fields.subtitle', 'subtitle'],
        'authors' => ['fields.authors', 'author-name-0'],
        'publisher' => ['fields.publisher', 'publisher'],
        'publication_year' => ['fields.publication_year', 'publication_year'],
        'page_count' => ['fields.page_count', 'page_count'],
        'language' => ['fields.language', 'language'],
        'description' => ['fields.work_description', 'work_description'],
        'cover' => ['fields.cover', null],
    ];

    /** Fields whose alternative value a single form control can take ("use this value"). */
    public const FILLABLE = ['title', 'subtitle', 'publisher', 'publication_year', 'page_count', 'language', 'description'];

    /**
     * @param list<array{field: string, value: string, source: string, alternatives: list<array{provider: string, value: string}>}> $found
     * @param list<string> $missing field names no provider supplied
     */
    private function __construct(
        public readonly array $found,
        public readonly array $missing,
    ) {
    }

    /**
     * @param array<string, BookMetadata> $byProvider in priority order
     */
    public static function fromProviders(array $byProvider): self
    {
        $found = [];
        $missing = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $values = [];
            foreach ($byProvider as $provider => $metadata) {
                $value = self::value($metadata, $field);
                if ($value !== '') {
                    $values[] = ['provider' => (string) $provider, 'value' => $value];
                }
            }
            if ($values === []) {
                $missing[] = $field;
                continue;
            }
            $chosen = array_shift($values);
            $alternatives = [];
            // The cover is only reported as found: image URLs are not shown.
            $seen = $field === 'cover' ? null : [self::comparable($field, $chosen['value'])];
            foreach ($seen === null ? [] : $values as $candidate) {
                $key = self::comparable($field, $candidate['value']);
                if (!in_array($key, $seen, true)) {
                    $seen[] = $key;
                    $alternatives[] = $candidate;
                }
            }
            $found[] = ['field' => $field, 'value' => $field === 'cover' ? '' : $chosen['value'], 'source' => $chosen['provider'], 'alternatives' => $alternatives];
        }

        return new self($found, $missing);
    }

    public function hasConflicts(): bool
    {
        foreach ($this->found as $entry) {
            if ($entry['alternatives'] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Display value of a field ('' when the provider did not supply it).
     * Language stays a vocabulary code (the template translates it).
     */
    private static function value(BookMetadata $m, string $field): string
    {
        return match ($field) {
            'title' => $m->title,
            'subtitle' => $m->subtitle,
            'authors' => implode('; ', $m->authors),
            'publisher' => $m->publisher,
            'publication_year' => $m->publicationYear === null ? '' : (string) $m->publicationYear,
            'page_count' => $m->pageCount === null ? '' : (string) $m->pageCount,
            'language' => $m->language,
            'description' => $m->description,
            'cover' => $m->coverUrl,
            default => throw new \InvalidArgumentException($field),
        };
    }

    /**
     * Differences in case, spacing, trailing punctuation or author order are
     * not conflicts.
     */
    private static function comparable(string $field, string $value): string
    {
        $parts = $field === 'authors' ? explode(';', $value) : [$value];
        $parts = array_map(static function (string $part): string {
            $part = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $part)));

            return rtrim($part, " .,:;/");
        }, $parts);
        sort($parts);

        return implode(';', $parts);
    }
}
