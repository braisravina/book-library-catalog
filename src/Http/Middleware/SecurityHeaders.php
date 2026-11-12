<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Middleware;

use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;

final class SecurityHeaders implements Middleware
{
    public function __construct(private readonly bool $hsts = false)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);

        $defaults = [
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
                . "font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            // The catalogue is private: never let shared caches or search engines keep pages.
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
        if ($this->hsts) {
            $defaults['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        foreach ($defaults as $name => $value) {
            if ($response->header($name) === null) {
                $response->setHeader($name, $value);
            }
        }

        return $response;
    }
}
