<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue;

use DigitalLibrary\Catalogue\Repository\CategoryRepository;
use DigitalLibrary\Catalogue\Repository\LocationRepository;
use DigitalLibrary\Database\Params;
use PDO;

/**
 * Catalogue browsing and search over editions.
 *
 * Text search uses LIKE with escaped wildcards: every word must match the
 * title, subtitle, original title, an author or contributor name, or an
 * ISBN. The default utf8mb4_0900_ai_ci collation makes matching case- and
 * accent-insensitive ("garcia" finds "García"), and at personal-library
 * scale this is fast enough while avoiding FULLTEXT stop-word and
 * minimum-token pitfalls. Category and location filters include descendants.
 */
final class CatalogueSearch
{
    private const MAX_WORDS = 8;

    public function __construct(
        private readonly PDO $pdo,
        private readonly CategoryRepository $categories,
        private readonly LocationRepository $locations,
    ) {
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function search(SearchCriteria $criteria): array
    {
        $params = new Params();
        $where = $this->conditions($criteria, $params);
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM editions e JOIN works w ON w.id = e.work_id {$whereSql}");
        $params->bind($countStmt);
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $pages = max(1, (int) ceil($total / SearchCriteria::PER_PAGE));
        $page = min($criteria->page, $pages);
        $order = match ($criteria->sort) {
            'recent' => 'e.created_at DESC, e.id DESC',
            'year' => 'e.publication_year IS NULL, e.publication_year DESC, e.title, e.id',
            default => 'e.title, e.id',
        };
        $limit = $params->add(SearchCriteria::PER_PAGE);
        $offset = $params->add(($page - 1) * SearchCriteria::PER_PAGE);

        $stmt = $this->pdo->prepare(
            "SELECT e.id, e.work_id, e.title, e.subtitle, e.publication_year, e.language, e.format, e.isbn_13,
                    p.name AS publisher_name,
                    (SELECT COUNT(*) FROM copies c WHERE c.edition_id = e.id) AS copy_count,
                    (SELECT cv.id FROM covers cv WHERE cv.edition_id = e.id AND cv.is_primary = 1) AS cover_id,
                    (SELECT GROUP_CONCAT(a.name ORDER BY wa.position SEPARATOR ', ')
                       FROM work_authors wa JOIN authors a ON a.id = wa.author_id
                      WHERE wa.work_id = e.work_id AND wa.role = 'author') AS authors
             FROM editions e
             JOIN works w ON w.id = e.work_id
             LEFT JOIN publishers p ON p.id = e.publisher_id
             {$whereSql}
             ORDER BY {$order}
             LIMIT {$limit} OFFSET {$offset}"
        );
        $params->bind($stmt);
        $stmt->execute();

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * @return list<string> SQL fragments; every value is a bound parameter
     */
    private function conditions(SearchCriteria $c, Params $params): array
    {
        $where = [];

        if ($c->query !== '') {
            $isbn = Isbn::tryParse($c->query);
            if ($isbn !== null) {
                $where[] = 'e.isbn_13 = ' . $params->add($isbn->isbn13);
            } else {
                $words = array_slice(preg_split('/\s+/u', $c->query, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, self::MAX_WORDS);
                foreach ($words as $word) {
                    $where[] = $this->wordCondition($word, $params);
                }
            }
        }

        if ($c->categoryId !== null) {
            $ids = $this->categories->descendantIds($c->categoryId);
            $where[] = 'EXISTS (SELECT 1 FROM work_categories wc WHERE wc.work_id = e.work_id AND wc.category_id IN (' . $params->in($ids) . '))';
        }

        if ($c->language !== null) {
            $where[] = 'e.language = ' . $params->add($c->language);
        }

        // Owner and location must match the same physical copy.
        $copyConditions = [];
        if ($c->ownerId !== null) {
            $copyConditions[] = 'c.owner_user_id = ' . $params->add($c->ownerId);
        }
        if ($c->locationId !== null) {
            $copyConditions[] = 'c.location_id IN (' . $params->in($this->locations->descendantIds($c->locationId)) . ')';
        }
        if ($copyConditions !== []) {
            $where[] = 'EXISTS (SELECT 1 FROM copies c WHERE c.edition_id = e.id AND ' . implode(' AND ', $copyConditions) . ')';
        }

        return $where;
    }

    private function wordCondition(string $word, Params $params): string
    {
        $like = Params::likeContains($word);
        $fields = ['e.title', 'e.subtitle', 'w.title', 'w.subtitle', 'w.original_title'];
        $parts = array_map(fn (string $f): string => $f . ' LIKE ' . $params->add($like), $fields);
        $parts[] = 'EXISTS (SELECT 1 FROM work_authors wa JOIN authors a ON a.id = wa.author_id WHERE wa.work_id = e.work_id AND a.name LIKE ' . $params->add($like) . ')';
        $parts[] = 'EXISTS (SELECT 1 FROM edition_contributors ec JOIN authors a2 ON a2.id = ec.author_id WHERE ec.edition_id = e.id AND a2.name LIKE ' . $params->add($like) . ')';

        $digits = Isbn::normalize($word);
        if (preg_match('/^\d{4,13}X?$/', $digits)) {
            $parts[] = 'e.isbn_13 LIKE ' . $params->add(Params::likeContains($digits));
            $parts[] = 'e.isbn_10 LIKE ' . $params->add(Params::likeContains($digits));
        }

        return '(' . implode(' OR ', $parts) . ')';
    }
}
