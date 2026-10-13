<?php

declare(strict_types=1);

namespace DigitalLibrary\Export;

/**
 * Administrator catalogue export (permission catalogue.export).
 *
 * Included: works, editions, authors and credits, publishers, categories,
 * locations, physical copies with owner references (user id and display
 * name), SHARED collections and their items. Identifiers are the database
 * ids so a future import can rebuild the relations.
 *
 * Excluded by design: user e-mail addresses and accounts, password hashes,
 * sessions, invitations and their tokens, login attempts, settings, the
 * audit log, the metadata cache, cover image files (covered by backups),
 * wishlists, loans and PRIVATE collections (personal data that only its
 * owner exports).
 */
final class CatalogueExport extends ExportSource
{
    public const DATASETS = ['works', 'editions', 'authors', 'work_authors', 'edition_contributors', 'publishers',
        'categories', 'locations', 'copies', 'collections', 'collection_items'];

    /**
     * @return array<string, Dataset>
     */
    public function datasets(): array
    {
        $all = [
            new Dataset('works', ['id', 'title', 'subtitle', 'original_title', 'original_language', 'first_published_year', 'description',
                'authors', 'author_ids', 'categories', 'category_ids', 'created_by', 'created_at', 'updated_at'], fn () => $this->works()),
            new Dataset('editions', ['id', 'work_id', 'title', 'subtitle', 'publisher_id', 'publisher', 'publication_year', 'edition_statement',
                'language', 'format', 'page_count', 'isbn_13', 'isbn_10', 'legal_deposit', 'description', 'contributors', 'created_by',
                'created_at', 'updated_at'], fn () => $this->editions()),
            new Dataset('authors', ['id', 'name', 'sort_name', 'birth_year', 'death_year', 'notes', 'created_at', 'updated_at'], fn () => $this->authors()),
            new Dataset('work_authors', ['work_id', 'author_id', 'author', 'role', 'position'], fn () => $this->creditRows('work_authors', 'work_id')),
            new Dataset('edition_contributors', ['edition_id', 'author_id', 'author', 'role', 'position'], fn () => $this->creditRows('edition_contributors', 'edition_id')),
            new Dataset('publishers', ['id', 'name', 'created_at', 'updated_at'], fn () => $this->simple('publishers')),
            new Dataset('categories', ['id', 'parent_id', 'name', 'slug', 'path', 'description'], fn () => $this->tree('categories')),
            new Dataset('locations', ['id', 'parent_id', 'name', 'kind', 'path', 'description'], fn () => $this->tree('locations')),
            new Dataset('copies', self::COPY_COLUMNS, fn () => $this->copyRows('1 = 1', [], $this->treePaths('locations'))),
            new Dataset('collections', self::COLLECTION_COLUMNS, fn () => $this->collectionRows("c.visibility = 'shared'", [])),
            new Dataset('collection_items', self::COLLECTION_ITEM_COLUMNS, fn () => $this->collectionItemRows("c.visibility = 'shared'", [])),
        ];
        $out = [];
        foreach ($all as $dataset) {
            $out[$dataset->name] = $dataset;
        }

        return $out;
    }

    /**
     * @return \Generator<array<string, mixed>>
     */
    private function works(): \Generator
    {
        $categories = $this->treePaths('categories');
        yield from $this->keyset(
            'SELECT w.id, w.title, w.subtitle, w.original_title, w.original_language, w.first_published_year, w.description,
                    w.created_by, w.created_at, w.updated_at FROM works w',
            '1 = 1',
            [],
            [['w.id', 'id', 0]],
            function (array $rows) use ($categories): array {
                $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
                $credits = $this->credits('work_authors', 'work_id', $ids);
                $workCategories = $this->workCategories($ids);
                $out = [];
                foreach ($rows as $row) {
                    $id = (int) $row['id'];
                    $authors = array_values(array_filter($credits[$id] ?? [], static fn (array $c): bool => $c['role'] === 'author'));
                    $categoryIds = $workCategories[$id] ?? [];
                    $out[] = [
                        'id' => $id,
                        'title' => $row['title'],
                        'subtitle' => $row['subtitle'],
                        'original_title' => $row['original_title'],
                        'original_language' => $row['original_language'],
                        'first_published_year' => $row['first_published_year'] === null ? null : (int) $row['first_published_year'],
                        'description' => $row['description'],
                        'authors' => array_column($authors, 'name'),
                        'author_ids' => array_column($authors, 'id'),
                        'categories' => array_values(array_map(static fn (int $c): string => $categories[$c] ?? '', $categoryIds)),
                        'category_ids' => $categoryIds,
                        'created_by' => $row['created_by'] === null ? null : (int) $row['created_by'],
                        'created_at' => self::dateTime($row['created_at']),
                        'updated_at' => self::dateTime($row['updated_at']),
                    ];
                }

                return $out;
            },
        );
    }

