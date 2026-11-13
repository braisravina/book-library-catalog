<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata;

/**
 * What the lookup found, field by field, for the user's review. It only
 * reports what the providers said; it never invents, combines or chooses
 * values beyond the existing priority order (BookMetadata::mergedWith).
 *
 * Each field gets one status, with a plain explanation in the interface:
 *   corroborated  two or more INDEPENDENT providers give the same value
 *                 (Open Library's endpoints are one provider: they never
 *                 corroborate each other);
 *   single        one provider supplied it;
 *   conflict      providers disagree (the alternatives are listed);
 *   questionable  only a record set aside as questionable supplied it: it is
 *                 shown but NOT pre-filled;
 *   edited        the form now holds a value the user typed instead;
 *   (missing)     no provider supplied it (listed separately).
 * There is no numeric score.
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

    public const STATUSES = ['corroborated', 'single', 'conflict', 'questionable', 'edited'];

    /**
     * @param list<array{field: string, value: string, source: ?string, origin: ?string, status: string,
     *              alternatives: list<array{provider: string, value: string, origin: ?string, questionable: bool}>}> $found
     * @param list<string> $missing field names no provider supplied
     * @param array<string, list<string>> $warnings provider => MetadataChecks codes
     * @param array<string, list<array{edition: string, work: string, title: string, authors: list<string>}>> $candidates
     *        provider => other records claiming the ISBN
     */
    private function __construct(
        public readonly array $found,
        public readonly array $missing,
        public readonly array $warnings = [],
        public readonly array $candidates = [],
        public readonly array $questionable = [],
    ) {
    }

    /**
     * @param array<string, BookMetadata> $byProvider in priority order (all trusted)
     */
    public static function fromProviders(array $byProvider): self
    {
        $records = [];
        foreach ($byProvider as $provider => $metadata) {
            $records[(string) $provider] = new ProviderRecord((string) $provider, $metadata);
        }

        return self::fromRecords($records);
    }

    /**
     * @param array<string, ProviderRecord> $records in priority order
     * @param array<string, string>         $current the values now in the form (to mark edits), by field
     */
    public static function fromRecords(array $records, array $current = []): self
    {
        $found = [];
        $missing = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $trusted = [];
            $doubtful = [];
            foreach ($records as $provider => $record) {
                $value = self::value($record->metadata, $field);
                if ($value === '') {
                    continue;
                }
                $item = ['provider' => (string) $provider, 'value' => $value, 'origin' => $record->fieldOrigins[$field] ?? null, 'questionable' => $record->questionable()];
                if ($record->questionable()) {
                    $doubtful[] = $item;
                } else {
                    $trusted[] = $item;
                }
            }
            if ($trusted === [] && $doubtful === []) {
                if (($current[$field] ?? '') !== '') {
                    $found[] = ['field' => $field, 'value' => $current[$field], 'source' => null, 'origin' => null, 'status' => 'edited', 'alternatives' => []];
                } else {
                    $missing[] = $field;
                }
                continue;
            }
            if ($field === 'cover') {
                $chosen = $trusted[0] ?? null;
                $found[] = ['field' => $field, 'value' => '', 'source' => $chosen['provider'] ?? $doubtful[0]['provider'], 'origin' => $chosen['origin'] ?? null, 'status' => $chosen === null ? 'questionable' : 'single', 'alternatives' => []];
                continue;
            }
            $chosen = $trusted[0] ?? null;
            $alternatives = [];
            $seen = $chosen === null ? [] : [self::comparable($field, $chosen['value'])];
            $agreeing = $chosen === null ? [] : [$chosen['provider']];
            foreach (array_merge(array_slice($trusted, 1), $doubtful) as $candidate) {
                $key = self::comparable($field, $candidate['value']);
                if ($chosen !== null && !$candidate['questionable'] && $key === $seen[0]) {
                    $agreeing[] = $candidate['provider'];
                    continue;
                }
                if (!in_array($key, $seen, true)) {
                    $seen[] = $key;
                    $alternatives[] = $candidate;
                }
            }
            $status = match (true) {
                $chosen === null => 'questionable',
                array_filter($alternatives, static fn (array $a): bool => !$a['questionable']) !== [] => 'conflict',
                count(array_unique($agreeing)) >= 2 => 'corroborated',
                default => 'single',
            };
            $value = $chosen['value'] ?? '';
            if (isset($current[$field]) && $current[$field] !== '' && self::comparable($field, $current[$field]) !== self::comparable($field, $value)) {
                $status = 'edited';
            }
            $found[] = [
                'field' => $field,
                'value' => $value,
                'source' => $chosen['provider'] ?? null,
                'origin' => $chosen['origin'] ?? null,
                'status' => $status,
                'alternatives' => $alternatives,
            ];
        }

        $warnings = [];
        $candidates = [];
        $questionable = [];
        foreach ($records as $provider => $record) {
            if ($record->warnings !== []) {
                $warnings[(string) $provider] = $record->warnings;
            }
            if ($record->candidates !== []) {
                $candidates[(string) $provider] = $record->candidates;
            }
            if ($record->questionable()) {
                $questionable[] = (string) $provider;
            }
        }

        return new self($found, $missing, $warnings, $candidates, $questionable);
    }

    public function hasConflicts(): bool
    {
        foreach ($this->found as $entry) {
            if ($entry['status'] === 'conflict') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> statuses present (for the legend)
     */
    public function statuses(): array
    {
        return array_values(array_intersect(self::STATUSES, array_column($this->found, 'status')));
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
