<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers;

use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        if ($this->services->session()->pull('_expired', false)) {
            $this->flash('info', 'auth.session_expired');
        }

        return $this->render('auth/login', ['page_title' => 'auth.login.title', 'email' => '', 'error' => null]);
    }

    public function login(Request $request): Response
    {
        $email = mb_substr(trim((string) $request->input('email', '')), 0, 254);
        $password = (string) $request->input('password', '');

        if ($email === '' || $password === '' || strlen($password) > 4096) {
            return $this->failed($email, 'auth.login.failed');
        }

        $result = $this->services->auth()->attempt($email, $password, $request->remoteAddress(), $request->userAgent());
        if ($result->isThrottled()) {
            $response = $this->failed($email, 'auth.login.throttled', 429, ['minutes' => (int) ceil($result->retryAfter / 60)]);
            $response->setHeader('Retry-After', (string) $result->retryAfter);

            return $response;
        }
        if (!$result->success) {
            // One generic message: never reveal whether the account exists.
            return $this->failed($email, 'auth.login.failed');
        }

        $intended = $this->services->session()->pull('intended', '/');

        return Response::redirect(is_string($intended) && Response::isSafeLocalPath($intended) ? $intended : '/');
    }

    public function logout(Request $request): Response
    {
        $user = $this->user($request);
        $this->services->audit()->log('auth.logout', $user->id, 'user', $user->id, [], $request->remoteAddress(), $request->userAgent());
        $this->services->auth()->logout();
        $this->flash('success', 'auth.logged_out');

        return Response::redirect('/login');
    }

    /**
     * @param array<string, string|int> $params
     */
    private function failed(string $email, string $errorKey, int $status = 422, array $params = []): Response
    {
        return $this->render('auth/login', [
            'page_title' => 'auth.login.title',
            'email' => $email,
            'error' => $errorKey,
            'error_params' => $params,
        ], $status);
    }
}
