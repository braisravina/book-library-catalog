<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Http;

use DigitalLibrary\Http\HttpException;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $noop = static fn (): Response => new Response('');
        $this->router->get('/', $noop, [], 'home');
        $this->router->get('/books/{id:\d+}', $noop, ['auth'], 'books.show');
        $this->router->post('/books', $noop, ['auth'], 'books.store');
        $this->router->get('/authors/{slug}', $noop, [], 'authors.show');
    }

    public function testMatchesStaticAndParameterisedRoutes(): void
    {
        [$route, $params] = $this->router->match('GET', '/books/42');
        self::assertSame('books.show', $route->name);
        self::assertSame(['id' => '42'], $params);
        self::assertSame(['auth'], $route->middleware);

        [$route] = $this->router->match('GET', '/');
        self::assertSame('home', $route->name);
    }

    public function testConstraintRejectsNonMatchingParameter(): void
    {
        $this->expectExceptionObject(HttpException::notFound());
        $this->router->match('GET', '/books/abc');
    }

    public function testParameterDoesNotSpanSegments(): void
    {
        $this->expectException(HttpException::class);
        $this->router->match('GET', '/authors/a/b');
    }

    public function testWrongMethodIs405(): void
    {
        try {
            $this->router->match('DELETE', '/books');
            self::fail('Expected 405');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status());
        }
    }

    public function testHeadFallsBackToGet(): void
    {
        [$route] = $this->router->match('HEAD', '/');
        self::assertSame('home', $route->name);
    }

    public function testUrlGeneration(): void
    {
        self::assertSame('/books/7', $this->router->url('books.show', ['id' => 7]));
        self::assertSame('/authors/a%20b', $this->router->url('authors.show', ['slug' => 'a b']));
    }

    public function testDuplicateNamesAreRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->router->get('/other', static fn (): Response => new Response(''), [], 'home');
    }

    public function testPathNormalisation(): void
    {
        self::assertSame('/', Request::normalizePath(''));
        self::assertSame('/books', Request::normalizePath('/books/'));
        self::assertSame('/books', Request::normalizePath('books'));
        self::assertSame('/a b', Request::normalizePath('/a%20b'));
    }
}
