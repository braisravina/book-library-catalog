<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Admin;

use DigitalLibrary\Http\Controllers\Controller;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\I18n\Translator;
use DigitalLibrary\Security\Role;
use DigitalLibrary\Validation\Validator;

/**
 * Administrator-managed accounts. Public self-registration does not exist;
 * invitation-based registration is planned on top of the invitations table.
 */
final class UserController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->render('admin/users/index', [
            'page_title' => 'admin.users.title',
            'users' => $this->services->users()->all(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form([], []);
    }

    public function store(Request $request): Response
    {
        $admin = $this->user($request);
        $input = [
            'email' => trim((string) $request->input('email', '')),
            'display_name' => trim((string) $request->input('display_name', '')),
            'role' => (string) $request->input('role', ''),
            'locale' => (string) $request->input('locale', ''),
            'password' => (string) $request->input('password', ''),
        ];
        $validator = Validator::make($input, [
            'email' => ['required', 'email', 'max:254'],
            'display_name' => ['required', 'max:100', 'no_control'],
            'role' => ['required', 'in:' . implode(',', array_column(Role::cases(), 'value'))],
            'locale' => ['required', 'in:' . implode(',', Translator::SUPPORTED)],
            'password' => ['required'],
        ]);
        foreach ($this->services->passwordPolicy()->validate($input['password'], $input['email']) as $key) {
            $validator->addError('password', $key);
        }
        if (!$validator->fails() && $this->services->users()->emailExists($input['email'])) {
            $validator->addError('email', 'validation.email_taken');
        }
        if ($validator->fails()) {
            unset($input['password']);

            return $this->form($input, $validator->errors(), 422);
        }

        $id = $this->services->users()->create(
            $input['email'],
            $input['display_name'],
            Role::from($input['role']),
            $this->services->hasher()->hash($input['password']),
            $input['locale'],
            $admin->id,
        );
        $this->services->audit()->log('user.created', $admin->id, 'user', $id, [
            'email' => $input['email'],
            'role' => $input['role'],
        ], $request->remoteAddress(), $request->userAgent());
        $this->flash('success', 'admin.users.created');

        return Response::redirect('/admin/users');
    }

    /**
     * @param array<string, string>       $old
     * @param array<string, list<string>> $errors
     */
    private function form(array $old, array $errors, int $status = 200): Response
    {
        return $this->render('admin/users/create', [
            'page_title' => 'admin.users.new',
            'old' => $old + ['email' => '', 'display_name' => '', 'role' => Role::Member->value, 'locale' => $this->services->defaultLocale()],
            'errors' => $errors,
            'roles' => Role::cases(),
            'locales' => Translator::SUPPORTED,
        ], $status);
    }
}
