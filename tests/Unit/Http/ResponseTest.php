<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Http;

use DigitalLibrary\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeRedirects(): iterable
    {
        yield 'absolute url' => ['https://evil.example/'];
        yield 'protocol relative' => ['//evil.example/'];
        yield 'backslash trick' => ['/\\evil.example'];
        yield 'relative' => ['evil'];
        yield 'header injection' => ["/ok\r\nSet-Cookie: x=y"];
        yield 'javascript' => ['javascript:alert(1)'];
    }

    #[DataProvider('unsafeRedirects')]
    public function testRedirectRejectsNonLocalTargets(string $target): void
    {
        self::assertSame('/', Response::redirect($target)->header('Location'));
    }

    public function testRedirectKeepsLocalPath(): void
    {
        $response = Response::redirect('/admin/users?page=2');
        self::assertSame(303, $response->status());
        self::assertSame('/admin/users?page=2', $response->header('Location'));
    }

    public function testHeaderInjectionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Response())->setHeader('X-Test', "a\r\nb: c");
    }

    public function testHeaderNamesAreCaseInsensitive(): void
    {
        $response = new Response('', 200, ['content-type' => 'text/plain']);
        self::assertSame('text/plain', $response->header('Content-Type'));
    }

    public function testJsonEscapesSafely(): void
    {
        $response = Response::json(['a' => '<script>']);
        self::assertSame('application/json; charset=UTF-8', $response->header('Content-Type'));
        self::assertSame('{"a":"<script>"}', $response->body());
    }
}
