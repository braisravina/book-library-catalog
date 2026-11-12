<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Catalogue;

use DigitalLibrary\Catalogue\Slug;
use DigitalLibrary\Catalogue\Tree;
use PHPUnit\Framework\TestCase;

final class TreeTest extends TestCase
{
    /**
     * House > Study > (Shelf 2, Shelf 1); House > Attic; Office
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(): array
    {
        return [
            ['id' => 1, 'parent_id' => null, 'name' => 'House'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'Study'],
            ['id' => 3, 'parent_id' => 2, 'name' => 'Shelf 2'],
            ['id' => 4, 'parent_id' => 2, 'name' => 'shelf 1'],
            ['id' => 5, 'parent_id' => 1, 'name' => 'Attic'],
            ['id' => 6, 'parent_id' => null, 'name' => 'Office'],
        ];
    }

    public function testFlattenIsDepthFirstWithCaseInsensitiveSiblingOrder(): void
    {
        $flat = Tree::flatten(self::rows());

        self::assertSame([1, 5, 2, 4, 3, 6], array_map(static fn (array $r): int => $r['id'], $flat));
        self::assertSame([0, 1, 1, 2, 2, 0], array_column($flat, 'depth'));
        self::assertSame('House › Study › shelf 1', $flat[3]['path']);
    }

    public function testOrphansBecomeRoots(): void
    {
        $flat = Tree::flatten([['id' => 9, 'parent_id' => 404, 'name' => 'Orphan']]);
        self::assertSame(0, $flat[0]['depth']);
    }

    public function testDescendantIdsIncludeSelf(): void
    {
        self::assertEqualsCanonicalizing([2, 3, 4], Tree::descendantIds(self::rows(), 2));
        self::assertEqualsCanonicalizing([1, 2, 3, 4, 5], Tree::descendantIds(self::rows(), 1));
        self::assertSame([6], Tree::descendantIds(self::rows(), 6));
    }

    public function testCycleDetection(): void
    {
        self::assertTrue(Tree::wouldCreateCycle(self::rows(), 1, 1), 'Parent of itself');
        self::assertTrue(Tree::wouldCreateCycle(self::rows(), 1, 3), 'Under its own grandchild');
        self::assertFalse(Tree::wouldCreateCycle(self::rows(), 3, 5), 'Moving a leaf elsewhere');
        self::assertFalse(Tree::wouldCreateCycle(self::rows(), 2, null), 'Moving to top level');
    }

    public function testCorruptCyclicDataDoesNotLoopForever(): void
    {
        $rows = [['id' => 1, 'parent_id' => 2, 'name' => 'A'], ['id' => 2, 'parent_id' => 1, 'name' => 'B']];
        self::assertEqualsCanonicalizing([1, 2], Tree::descendantIds($rows, 1));
    }

    public function testPaths(): void
    {
        self::assertSame('House › Attic', Tree::paths(self::rows())[5]);
    }

    public function testSlug(): void
    {
        self::assertSame('ciencia-ficcion', Slug::from('Ciencia ficción'));
        self::assertSame('narrativa-galega-s-xx', Slug::from('  Narrativa galega (s. XX) '));
        self::assertSame('strasse-aeon', Slug::from('Straße Æon'));
        self::assertSame('item', Slug::from('¿¡!?'));
        self::assertSame(150, strlen(Slug::from(str_repeat('a', 400))));
    }
}
