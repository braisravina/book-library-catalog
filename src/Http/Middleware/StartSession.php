<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Middleware;

use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Csrf;
use DigitalLibrary\Security\Session;
use DigitalLibrary\View\View;

final class StartSession implements Middleware
{
    public function __construct(
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly View $view,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $this->session->start();
        $this->view->share('csrf_token', $this->csrf->token());
        $this->view->share('current_path', $request->path());

        $response = $next($request);
        // The token may have rotated during the request (login/logout).
        $this->view->share('csrf_token', $this->csrf->token());

        return $response;
    }
}
