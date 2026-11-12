<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Middleware;

use DigitalLibrary\Auth\AuthService;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Session;

final class Authenticate implements Middleware
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Session $session,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            if ($request->method() === 'GET') {
                $this->session->set('intended', $request->path());
            }
            if ($this->session->pull('_expired', false)) {
                $this->session->flash('info', 'auth.session_expired');
            }

            return Response::redirect('/login');
        }

        return $next($request->withAttribute('user', $user));
    }
}
