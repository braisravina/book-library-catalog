<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Http;

use DigitalLibrary\Http\HttpException;
use DigitalLibrary\Http\Middleware\SecurityHeaders;
use DigitalLibrary\Http\Middleware\VerifyCsrf;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Csrf;
use DigitalLibrary\Security\Session;
use PHPUnit\Framework\TestCase;

final class MiddlewareTest extends TestCase
{
    private Csrf $csrf;

    protected function setUp(): void
    {
        $session = Session::inMemory();
        $session->start();
        $this->csrf = new Csrf($session);
    }

    public function testSecurityHeadersAreApplied(): void
    {
        $response = (new SecurityHeaders(true))->process(new Request('GET', '/'), static fn (): Response => new Response('ok'));

        $csp = (string) $response->header('Content-Security-Policy');
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('DENY', $response->header('X-Frame-Options'));
        self::assertSame('no-store, private', $response->header('Cache-Control'));
        self::assertNotNull($response->header('Strict-Transport-Security'));
    }

    public function testSafeMethodsBypassCsrf(): void
    {
        $response = $this->csrfMiddleware()->process(new Request('GET', '/'), static fn (): Response => new Response('ok'));
        self::assertSame('ok', $response->body());
    }

    public function testPostWithoutTokenIsRejected(): void
    {
        $this->expectCsrfFailure(419, new Request('POST', '/login'));
    }

    public function testPostWithWrongTokenIsRejected(): void
    {
        $this->csrf->token();
        $this->expectCsrfFailure(419, new Request('POST', '/login', [], ['_csrf' => str_repeat('a', 64)]));
    }

    public function testPostWithValidTokenPasses(): void
    {
        $request = new Request('POST', '/login', [], ['_csrf' => $this->csrf->token()]);
        $response = $this->csrfMiddleware()->process($request, static fn (): Response => new Response('ok'));
        self::assertSame('ok', $response->body());
    }

    public function testHeaderTokenIsAccepted(): void
    {
        $request = new Request('POST', '/x', [], [], ['HTTP_X_CSRF_TOKEN' => $this->csrf->token()]);
        self::assertSame('ok', $this->csrfMiddleware()->process($request, static fn (): Response => new Response('ok'))->body());
    }

    public function testCrossOriginPostIsRejectedEvenWithToken(): void
    {
        $request = new Request('POST', '/x', [], ['_csrf' => $this->csrf->token()], ['HTTP_ORIGIN' => 'https://evil.example']);
        $this->expectCsrfFailure(403, $request);
    }

    public function testCrossSiteFetchMetadataIsRejected(): void
    {
        $request = new Request('POST', '/x', [], ['_csrf' => $this->csrf->token()], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        $this->expectCsrfFailure(403, $request);
    }

    public function testSameOriginPostPasses(): void
    {
        $request = new Request('POST', '/x', [], ['_csrf' => $this->csrf->token()], ['HTTP_ORIGIN' => 'https://library.example.org']);
        self::assertSame('ok', $this->csrfMiddleware()->process($request, static fn (): Response => new Response('ok'))->body());
    }

    private function csrfMiddleware(): VerifyCsrf
    {
        return new VerifyCsrf($this->csrf, 'https://library.example.org');
    }

    private function expectCsrfFailure(int $status, Request $request): void
    {
        try {
            $this->csrfMiddleware()->process($request, static fn (): Response => new Response('ok'));
            self::fail('Expected CSRF rejection');
        } catch (HttpException $e) {
            self::assertSame($status, $e->status());
        }
    }
}
