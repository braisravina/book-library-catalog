<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Http\HttpException;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Permission;

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

    /**
     * @param array<string, string> $params route parameters
     */
    protected function id(array $params, string $key = 'id'): int
    {
        $value = $params[$key] ?? '';
        if (!preg_match('/^[1-9]\d{0,9}$/', $value)) {
            throw HttpException::notFound();
        }

        return (int) $value;
    }

    /**
     * @template T of array
     *
     * @param T|null $record
     *
     * @return T
     */
    protected function found(?array $record): array
    {
        if ($record === null) {
            throw HttpException::notFound();
        }

        return $record;
    }

    protected function authorize(bool $allowed): void
    {
        if (!$allowed) {
            throw HttpException::forbidden();
        }
    }

    protected function can(User $user, Permission $permission): bool
    {
        return $this->services->authorizer()->can($user, $permission);
    }

    /**
     * @param array<string, mixed> $details
     */
    protected function audit(Request $request, string $action, string $entityType, ?int $entityId, array $details = []): void
    {
        $this->services->audit()->log(
            $action,
            $this->user($request)->id,
            $entityType,
            $entityId,
            $details,
            $request->remoteAddress(),
            $request->userAgent(),
        );
    }
}
