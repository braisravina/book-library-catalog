<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata;

use DigitalLibrary\Catalogue\Isbn;

/**
 * Prepares an OPTIONAL, human-reviewed contribution to Open Library from a
 * catalogued edition. Nothing is ever sent from the server: the user copies
 * the prepared text and opens Open Library (or their mail client) in their
 * own browser, logs in there and submits it themselves. No Open Library
 * credentials, cookies or tokens are requested, stored or used.
 *
 * Only bibliographic fields from an explicit allowlist are used, and only
 * those the user selected. Never: copies, owners, locations, prices,
 * acquisition data, notes, collections, wishlists, categories, cover files,
 * internal IDs or who created the record.
 *
 * The situation is decided from the cached lookup only (no request):
 *   report   Open Library's record for this ISBN looks unreliable, or other
 *            unrelated records claim the ISBN: report it instead of adding
 *            a duplicate (Open Library merges/fixes are done by librarians);
 *   improve  Open Library has the edition, but fields the catalogue has are
 *            missing there;
 *   add      no Open Library record for this ISBN: check for an existing
 *            work first (a new edition), otherwise add a new book;
 *   check    no saved lookup: the user checks Open Library first;
 *   none     Open Library already has a complete, unremarkable record.
 */
final class OpenLibraryContribution
{
    public const BASE = 'https://openlibrary.org';
    public const REPORT_EMAIL = 'openlibrary@archive.org';
    public const LIBRARIANS_TRACKER = 'https://github.com/internetarchive/openlibrary-librarians/issues';

    /** field => [label in the prepared text, included by default] */
    public const FIELDS = [
        'isbn_13' => ['ISBN-13', true],
        'isbn_10' => ['ISBN-10', true],
        'title' => ['Title', true],
        'subtitle' => ['Subtitle', true],
        'authors' => ['Authors', true],
        'contributors' => ['Contributors', true],
        'publisher' => ['Publisher', true],
        'publication_year' => ['Publish date', true],
        'language' => ['Language', true],
        'page_count' => ['Number of pages', true],
        'edition_statement' => ['Edition', true],
        'format' => ['Physical format', true],
        'legal_deposit' => ['Legal deposit', false],
        'description' => ['Description', false],
    ];

    /** Catalogue languages (Vocabulary) in the English names Open Library shows. */
    private const LANGUAGE_NAMES = [
        'en' => 'English', 'es' => 'Spanish', 'gl' => 'Galician', 'pt' => 'Portuguese', 'ca' => 'Catalan',
        'eu' => 'Basque', 'fr' => 'French', 'de' => 'German', 'it' => 'Italian', 'la' => 'Latin',
        'nl' => 'Dutch', 'ru' => 'Russian', 'ja' => 'Japanese', 'zh' => 'Chinese', 'ar' => 'Arabic',
    ];

    /**
     * @param array<string, string>                                     $values     field => value (allowlisted, non-empty)
     * @param array<string, list<string>>                               $support    field => providers that give the same value
     * @param array{edition?: string, work?: string}                    $identifiers Open Library IDs, when known
     * @param list<string>                                              $missingThere fields Open Library's edition lacks
     * @param list<array{edition: string, work: string, title: string, authors: list<string>}> $problems records to report
     */
    private function __construct(
        public readonly string $situation,
        public readonly Isbn $isbn,
        public readonly array $values,
        public readonly array $support,
        public readonly array $identifiers,
        public readonly array $missingThere,
        public readonly array $problems,
    ) {
    }

