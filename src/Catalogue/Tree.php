<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue;

/**
 * Helpers for adjacency-list hierarchies (categories, locations). The trees
 * are small, so they are loaded whole and processed in PHP; this keeps the
 * SQL simple and portable.
 */
final class Tree
{
    /**
     * Orders nodes depth-first (siblings by name) and annotates each with
     * 'depth' and 'path' (ancestor names joined with " › ").
     *
     * @param list<array<string, mixed>> $rows each with id, parent_id, name
     *
     * @return list<array<string, mixed>>
     */
    public static function flatten(array $rows): array
    {
        $children = [];
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        foreach ($rows as $row) {
            $parent = $row['parent_id'] !== null && isset($byId[(int) $row['parent_id']]) ? (int) $row['parent_id'] : 0;
            $children[$parent][] = $row;
        }
        foreach ($children as &$list) {
            usort($list, static fn (array $a, array $b): int => [mb_strtolower((string) $a['name']), (int) $a['id']] <=> [mb_strtolower((string) $b['name']), (int) $b['id']]);
        }
        unset($list);

        $out = [];
        $walk = static function (int $parent, int $depth, array $trail) use (&$walk, &$out, $children): void {
            foreach ($children[$parent] ?? [] as $node) {
                $path = [...$trail, (string) $node['name']];
                $out[] = $node + ['depth' => $depth, 'path' => implode(' › ', $path)];
                $walk((int) $node['id'], $depth + 1, $path);
            }
        };
        $walk(0, 0, []);

        return $out;
    }

    /**
     * The node itself plus all of its descendants.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<int>
     */
    public static function descendantIds(array $rows, int $id): array
    {
        $children = [];
        foreach ($rows as $row) {
            if ($row['parent_id'] !== null) {
                $children[(int) $row['parent_id']][] = (int) $row['id'];
            }
        }
        $result = [];
        $queue = [$id];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (in_array($current, $result, true)) {
                continue; // defensive: tolerate corrupt cyclic data
            }
            $result[] = $current;
            foreach ($children[$current] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return $result;
    }

    /**
     * True when re-parenting $id under $newParentId would create a cycle.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function wouldCreateCycle(array $rows, int $id, ?int $newParentId): bool
    {
        return $newParentId !== null && in_array($newParentId, self::descendantIds($rows, $id), true);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, string> id => breadcrumb path
     */
    public static function paths(array $rows): array
    {
        $out = [];
        foreach (self::flatten($rows) as $node) {
            $out[(int) $node['id']] = (string) $node['path'];
        }

        return $out;
    }
}
