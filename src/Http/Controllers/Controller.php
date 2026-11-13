<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Http\HttpException;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Permission;
use DigitalLibrary\Security\Visibility;

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
        $view->share('households_enabled', $this->services->householdsEnabled());

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

    /**
     * What the signed-in user may see (phase H2). Every record a controller
     * loads or lists is read through it, for GET and POST alike, so a
     * record outside the user's households is answered with 404 exactly
     * like one that does not exist. Unrestricted for administrators and
     * while the household feature is disabled.
     */
    protected function visibility(): Visibility
    {
        return $this->services->visibility($this->services->auth()->user());
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
