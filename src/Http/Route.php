<?php

declare(strict_types=1);

namespace DigitalLibrary\Http;

final class Route
{
    /**
     * @param callable(Request, array<string, string>): Response $handler
     * @param list<string> $middleware Middleware aliases resolved by the application.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        public readonly mixed $handler,
        public readonly array $middleware = [],
        public readonly ?string $name = null,
    ) {
    }
}