    /**
     * @param array<string, mixed>                    $edition      EditionRepository::find() row
     * @param list<array{name: string, role: string}> $authors      the work's authors
     * @param list<array{name: string, role: string}> $contributors the edition's contributors
     */
    public static function plan(array $edition, array $authors, array $contributors, ?LookupResult $cached): ?self
    {
        $isbn = Isbn::tryParse((string) ($edition['isbn_13'] ?? ''));
        if ($isbn === null) {
            return null; // only ISBN editions: duplicates cannot be checked otherwise
        }
        $values = self::values($edition, $authors, $contributors, $isbn);
        $ol = $cached?->records['open_library'] ?? null;
        $olStatus = null;
        foreach ($cached?->outcomes ?? [] as $outcome) {
            if ($outcome['provider'] === 'open_library') {
                $olStatus = $outcome['status'];
            }
        }
        $support = [];
        foreach ($cached?->records ?? [] as $provider => $record) {
            if ($record->questionable() || $record->metadata->isEmpty()) {
                continue;
            }
            // Providers only describe a record that lists the ISBN looked up,
            // so a usable record confirms the ISBN (and its ISBN-10 form).
            $support['isbn_13'][] = (string) $provider;
            if ($isbn->isbn10 !== null && strtoupper($values['isbn_10'] ?? '') === $isbn->isbn10) {
                $support['isbn_10'][] = (string) $provider;
            }
            foreach (self::comparableValues($record->metadata) as $field => $value) {
                if (isset($values[$field]) && self::same($field, $values[$field], $value)) {
                    $support[$field][] = (string) $provider;
                }
            }
        }

        $identifiers = [];
        $missingThere = [];
        $problems = [];
        if ($ol !== null) {
            if (isset($ol->identifiers['ol_edition']) && !$ol->questionable()) {
                $identifiers['edition'] = $ol->identifiers['ol_edition'];
            }
            if (isset($ol->identifiers['ol_work']) && !$ol->questionable()) {
                $identifiers['work'] = $ol->identifiers['ol_work'];
            }
            if ($ol->questionable() && isset($ol->identifiers['ol_edition'])) {
                $problems[] = ['edition' => $ol->identifiers['ol_edition'], 'work' => $ol->identifiers['ol_work'] ?? '', 'title' => $ol->metadata->title, 'authors' => $ol->metadata->authors];
            }
            foreach ($ol->candidates as $candidate) {
                $problems[] = $candidate;
            }
            if (!$ol->questionable() && !$ol->metadata->isEmpty()) {
                $theirs = self::comparableValues($ol->metadata);
                foreach (['authors', 'publisher', 'publication_year', 'page_count', 'language', 'subtitle'] as $field) {
                    if (isset($values[$field]) && ($theirs[$field] ?? '') === '') {
                        $missingThere[] = $field;
                    }
                }
            }
        }

        $situation = match (true) {
            $problems !== [] => 'report',
            $ol !== null && !$ol->metadata->isEmpty() && !$ol->questionable() => $missingThere !== [] ? 'improve' : 'none',
            $olStatus === 'not_found' => 'add',
            default => 'check',
        };

        return new self($situation, $isbn, $values, $support, $identifiers, $missingThere, $problems);
    }

    public function relevant(): bool
    {
        return $this->situation !== 'none';
    }

    /**
     * The fields to prepare: the user's selection, else the defaults.
     *
     * @param list<string>|null $selected
     *
     * @return list<string>
     */
    public function selection(?array $selected): array
    {
        $fields = $selected ?? array_keys(array_filter(self::FIELDS, static fn (array $f): bool => $f[1]));

        return array_values(array_filter(array_keys(self::FIELDS), fn (string $f): bool => in_array($f, $fields, true) && isset($this->values[$f])));
    }

