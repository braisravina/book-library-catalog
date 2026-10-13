<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers;

use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;

final class AccountController extends Controller
{
    public function editPassword(Request $request): Response
    {
        return $this->render('account/password', ['page_title' => 'account.password.title', 'errors' => []]);
    }

    public function updatePassword(Request $request): Response
    {
        $user = $this->user($request);
        $current = (string) $request->input('current_password', '');
        $new = (string) $request->input('new_password', '');
        $confirm = (string) $request->input('new_password_confirmation', '');

        $errors = [];
        if (!$this->services->auth()->verifyPassword($user, $current)) {
            $errors['current_password'][] = 'account.password.current_invalid';
        }
        foreach ($this->services->passwordPolicy()->validate($new, $user->email) as $key) {
            $errors['new_password'][] = $key;
        }
        if (!hash_equals($new, $confirm)) {
            $errors['new_password_confirmation'][] = 'validation.confirmed';
        }
        if ($errors !== []) {
            return $this->render('account/password', ['page_title' => 'account.password.title', 'errors' => $errors], 422);
        }

        $this->services->auth()->changePassword($user, $new);
        $this->services->audit()->log('user.password_changed', $user->id, 'user', $user->id, [], $request->remoteAddress(), $request->userAgent());
        $this->flash('success', 'account.password.updated');

        return Response::redirect('/');
    }
}
