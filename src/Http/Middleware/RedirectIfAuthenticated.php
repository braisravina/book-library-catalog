<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Middleware;

use DigitalLibrary\Auth\AuthService;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;

final class RedirectIfAuthenticated implements Middleware
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if ($this->auth->user() !== null) {
            return Response::redirect('/');
        }

        return $next($request);
    }
}
