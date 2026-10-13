<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Middleware;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Http\HttpException;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Authorizer;
use DigitalLibrary\Security\Permission;

/**
 * Route guard; must run after Authenticate.
 */
final class RequirePermission implements Middleware
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly Permission $permission,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $user = $request->attribute('user');
        if (!$user instanceof User || !$this->authorizer->can($user, $this->permission)) {
            throw HttpException::forbidden();
        }

        return $next($request);
    }
}
