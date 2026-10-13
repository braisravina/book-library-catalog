<?php

declare(strict_types=1);

namespace DigitalLibrary\Http;

/**
 * Small method + path router. Patterns support named parameters with an
 * optional constraint: /books/{id:\d+}. Parameters default to [^/]+.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $named = [];

    /**
     * @param list<string> $middleware
     */
    public function get(string $pattern, callable $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('GET', $pattern, $handler, $middleware, $name);
    }

    /**
     * @param list<string> $middleware
     */
    public function post(string $pattern, callable $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('POST', $pattern, $handler, $middleware, $name);
    }

    /**
     * @param list<string> $middleware
     */
    public function add(string $method, string $pattern, callable $handler, array $middleware = [], ?string $name = null): void
    {
        $route = new Route(strtoupper($method), $pattern, $handler, $middleware, $name);
        $this->routes[] = $route;
        if ($name !== null) {
            if (isset($this->named[$name])) {
                throw new \LogicException(sprintf('Duplicate route name "%s".', $name));
            }
            $this->named[$name] = $route;
        }
    }

    /**
     * @return array{Route, array<string, string>}
     *
     * @throws HttpException 404 when no path matches, 405 when only the method differs.
     */
    public function match(string $method, string $path): array
    {
        $method = strtoupper($method);
        $lookupMethod = $method === 'HEAD' ? 'GET' : $method;
        $pathMatched = false;

        foreach ($this->routes as $route) {
            $params = $this->matchPattern($route->pattern, $path);
            if ($params === null) {
                continue;
            }
            $pathMatched = true;
            if ($route->method === $lookupMethod) {
                return [$route, $params];
            }
        }

        throw $pathMatched ? HttpException::methodNotAllowed() : HttpException::notFound();
    }

    /**
     * @param array<string, string|int> $params
     */
    public function url(string $name, array $params = []): string
    {
        $route = $this->named[$name] ?? throw new \InvalidArgumentException(sprintf('Unknown route "%s".', $name));

        return (string) preg_replace_callback(
            '/\{(\w+)(?::[^}]+)?\}/',
            static function (array $m) use ($params): string {
                if (!array_key_exists($m[1], $params)) {
                    throw new \InvalidArgumentException(sprintf('Missing route parameter "%s".', $m[1]));
                }

                return rawurlencode((string) $params[$m[1]]);
            },
            $route->pattern,
        );
    }

    /**
     * @return array<string, string>|null
     */
    private function matchPattern(string $pattern, string $path): ?array
    {
        $regex = preg_replace_callback(
            '/\{(\w+)(?::([^}]+))?\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $pattern,
        );
        if (!preg_match('#^' . $regex . '$#u', $path, $matches)) {
            return null;
        }

        return array_filter($matches, static fn ($key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
    }
}
