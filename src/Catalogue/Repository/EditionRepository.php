<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue\Repository;

final class EditionRepository extends Repository
{
    public const FIELDS = ['title', 'subtitle', 'publisher_id', 'publication_year', 'edition_statement', 'language',
        'page_count', 'format', 'isbn_13', 'isbn_10', 'legal_deposit', 'description'];

    public const CONTRIBUTOR_ROLES = ['translator', 'illustrator', 'editor', 'introduction', 'other'];

    /**
     * The edition joined with its work and publisher.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->one(
            'SELECT e.*, p.name AS publisher_name,
                    w.title AS work_title, w.subtitle AS work_subtitle, w.original_title, w.original_language,
                    w.first_published_year, w.description AS work_description, w.created_by AS work_created_by,
                    cu.display_name AS created_by_name
             FROM editions e
             JOIN works w ON w.id = e.work_id
             LEFT JOIN publishers p ON p.id = e.publisher_id
             LEFT JOIN users cu ON cu.id = e.created_by
             WHERE e.id = :id',
            ['id' => $id],
        );
    }

    public function idByIsbn13(string $isbn13, ?int $excludeId = null): ?int
    {
        $id = $this->scalar(
            'SELECT id FROM editions WHERE isbn_13 = :isbn AND id <> :exclude',
            ['isbn' => $isbn13, 'exclude' => $excludeId ?? 0],
        );

        return $id === null ? null : (int) $id;
    }

    /**
     * Possible duplicates for books entered without ISBN: same title on the
     * edition or the work, compared case- and accent-insensitively.
     *
     * @return list<array<string, mixed>>
     */
    public function titleMatches(string $title, int $limit = 10): array
    {
        return $this->many(
            'SELECT e.id, e.title, e.publication_year, e.isbn_13, p.name AS publisher_name,
                    (SELECT COUNT(*) FROM copies c WHERE c.edition_id = e.id) AS copy_count
             FROM editions e JOIN works w ON w.id = e.work_id LEFT JOIN publishers p ON p.id = e.publisher_id
             WHERE e.title = :t1 OR w.title = :t2
             ORDER BY e.id LIMIT :l',
            ['t1' => $title, 't2' => $title, 'l' => $limit],
        );
    }

    /**
     * @param array<string, mixed> $data keys from FIELDS
     */
    public function create(int $workId, array $data, int $userId): int
    {
        return $this->insert(
            'INSERT INTO editions (work_id, title, subtitle, publisher_id, publication_year, edition_statement, language,
                                   page_count, format, isbn_13, isbn_10, legal_deposit, description,
                                   created_by, updated_by, created_at, updated_at)
             VALUES (:work_id, :title, :subtitle, :publisher_id, :publication_year, :edition_statement, :language,
                     :page_count, :format, :isbn_13, :isbn_10, :legal_deposit, :description,
                     :created_by, :updated_by, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            self::only($data) + ['work_id' => $workId, 'created_by' => $userId, 'updated_by' => $userId],
        );
    }

    /**
     * @param array<string, mixed> $data keys from FIELDS
     */
    public function update(int $id, array $data, int $userId): void
    {
        $this->run(
            'UPDATE editions SET title = :title, subtitle = :subtitle, publisher_id = :publisher_id,
                    publication_year = :publication_year, edition_statement = :edition_statement, language = :language,
                    page_count = :page_count, format = :format, isbn_13 = :isbn_13, isbn_10 = :isbn_10,
                    legal_deposit = :legal_deposit, description = :description,
                    updated_by = :updated_by, updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            self::only($data) + ['updated_by' => $userId, 'id' => $id],
        );
    }

    /**
     * @return list<array{id: int, name: string, role: string}>
     */
    public function contributors(int $editionId): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'role' => (string) $r['role']], $this->many(
            'SELECT a.id, a.name, ec.role FROM edition_contributors ec JOIN authors a ON a.id = ec.author_id
             WHERE ec.edition_id = :id ORDER BY ec.position, a.sort_name',
            ['id' => $editionId],
        ));
    }

    /**
     * @param list<array{author_id: int, role: string}> $contributors
     */
    public function setContributors(int $editionId, array $contributors): void
    {
        $this->run('DELETE FROM edition_contributors WHERE edition_id = :id', ['id' => $editionId]);
        $seen = [];
        foreach ($contributors as $position => $c) {
            $key = $c['author_id'] . '|' . $c['role'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $this->run(
                'INSERT INTO edition_contributors (edition_id, author_id, role, position) VALUES (:e, :a, :r, :p)',
                ['e' => $editionId, 'a' => $c['author_id'], 'r' => $c['role'], 'p' => $position],
            );
        }
    }

    public function copyCount(int $id): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM copies WHERE edition_id = :id', ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->run('DELETE FROM editions WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit): array
    {
        return $this->many(
            'SELECT e.id, e.title, e.publication_year,
                    (SELECT cv.id FROM covers cv WHERE cv.edition_id = e.id AND cv.is_primary = 1) AS cover_id,
                    (SELECT GROUP_CONCAT(a.name ORDER BY wa.position SEPARATOR \', \')
                       FROM work_authors wa JOIN authors a ON a.id = wa.author_id
                      WHERE wa.work_id = e.work_id AND wa.role = \'author\') AS authors
             FROM editions e ORDER BY e.created_at DESC, e.id DESC LIMIT :l',
            ['l' => $limit],
        );
    }

    /**
     * @return list<string>
     */
    public function languagesInUse(): array
    {
        return array_column($this->many('SELECT DISTINCT language FROM editions WHERE language IS NOT NULL ORDER BY language'), 'language');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function only(array $data): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $out[$field] = $data[$field] ?? null;
        }

        return $out;
    }
}