    /**
     * Plain text for the user to copy (English labels, as on Open Library).
     *
     * @param list<string> $fields from selection()
     */
    public function text(array $fields): string
    {
        $lines = [];
        if ($this->situation === 'report') {
            $lines[] = 'Possible incorrect record for ISBN ' . $this->isbn->isbn13;
            foreach ($this->problems as $p) {
                $lines[] = '- ' . self::BASE . '/books/' . $p['edition'] . ' "' . $p['title'] . '"' . ($p['authors'] !== [] ? ' by ' . implode(', ', $p['authors']) : ' (no author)');
            }
            $lines[] = '';
            $lines[] = 'The ISBN belongs to this edition, as catalogued from the printed book:';
        }
        foreach ($fields as $field) {
            $value = $this->values[$field];
            if ($field === 'description') {
                $lines[] = self::FIELDS[$field][0] . ':';
                $lines[] = $value;
                continue;
            }
            $lines[] = self::FIELDS[$field][0] . ': ' . $value;
        }
        if (isset($this->identifiers['edition'])) {
            $lines[] = 'Open Library edition: ' . self::BASE . '/books/' . $this->identifiers['edition'];
        }
        if (isset($this->identifiers['work'])) {
            $lines[] = 'Open Library work: ' . self::BASE . '/works/' . $this->identifiers['work'];
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Links the user opens in their own browser (logging in there).
     *
     * @return array<string, string> purpose => URL
     */
    public function links(string $text): array
    {
        $links = ['isbn' => self::BASE . '/isbn/' . $this->isbn->isbn13];
        $query = trim(($this->values['title'] ?? '') . ' ' . ($this->values['authors'] ?? ''));
        if ($query !== '') {
            $links['search'] = self::BASE . '/search?' . http_build_query(['q' => mb_substr($query, 0, 200), 'mode' => 'everything'], '', '&', PHP_QUERY_RFC3986);
        }
        if (isset($this->identifiers['edition'])) {
            $links['edition'] = self::BASE . '/books/' . $this->identifiers['edition'];
        }
        if (isset($this->identifiers['work'])) {
            $links['work'] = self::BASE . '/works/' . $this->identifiers['work'];
        }
        if ($this->situation === 'add' || $this->situation === 'check') {
            $links['add'] = self::BASE . '/books/add';
        }
        if ($this->situation === 'report') {
            $links['email'] = 'mailto:' . self::REPORT_EMAIL . '?' . http_build_query([
                'subject' => 'Incorrect record for ISBN ' . $this->isbn->isbn13,
                'body' => mb_substr($text, 0, 1500),
            ], '', '&', PHP_QUERY_RFC3986);
            $links['librarians'] = self::LIBRARIANS_TRACKER;
        }

        return $links;
    }

    /**
     * @param array<string, mixed>                    $edition
     * @param list<array{name: string, role: string}> $authors
     * @param list<array{name: string, role: string}> $contributors
     *
     * @return array<string, string>
     */
    private static function values(array $edition, array $authors, array $contributors, Isbn $isbn): array
    {
        $names = static fn (array $people, string $default): string => implode(', ', array_map(
            static fn (array $p): string => (string) $p['name'] . ($p['role'] !== $default ? ' (' . $p['role'] . ')' : ''),
            $people,
        ));
        $str = static fn (mixed $v): string => trim((string) $v);
        $values = [
            'isbn_13' => $isbn->isbn13,
            'isbn_10' => $str($edition['isbn_10'] ?? ''),
            'title' => $str($edition['title'] ?? ''),
            'subtitle' => $str($edition['subtitle'] ?? ''),
            'authors' => $names($authors, 'author'),
            'contributors' => $names($contributors, ''),
            'publisher' => $str($edition['publisher_name'] ?? ''),
            'publication_year' => $str($edition['publication_year'] ?? ''),
            'language' => self::LANGUAGE_NAMES[(string) ($edition['language'] ?? '')] ?? '',
            'page_count' => $str($edition['page_count'] ?? ''),
            'edition_statement' => $str($edition['edition_statement'] ?? ''),
            'format' => $str($edition['format'] ?? ''),
            'legal_deposit' => $str($edition['legal_deposit'] ?? ''),
            'description' => $str(($edition['description'] ?? '') !== '' && $edition['description'] !== null ? $edition['description'] : ($edition['work_description'] ?? '')),
        ];

        return array_filter($values, static fn (string $v): bool => $v !== '');
    }

    /**
     * Provider values in the same shape as values(), for support and gaps.
     *
     * @return array<string, string>
     */
    private static function comparableValues(BookMetadata $m): array
    {
        return [
            'title' => $m->title,
            'subtitle' => $m->subtitle,
            'authors' => implode(', ', $m->authors),
            'publisher' => $m->publisher,
            'publication_year' => $m->publicationYear === null ? '' : (string) $m->publicationYear,
            'page_count' => $m->pageCount === null ? '' : (string) $m->pageCount,
            'language' => self::LANGUAGE_NAMES[$m->language] ?? '',
            'description' => $m->description,
        ];
    }

    private static function same(string $field, string $a, string $b): bool
    {
        $norm = static function (string $v) use ($field): string {
            $v = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $v)));
            if ($field === 'authors') {
                $v = (string) preg_replace('/\s*\([^)]*\)/u', '', $v);
                $parts = array_map('trim', explode(',', $v));
                sort($parts);
                $v = implode(',', $parts);
            }

            return rtrim($v, ' .,:;/');
        };

        return $b !== '' && $norm($a) === $norm($b);
    }
}
