<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Security;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Database\Params;
use DigitalLibrary\Security\HouseholdScope;
use DigitalLibrary\Security\Role;
use DigitalLibrary\Security\Visibility;
use PHPUnit\Framework\TestCase;

/**
 * Security\Visibility is the single definition of what a viewer may read
 * (phase H2). These tests pin the SQL it produces and the values it binds.
 */
final class VisibilityTest extends TestCase
{
    private static function user(int $id, Role $role, bool $active = true): User
    {
        return new User($id, "u{$id}@example.org", "User {$id}", $role, 'en', $active, 1);
    }

    private static function member(int $id, int ...$households): Visibility
    {
        return Visibility::forUser(self::user($id, Role::Member), HouseholdScope::of($households));
    }

    /**
     * @return array{string, array<string, int|string|null>}
     */
    private static function sql(Visibility $visibility, string $kind, string $alias): array
    {
        $params = new Params();

        return [$visibility->{$kind}($alias, $params), $params->all()];
    }

    public function testUnrestrictedAddsNoConditionAndBindsNothing(): void
    {
        $admin = Visibility::forUser(self::user(1, Role::Admin), HouseholdScope::forUser(self::user(1, Role::Admin), static fn (): array => self::fail('Not looked up')));
        foreach ([Visibility::unrestricted(), $admin] as $visibility) {
            self::assertTrue($visibility->isUnrestricted());
            self::assertTrue($visibility->includesHousehold(null), 'Inventory without a household is reachable when unrestricted');
            foreach (['copy', 'location', 'edition', 'work', 'author', 'publisher', 'user'] as $kind) {
                self::assertSame(['1 = 1', []], self::sql($visibility, $kind, 'x'), $kind);
            }
        }
    }

    public function testCopiesAndLocationsAreReachedThroughTheHouseholdOnly(): void
    {
        $visibility = self::member(7, 5, 3, 5);

        self::assertSame(['c.household_id IN (:p0, :p1)', ['p0' => 3, 'p1' => 5]], self::sql($visibility, 'copy', 'c'));
        self::assertSame(['l.household_id IN (:p0, :p1)', ['p0' => 3, 'p1' => 5]], self::sql($visibility, 'location', 'l'));
        self::assertTrue($visibility->includesHousehold(3));
        self::assertFalse($visibility->includesHousehold(4));
        self::assertFalse($visibility->includesHousehold(null), 'A copy without a household is never in a member\'s view');
        self::assertFalse($visibility->isUnrestricted());
    }

    public function testEditionsAreVisibleToTheirCreatorOrThroughAVisibleCopy(): void
    {
        [$sql, $params] = self::sql(self::member(7, 3), 'edition', 'e');

        self::assertSame('(e.created_by = :p0 OR EXISTS (SELECT 1 FROM copies vis_c WHERE vis_c.edition_id = e.id AND vis_c.household_id IN (:p1)))', $sql);
        self::assertSame(['p0' => 7, 'p1' => 3], $params);
    }

    public function testWorksAreVisibleToTheirCreatorOrThroughAVisibleEdition(): void
    {
        [$sql, $params] = self::sql(self::member(7, 3), 'work', 'w');

        self::assertSame(
            '(w.created_by = :p0 OR EXISTS (SELECT 1 FROM editions vis_e WHERE vis_e.work_id = w.id AND '
            . '(vis_e.created_by = :p1 OR EXISTS (SELECT 1 FROM copies vis_c WHERE vis_c.edition_id = vis_e.id AND vis_c.household_id IN (:p2)))))',
            $sql,
        );
        self::assertSame(['p0' => 7, 'p1' => 7, 'p2' => 3], $params);
    }

    public function testAMemberWithoutHouseholdsSeesOnlyWhatTheyCatalogued(): void
    {
        $visibility = self::member(7);

        self::assertSame(['0 = 1', []], self::sql($visibility, 'copy', 'c'));
        self::assertSame(['0 = 1', []], self::sql($visibility, 'location', 'l'));
        self::assertSame(['e.created_by = :p0', ['p0' => 7]], self::sql($visibility, 'edition', 'e'));
        self::assertSame(['(w.created_by = :p0 OR EXISTS (SELECT 1 FROM editions vis_e WHERE vis_e.work_id = w.id AND vis_e.created_by = :p1))', ['p0' => 7, 'p1' => 7]], self::sql($visibility, 'work', 'w'));
        self::assertSame(['u.id = :p0', ['p0' => 7]], self::sql($visibility, 'user', 'u'));
    }

