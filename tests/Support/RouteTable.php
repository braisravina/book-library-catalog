<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Support;

/**
 * The named routes of config/routes.php and their classification in
 * config/household_access.php, read from the source files (no database or
 * application needed).
 */
final class RouteTable
{
    /**
     * name => [method, path, middleware variable or list]
     *
     * @return array<string, array{string, string, string}>
     */
    public static function routes(): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/config/routes.php');
        preg_match_all('/\$router->(get|post)\(\'([^\']+)\', [^,]+, (\[[^\]]*\]|\$\w+), \'([a-z_.]+)\'\);/', $source, $m, PREG_SET_ORDER);
        $routes = [];
        foreach ($m as $match) {
            if (isset($routes[$match[4]])) {
                throw new \LogicException('Duplicate route name ' . $match[4]);
            }
            $routes[$match[4]] = [strtoupper($match[1]), $match[2], $match[3]];
        }
        if (substr_count($source, '$router->') !== count($routes)) {
            throw new \LogicException('A route in config/routes.php is unnamed or was not parsed');
        }

        return $routes;
    }

    /**
     * @return array<string, array{kind: string, phase: string, rule: string, read?: string}>
     */
    public static function matrix(): array
    {
        return require dirname(__DIR__, 2) . '/config/household_access.php';
    }

    /**
     * Routes that return catalogue, inventory, loan, collection, wishlist
     * or export data to non-administrators: the ones household visibility
     * must cover. name => [method, path]
     *
     * @return array<string, array{string, string}>
     */
    public static function recordRoutes(): array
    {
        $matrix = self::matrix();
        $out = [];
        foreach (self::routes() as $name => [$method, $path]) {
            if (in_array($matrix[$name]['kind'] ?? '', ['bibliographic', 'inventory', 'loan', 'collection', 'wishlist', 'export'], true)) {
                $out[$name] = [$method, $path];
            }
        }

        return $out;
    }
}