    /**
     * @return \Generator<array<string, mixed>>
     */
    private function editions(): \Generator
    {
        yield from $this->keyset(
            'SELECT e.id, e.work_id, e.title, e.subtitle, e.publisher_id, p.name AS publisher, e.publication_year, e.edition_statement,
                    e.language, e.format, e.page_count, e.isbn_13, e.isbn_10, e.legal_deposit, e.description, e.created_by, e.created_at, e.updated_at
             FROM editions e LEFT JOIN publishers p ON p.id = e.publisher_id',
            '1 = 1',
            [],
            [['e.id', 'id', 0]],
            function (array $rows): array {
                $credits = $this->credits('edition_contributors', 'edition_id', array_map(static fn (array $r): int => (int) $r['id'], $rows));
                $out = [];
                foreach ($rows as $row) {
                    $id = (int) $row['id'];
                    $out[] = [
                        'id' => $id,
                        'work_id' => (int) $row['work_id'],
                        'title' => $row['title'],
                        'subtitle' => $row['subtitle'],
                        'publisher_id' => $row['publisher_id'] === null ? null : (int) $row['publisher_id'],
                        'publisher' => $row['publisher'],
                        'publication_year' => $row['publication_year'] === null ? null : (int) $row['publication_year'],
                        'edition_statement' => $row['edition_statement'],
                        'language' => $row['language'],
                        'format' => $row['format'],
                        'page_count' => $row['page_count'] === null ? null : (int) $row['page_count'],
                        'isbn_13' => $row['isbn_13'],
                        'isbn_10' => $row['isbn_10'],
                        'legal_deposit' => $row['legal_deposit'],
                        'description' => $row['description'],
                        'contributors' => array_map(static fn (array $c): string => $c['name'] . ' (' . $c['role'] . ')', $credits[$id] ?? []),
                        'created_by' => $row['created_by'] === null ? null : (int) $row['created_by'],
                        'created_at' => self::dateTime($row['created_at']),
                        'updated_at' => self::dateTime($row['updated_at']),
                    ];
                }

                return $out;
            },
        );
    }

    /**
     * @return \Generator<array<string, mixed>>
     */
    private function authors(): \Generator
    {
        foreach ($this->keyset('SELECT a.* FROM authors a', '1 = 1', [], [['a.id', 'id', 0]]) as $row) {
            yield [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'sort_name' => $row['sort_name'],
                'birth_year' => $row['birth_year'] === null ? null : (int) $row['birth_year'],
                'death_year' => $row['death_year'] === null ? null : (int) $row['death_year'],
                'notes' => $row['notes'],
                'created_at' => self::dateTime($row['created_at']),
                'updated_at' => self::dateTime($row['updated_at']),
            ];
        }
    }

    /**
     * @return \Generator<array<string, mixed>>
     */
    private function creditRows(string $table, string $column): \Generator
    {
        foreach ($this->keyset(
            "SELECT x.{$column}, x.author_id, a.name AS author, x.role, x.position FROM {$table} x JOIN authors a ON a.id = x.author_id",
            '1 = 1',
            [],
            [["x.{$column}", $column, 0], ['x.author_id', 'author_id', 0], ['x.role', 'role', '']],
        ) as $row) {
            yield [$column => (int) $row[$column], 'author_id' => (int) $row['author_id'], 'author' => $row['author'], 'role' => $row['role'], 'position' => (int) $row['position']];
        }
    }

    /**
     * @return \Generator<array<string, mixed>>
     */
    private function simple(string $table): \Generator
    {
        foreach ($this->keyset("SELECT t.id, t.name, t.created_at, t.updated_at FROM {$table} t", '1 = 1', [], [['t.id', 'id', 0]]) as $row) {
            yield ['id' => (int) $row['id'], 'name' => $row['name'], 'created_at' => self::dateTime($row['created_at']), 'updated_at' => self::dateTime($row['updated_at'])];
        }
    }

    /**
     * @return \Generator<array<string, mixed>>
     */
    private function tree(string $table): \Generator
    {
        $paths = $this->treePaths($table);
        foreach ($this->keyset("SELECT t.* FROM {$table} t", '1 = 1', [], [['t.id', 'id', 0]]) as $row) {
            yield [
                'id' => (int) $row['id'],
                'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
                'name' => $row['name'],
                'slug' => $row['slug'] ?? null,
                'kind' => $row['kind'] ?? null,
                'path' => $paths[(int) $row['id']] ?? $row['name'],
                'description' => $row['description'],
            ];
        }
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, list<int>>
     */
    private function workCategories(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $names = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $names[] = ':id' . $i;
            $params['id' . $i] = $id;
        }
        $stmt = $this->pdo->prepare('SELECT work_id, category_id FROM work_categories WHERE work_id IN (' . implode(', ', $names) . ') ORDER BY category_id');
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['work_id']][] = (int) $row['category_id'];
        }

        return $out;
    }
}