    public function testAuthorsPublishersAndUsersFollowFromVisibleRecords(): void
    {
        $visibility = self::member(7, 3);

        [$author, $params] = self::sql($visibility, 'author', 'a');
        self::assertStringContainsString('FROM work_authors vis_wa JOIN works vis_w ON vis_w.id = vis_wa.work_id WHERE vis_wa.author_id = a.id AND (vis_w.created_by = ', $author);
        self::assertStringContainsString('FROM edition_contributors vis_ec JOIN editions vis_ce ON vis_ce.id = vis_ec.edition_id WHERE vis_ec.author_id = a.id AND (vis_ce.created_by = ', $author);
        self::assertSame([7, 7, 3, 7, 3], array_values($params));

        [$publisher, $params] = self::sql($visibility, 'publisher', 'p');
        self::assertSame('EXISTS (SELECT 1 FROM editions vis_pe WHERE vis_pe.publisher_id = p.id AND (vis_pe.created_by = :p0 OR EXISTS (SELECT 1 FROM copies vis_c WHERE vis_c.edition_id = vis_pe.id AND vis_c.household_id IN (:p1))))', $publisher);
        self::assertSame(['p0' => 7, 'p1' => 3], $params);

        self::assertSame(
            ['(u.id = :p0 OR EXISTS (SELECT 1 FROM household_members vis_m WHERE vis_m.user_id = u.id AND vis_m.household_id IN (:p1)))', ['p0' => 7, 'p1' => 3]],
            self::sql($visibility, 'user', 'u'),
        );
    }

    public function testSignedOutAndDeactivatedUsersSeeNothing(): void
    {
        $deactivated = Visibility::forUser(self::user(7, Role::Admin, false), HouseholdScope::all());
        foreach ([Visibility::nobody(), Visibility::forUser(null, HouseholdScope::all()), $deactivated] as $visibility) {
            self::assertFalse($visibility->isUnrestricted());
            self::assertFalse($visibility->includesHousehold(1));
            self::assertSame(['0 = 1', []], self::sql($visibility, 'copy', 'c'));
            // No real user has id 0, so nothing was "created by" this viewer.
            self::assertSame(['e.created_by = :p0', ['p0' => 0]], self::sql($visibility, 'edition', 'e'));
        }
    }

    public function testEveryValueIsBoundAndEachUseGetsItsOwnPlaceholders(): void
    {
        $visibility = self::member(7, 3, 9);
        $params = new Params();
        $first = $visibility->edition('e', $params);
        $second = $visibility->edition('e', $params);

        self::assertNotSame($first, $second, 'A named placeholder cannot be reused by a native prepared statement');
        self::assertCount(6, $params->all());
        foreach ([$first, $second, $visibility->work('w', $params), $visibility->author('a', $params), $visibility->user('u', $params)] as $sql) {
            self::assertDoesNotMatchRegularExpression('/\b(?!1 = 1|0 = 1)\d+\b/', (string) preg_replace('/:p\d+|1 = 1|0 = 1|SELECT 1/', '', $sql), 'No literal ids in the SQL');
        }
    }

    /**
     * Memberships are read from the database for every request: neither
     * the visibility nor the household code may touch the session.
     */
    public function testHouseholdVisibilityNeverUsesTheSession(): void
    {
        $root = dirname(__DIR__, 3) . '/src';
        $files = array_merge([$root . '/Security/Visibility.php', $root . '/Security/HouseholdScope.php'], glob($root . '/Households/*.php') ?: []);
        self::assertGreaterThan(5, count($files));
        foreach ($files as $file) {
            self::assertDoesNotMatchRegularExpression('/\\\\Session\b|Session::|session\(\)|\$_SESSION|->session\b/', (string) file_get_contents($file), basename($file));
        }
        $services = (string) file_get_contents($root . '/Core/Services.php');
        preg_match('/public function householdScope\(.*?\n    }\n.*?public function visibility\(.*?\n    }\n/s', $services, $m);
        self::assertNotEmpty($m);
        self::assertStringNotContainsString('session()', $m[0]);
        self::assertStringContainsString('idsForUser', $m[0]);
    }

    /**
     * Controllers reach records through their own visibility only: none
     * names the unrestricted visibility, and the unfiltered user and
     * location lists stay with the administration pages.
     */
    public function testControllersNeverBypassVisibility(): void
    {
        $root = dirname(__DIR__, 3) . '/src';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $unrestricted = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'Visibility::unrestricted()')) {
                $unrestricted[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($unrestricted);
        self::assertSame(['Core/Services.php'], $unrestricted, 'Only Services decides that a viewer is unrestricted (feature disabled)');

        foreach (array_merge(glob($root . '/Http/Controllers/*.php') ?: [], glob($root . '/Http/Controllers/Catalogue/*.php') ?: [], glob($root . '/Http/Controllers/Library/*.php') ?: []) as $file) {
            $source = (string) file_get_contents($file);
            $name = basename($file);
            self::assertStringNotContainsString('locations()->all()', $source, $name);
            self::assertStringNotContainsString('locations()->tree()', $source, $name);
            if ($name !== 'HealthController.php') {
                self::assertStringNotContainsString('services->pdo()->query', $source, $name . ': controllers do not run their own catalogue queries');
            }
            if ($name !== 'CopyController.php') {
                self::assertStringNotContainsString('users()->all()', $source, $name);
            }
        }
        // The one remaining full user list: the owner choice of copy.manage_any (administrators).
        self::assertSame(1, preg_match('/\$canChooseOwner\s*\? array_values\(array_filter\(\$this->services->users\(\)->all\(\)/', (string) file_get_contents($root . '/Http/Controllers/Catalogue/CopyController.php')));
    }
}
