<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Admin;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Auth\UserAdminException;
use DigitalLibrary\Households\HouseholdRepository;
use DigitalLibrary\Http\Controllers\Controller;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\I18n\Translator;
use DigitalLibrary\Security\Role;
use DigitalLibrary\Validation\Validator;

/**
 * Administrator-managed accounts. Public self-registration does not exist;
 * invitees register through InvitationController / RegistrationController.
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
        // Optional household memberships (only while households are in use).
        $selected = $this->services->householdsEnabled() ? $request->inputList('household_ids') : [];
        $householdIds = HouseholdRepository::idList($selected);
        if ($householdIds === null) {
            $validator->addError('household_ids', 'validation.in');
        }
        if ($validator->fails()) {
            unset($input['password']);

            return $this->form($input, $validator->errors(), 422, $selected);
        }

        try {
            $created = $this->services->userAdministration()->create(
                $admin,
                $input['email'],
                $input['display_name'],
                Role::from($input['role']),
                $this->services->hasher()->hash($input['password']),
                $input['locale'],
                $householdIds ?? [],
            );
        } catch (UserAdminException $e) {
            if ($e->getMessage() === 'errors.403.message') {
                throw \DigitalLibrary\Http\HttpException::forbidden();
            }
            unset($input['password']);
            $field = $e->getMessage() === 'validation.email_taken' ? 'email' : 'household_ids';

            return $this->form($input, [$field => [$e->getMessage()]], 422, $selected);
        }
        $id = $created['id'];
        $this->services->audit()->log('user.created', $admin->id, 'user', $id, [
            'email' => $input['email'],
            'role' => $input['role'],
        ], $request->remoteAddress(), $request->userAgent());
        // Each membership is recorded on its household, by account id only.
        foreach ($created['household_ids'] as $householdId) {
            $this->audit($request, 'household.member_added', 'household', $householdId, ['member_user_id' => $id, 'via' => 'user_creation']);
        }
        $this->flash('success', 'admin.users.created');

        return Response::redirect('/admin/users');
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $target = $this->target($params);

        return $this->editPage($request, $target, [
            'email' => $target->email,
            'display_name' => $target->displayName,
            'role' => $target->role->value,
            'locale' => $target->locale,
            'is_active' => $target->isActive ? '1' : '0',
        ], []);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $admin = $this->user($request);
        $target = $this->target($params);
        $input = [
            'email' => trim((string) $request->input('email', '')),
            'display_name' => trim((string) $request->input('display_name', '')),
            'role' => (string) $request->input('role', ''),
            'locale' => (string) $request->input('locale', ''),
            'is_active' => $request->input('is_active') === '1' ? '1' : '0',
        ];
        $validator = Validator::make($input, [
            'email' => ['required', 'email', 'max:254'],
            'display_name' => ['required', 'max:100', 'no_control'],
            'role' => ['required', 'in:' . implode(',', array_column(Role::cases(), 'value'))],
            'locale' => ['required', 'in:' . implode(',', Translator::SUPPORTED)],
        ]);
        if (!$validator->fails() && $this->services->users()->emailExists($input['email'], $target->id)) {
            $validator->addError('email', 'validation.email_taken');
        }
        if ($validator->fails()) {
            return $this->editPage($request, $target, $input, $validator->errors(), 422);
        }

        try {
            $changes = $this->services->userAdministration()->update(
                $admin, $target, $input['email'], $input['display_name'], Role::from($input['role']), $input['locale'], $input['is_active'] === '1',
            );
        } catch (UserAdminException $e) {
            return $this->editPage($request, $target, $input, [], 409, $e->getMessage());
        }
        if ($changes !== []) {
            $this->audit($request, 'user.updated', 'user', $target->id, ['changes' => $changes]);
        }
        $this->flash('success', 'admin.users.saved');

        return Response::redirect('/admin/users');
    }

    /**
     * @param array<string, string> $params
     */
    public function resetPassword(Request $request, array $params): Response
    {
        $admin = $this->user($request);
        $target = $this->target($params);
        $password = (string) $request->input('new_password', '');
        $confirm = (string) $request->input('new_password_confirmation', '');
        $errors = [];
        foreach ($this->services->passwordPolicy()->validate($password, $target->email) as $key) {
            $errors['new_password'][] = $key;
        }
        if (!hash_equals($password, $confirm)) {
            $errors['new_password_confirmation'][] = 'validation.confirmed';
        }
        if ($errors !== []) {
            return $this->editPage($request, $target, null, $errors, 422);
        }
        try {
            $this->services->userAdministration()->resetPassword($admin, $target, $this->services->hasher()->hash($password));
        } catch (UserAdminException $e) {
            return $this->editPage($request, $target, null, [], 409, $e->getMessage());
        }
        $this->audit($request, 'user.password_reset', 'user', $target->id);
        $this->flash('success', 'admin.users.password_reset_done');

        return Response::redirect('/admin/users/' . $target->id . '/edit');
    }

    /**
     * @param array<string, string> $params
     */
    public function revokeSessions(Request $request, array $params): Response
    {
        $admin = $this->user($request);
        $target = $this->target($params);
        try {
            $this->services->userAdministration()->revokeSessions($admin, $target);
        } catch (UserAdminException $e) {
            return $this->editPage($request, $target, null, [], 409, $e->getMessage());
        }
        $this->audit($request, 'user.sessions_revoked', 'user', $target->id);
        $this->flash('success', 'admin.users.sessions_revoked');

        return Response::redirect('/admin/users/' . $target->id . '/edit');
    }

    /**
     * @param array<string, string> $params
     */
    private function target(array $params): User
    {
        $target = $this->services->users()->findById($this->id($params));
        if ($target === null) {
            throw \DigitalLibrary\Http\HttpException::notFound();
        }

        return $target;
    }

    /**
     * @param array<string, string>|null  $old    profile form values (null: from the record)
     * @param array<string, list<string>> $errors
     */
    private function editPage(Request $request, User $target, ?array $old, array $errors, int $status = 200, ?string $refusal = null): Response
    {
        $old ??= [
            'email' => $target->email,
            'display_name' => $target->displayName,
            'role' => $target->role->value,
            'locale' => $target->locale,
            'is_active' => $target->isActive ? '1' : '0',
        ];

        return $this->render('admin/users/edit', [
            'page_title' => 'admin.users.edit_title',
            'target' => $target,
            'is_self' => $target->id === $this->user($request)->id,
            'old' => $old,
            'errors' => $errors,
            'refusal' => $refusal,
            'roles' => Role::cases(),
            'locales' => Translator::SUPPORTED,
        ], $status);
    }

    /**
     * @param array<string, string>       $old
     * @param array<string, list<string>> $errors
     * @param list<mixed>                 $selectedHouseholds submitted household ids
     */
    private function form(array $old, array $errors, int $status = 200, array $selectedHouseholds = []): Response
    {
        return $this->render('admin/users/create', [
            // Offered only while households are in use; nothing is ticked by default.
            'households' => $this->services->householdsEnabled() ? $this->services->households()->names() : [],
            'selected_households' => array_values(array_filter($selectedHouseholds, 'is_string')),
            'page_title' => 'admin.users.new',
            'old' => $old + ['email' => '', 'display_name' => '', 'role' => Role::Member->value, 'locale' => $this->services->defaultLocale()],
            'errors' => $errors,
            'roles' => Role::cases(),
            'locales' => Translator::SUPPORTED,
        ], $status);
    }
}
