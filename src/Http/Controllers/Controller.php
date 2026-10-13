<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;

abstract class Controller
{
    public function __construct(protected readonly Services $services)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        $view = $this->services->view();
        $view->share('current_user', $this->services->auth()->user());
        $view->share('authorizer', $this->services->authorizer());
        $view->share('flashes', $this->services->session()->takeFlashes());

        return Response::html($view->render($template, $data), $status);
    }

    protected function user(Request $request): User
    {
        $user = $request->attribute('user');
        if (!$user instanceof User) {
            throw new \LogicException('Route requires the auth middleware.');
        }

        return $user;
    }

    protected function flash(string $type, string $messageKey): void
    {
        $this->services->session()->flash($type, $messageKey);
    }
}
