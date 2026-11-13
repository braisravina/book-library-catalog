<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * config/household_access.php classifies every route against the household
 * boundary. A route added without an entry fails here, so no endpoint is
 * forgotten when the boundary is enforced (phases H2/H3).
 */
final class HouseholdAccessMatrixTest extends TestCase
{
    private const KINDS = ['public', 'account', 'admin', 'bibliographic', 'inventory', 'loan', 'collection', 'wishlist', 'export', 'household'];
    private const PHASES = ['none', 'H1', 'H2', 'H3'];

    /**
     * @return array<string, array{kind: string, phase: string, rule: string}>
     */
    private static function matrix(): array
    {
        return require dirname(__DIR__, 3) . '/config/household_access.php';
    }

    /**
     * Named routes of config/routes.php: name => [method, path, middleware variable or list].
     *
     * @return array<string, array{string, string, string}>
     */
    private static function routes(): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/config/routes.php');
        preg_match_all('/\$router->(get|post)\(\'([^\']+)\', [^,]+, (\[[^\]]*\]|\$\w+), \'([a-z_.]+)\'\);/', $source, $m, PREG_SET_ORDER);
        $routes = [];
        foreach ($m as $match) {
            self::assertArrayNotHasKey($match[4], $routes, 'Route names are unique');
            $routes[$match[4]] = [strtoupper($match[1]), $match[2], $match[3]];
        }
        self::assertSame(substr_count($source, '$router->'), count($routes), 'Every route is named and was parsed');

        return $routes;
    }

    public function testEveryRouteIsClassifiedAndNothingElse(): void
    {
        $routes = array_keys(self::routes());
        $classified = array_keys(self::matrix());

        self::assertSame([], array_values(array_diff($routes, $classified)), 'Routes missing from config/household_access.php');
        self::assertSame([], array_values(array_diff($classified, $routes)), 'Entries for routes that no longer exist');
        self::assertGreaterThan(100, count($routes));
    }

    public function testEntriesAreWellFormed(): void
    {
        foreach (self::matrix() as $name => $entry) {
            self::assertSame(['kind', 'phase', 'rule'], array_slice(array_keys($entry), 0, 3), $name);
            self::assertContains(array_slice(array_keys($entry), 3), [[], ['read']], $name);
            if (isset($entry['read'])) {
                self::assertSame('H2', $entry['read'], $name);
                self::assertNotSame('H2', $entry['phase'], $name . ': an H2 route needs no separate read phase');
            }
            self::assertContains($entry['kind'], self::KINDS, $name);
            self::assertContains($entry['phase'], self::PHASES, $name);
            self::assertGreaterThan(15, strlen($entry['rule']), $name . ': the rule is stated');
        }
    }

    /**
     * Routes that show or change catalogue or inventory records can never
     * be declared free of household rules unless they are administrator-only
     * or act on the user's own collection or wishlist item.
     */
    public function testRecordRoutesCarryAHouseholdRule(): void
    {
        $matrix = self::matrix();
        foreach (self::routes() as $name => [$method, $path]) {
            $entry = $matrix[$name];
            if (preg_match('#^/(books|works|editions|copies|loans|covers|authors|publishers|export/personal)\b#', $path) !== 1) {
                continue;
            }
            if ($entry['kind'] === 'admin') {
                continue;
            }
            self::assertNotSame('none', $entry['phase'], "{$method} {$path} ({$name}) needs a household rule");
        }
        self::assertSame('H2', $matrix['home']['phase'], 'The dashboard shows catalogue statistics');
        self::assertSame('H2', $matrix['covers.show']['phase']);
        self::assertSame('H2', $matrix['collections.show']['phase'], 'Collection items are filtered per viewer');
    }

    /**
     * Phase H2: every route that returns record data on GET is
     * read-enforced, and so is every route that looks a record up by id
     * (its 404 comes before any form, error page or write).
     */
    public function testEveryRecordReadIsEnforcedFromPhaseTwo(): void
    {
        $matrix = self::matrix();
        $recordKinds = ['bibliographic', 'inventory', 'loan', 'collection', 'wishlist', 'export'];
        $get = 0;
        foreach (self::routes() as $name => [$method, $path]) {
            $entry = $matrix[$name];
            if (!in_array($entry['kind'], $recordKinds, true)) {
                continue;
            }
            $readPhase = $entry['read'] ?? $entry['phase'];
            if ($method === 'GET') {
                $get++;
                self::assertSame('H2', $readPhase, "GET {$path} ({$name}) returns record data and must be read-enforced");
            }
            if (preg_match('#^/(works|editions|copies|loans|covers)/\{id#', $path) === 1) {
                self::assertSame('H2', $readPhase, "{$method} {$path} ({$name}) looks a record up by id");
            }
        }
        self::assertGreaterThanOrEqual(30, $get);
        self::assertSame('H3', $matrix['works.update']['phase'], 'Write rules for shared records are still phase H3');
        self::assertSame('H3', $matrix['invite.accept.submit']['phase']);
    }

    /**
     * "Administrators only" in the matrix must match the route guard: such a
     * route requires a permission that only the administrator role holds.
     */
    public function testAdminEntriesAreGuardedByAnAdministratorPermission(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/config/routes.php');
        $adminPermissions = array_values(array_diff(
            array_column(\DigitalLibrary\Security\Permission::cases(), 'value'),
            array_map(static fn (\DigitalLibrary\Security\Permission $p): string => $p->value, \DigitalLibrary\Security\Role::Member->permissions()),
        ));
        $matrix = self::matrix();
        foreach (self::routes() as $name => [, , $middleware]) {
            if (!in_array($matrix[$name]['kind'], ['admin', 'household'], true)) {
                continue;
            }
            if ($middleware[0] === '$') {
                self::assertSame(1, preg_match('/' . preg_quote($middleware, '/') . ' = (\[[^\]]*\]);/', $source, $definition), $name);
                $middleware = $definition[1];
            }
            self::assertSame(1, preg_match("/'can:([a-z_.]+)'/", $middleware, $permission), $name . ' has a permission guard');
            self::assertContains($permission[1], $adminPermissions, $name . ' is guarded by an administrator-only permission');
            self::assertStringContainsString("'auth'", $middleware, $name);
        }
    }

    public function testHouseholdAdministrationIsEnforcedInPhaseOne(): void
    {
        $matrix = self::matrix();
        $household = array_filter($matrix, static fn (array $e): bool => $e['kind'] === 'household');

        self::assertCount(8, $household);
        foreach ($household as $name => $entry) {
            self::assertSame('H1', $entry['phase'], $name);
            self::assertStringStartsWith('admin.households', $name);
        }
        self::assertSame('H1', $matrix['copies.store']['phase']);
        self::assertSame('H1', $matrix['copies.update']['phase']);
    }
}
