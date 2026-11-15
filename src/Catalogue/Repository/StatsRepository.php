<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue\Repository;

use DigitalLibrary\Database\Params;
use DigitalLibrary\Security\Visibility;

/**
 * Dashboard figures. Every count is taken over the records the viewer may
 * see, so the totals say nothing about other households' holdings.
 */
final class StatsRepository extends Repository
{
    /**
     * @return array{works: int, editions: int, copies: int, authors: int, my_copies: int}
     */
    public function dashboard(Visibility $visibility, int $userId): array
    {
        $params = new Params();
        $row = $this->one(
            'SELECT
                (SELECT COUNT(*) FROM works w WHERE ' . $visibility->work('w', $params) . ') AS works,
                (SELECT COUNT(*) FROM editions e WHERE ' . $visibility->edition('e', $params) . ') AS editions,
                (SELECT COUNT(*) FROM copies c WHERE ' . $visibility->copy('c', $params) . ') AS copies,
                (SELECT COUNT(*) FROM authors a WHERE ' . $visibility->author('a', $params) . ') AS authors,
                (SELECT COUNT(*) FROM copies mc WHERE mc.owner_user_id = ' . $params->add($userId) . ' AND ' . $visibility->copy('mc', $params) . ') AS my_copies',
            $params->all(),
        ) ?? [];

        return [
            'works' => (int) ($row['works'] ?? 0),
            'editions' => (int) ($row['editions'] ?? 0),
            'copies' => (int) ($row['copies'] ?? 0),
            'authors' => (int) ($row['authors'] ?? 0),
            'my_copies' => (int) ($row['my_copies'] ?? 0),
        ];
    }
}
