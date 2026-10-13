<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Middleware;

use DigitalLibrary\Http\HttpException;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Csrf;

/**
 * Rejects state-changing requests without a valid synchroniser token, and
 * cross-origin requests when the browser declares their origin.
 */
final class VerifyCsrf implements Middleware
{
    public function __construct(
        private readonly Csrf $csrf,
        private readonly ?string $appUrl,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }
        if (!$this->originAllowed($request)) {
            throw new HttpException(403, 'errors.csrf');
        }
        $token = $request->input(Csrf::FIELD) ?? $request->header(Csrf::HEADER);
        if (!$this->csrf->isValid($token)) {
            throw new HttpException(419, 'errors.csrf');
        }

        return $next($request);
    }

    private function originAllowed(Request $request): bool
    {
        if ($request->header('Sec-Fetch-Site') === 'cross-site') {
            return false;
        }
        $origin = $request->header('Origin');
        if ($origin === null || $origin === 'null' || $this->appUrl === null) {
            return $origin !== 'null';
        }
        $expected = parse_url($this->appUrl);
        $actual = parse_url($origin);
        if (!is_array($expected) || !is_array($actual)) {
            return false;
        }

        return strtolower($expected['scheme'] ?? '') === strtolower($actual['scheme'] ?? '')
            && strtolower($expected['host'] ?? '') === strtolower($actual['host'] ?? '')
            && ($expected['port'] ?? null) === ($actual['port'] ?? null);
    }
}
