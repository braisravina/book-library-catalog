<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Admin;

use DigitalLibrary\Households\HouseholdException;
use DigitalLibrary\Households\HouseholdInput;
use DigitalLibrary\Http\Controllers\Controller;
use DigitalLibrary\Http\HttpException;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Validation\Validator;

/**
 * Household administration: administrators create, rename and delete
 * households and assign their members.
 *
 * Access: the routes require household.manage (administrators); every
 * action also requires the household feature to be enabled and answers
 * 404 otherwise, and HouseholdService checks the permission again. The
 * feature cannot be enabled in production in phase H1: memberships do not
 * restrict what users see until phases H2/H3.
 */
final class HouseholdController extends Controller
{
    public function index(Request $request): Response
    {
        $this->requireFeature();

        return $this->render('admin/households/index', [
            'page_title' => 'households.title',
            'households' => $this->services->households()->all(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->requireFeature();

        return $this->form(null, HouseholdInput::blank(), []);
    }

    public function store(Request $request): Response
    {
        $this->requireFeature();
        $input = HouseholdInput::fromRequest($request);
        $validator = Validator::make([], []);
        $input->validate($validator);
        if ($validator->fails()) {
            return $this->form(null, $input, $validator->errors(), 422);
        }
        try {
            $id = $this->services->householdService()->create($this->user($request), $input);
        } catch (HouseholdException $e) {
            $this->refuse($e);

            return $this->form(null, $input, ['name' => [$e->getMessage()]], 422);
        }
        $this->audit($request, 'household.created', 'household', $id, ['name' => $input->name]);
        $this->flash('success', 'households.created');

        return Response::redirect('/admin/households/' . $id . '/edit');
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $this->requireFeature();
        $household = $this->household($params);

        return $this->form($household, HouseholdInput::fromRecord($household), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $this->requireFeature();
        $household = $this->household($params);
        $input = HouseholdInput::fromRequest($request);
        $validator = Validator::make([], []);
        $input->validate($validator);
        if ($validator->fails()) {
            return $this->form($household, $input, $validator->errors(), 422);
        }
        $expected = (string) $request->input('row_version', '');
        try {
            $changes = $this->services->householdService()->update(
                $this->user($request),
                (int) $household['id'],
                $input,
                preg_match('/^[1-9]\d{0,9}$/', $expected) === 1 ? (int) $expected : 0,
            );
        } catch (HouseholdException $e) {
            $this->refuse($e);
            if ($e->getMessage() === 'households.error.conflict') {
                // Keep the stale version: saving again must not silently overwrite.
                return $this->form(['row_version' => $expected] + $household, $input, [], 409, $e->getMessage());
            }

            return $this->form($household, $input, ['name' => [$e->getMessage()]], 422);
        }
        if ($changes !== []) {
            $this->audit($request, 'household.updated', 'household', (int) $household['id'], ['changes' => $changes]);
        }
        $this->flash('success', 'households.saved');

        return Response::redirect('/admin/households/' . (int) $household['id'] . '/edit');
    }

    /**
     * @param array<string, string> $params
     */
    public function destroy(Request $request, array $params): Response
    {
        $this->requireFeature();
        $household = $this->household($params);
        try {
            $this->services->householdService()->delete($this->user($request), (int) $household['id']);
        } catch (HouseholdException $e) {
            $this->refuse($e);
            $this->flash('error', $e->getMessage());

            return Response::redirect('/admin/households/' . (int) $household['id'] . '/edit');
        }
        $this->audit($request, 'household.deleted', 'household', (int) $household['id'], ['name' => (string) $household['name']]);
        $this->flash('success', 'households.deleted');

        return Response::redirect('/admin/households');
    }

    /**
     * @param array<string, string> $params
     */
    public function addMember(Request $request, array $params): Response
    {
        $this->requireFeature();
        $household = $this->household($params);
        $raw = (string) $request->input('user_id', '');
        if (preg_match('/^[1-9]\d{0,9}$/', $raw) !== 1) {
            $this->flash('error', 'households.error.user_not_found');

            return $this->back($household);
        }
        try {
            $this->services->householdService()->addMember($this->user($request), (int) $household['id'], (int) $raw);
        } catch (HouseholdException $e) {
            $this->refuse($e);
            $this->flash('error', $e->getMessage());

            return $this->back($household);
        }
        // The member is recorded by id only (names and e-mail addresses stay out of the audit trail).
        $this->audit($request, 'household.member_added', 'household', (int) $household['id'], ['member_user_id' => (int) $raw]);
        $this->flash('success', 'households.members.added');

        return $this->back($household);
    }

    /**
     * @param array<string, string> $params
     */
    public function removeMember(Request $request, array $params): Response
    {
        $this->requireFeature();
        $household = $this->household($params);
        $userId = $this->id($params, 'user');
        try {
            $ownedCopies = $this->services->householdService()->removeMember($this->user($request), (int) $household['id'], $userId);
        } catch (HouseholdException $e) {
            $this->refuse($e);
            $this->flash('error', $e->getMessage());

            return $this->back($household);
        }
        $this->audit($request, 'household.member_removed', 'household', (int) $household['id'], ['member_user_id' => $userId, 'owned_copies' => $ownedCopies]);
        $this->flash('success', 'households.members.removed');

        return $this->back($household);
    }

    /**
     * Not found while the feature is disabled, whatever the caller's role.
     */
    private function requireFeature(): void
    {
        if (!$this->services->householdsEnabled()) {
            throw HttpException::notFound();
        }
    }

    private function refuse(HouseholdException $e): void
    {
        match ($e->getMessage()) {
            'households.error.forbidden' => throw HttpException::forbidden(),
            'households.error.not_found' => throw HttpException::notFound(),
            default => null,
        };
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function household(array $params): array
    {
        return $this->found($this->services->households()->find($this->id($params)));
    }

    /**
     * @param array<string, mixed> $household
     */
    private function back(array $household): Response
    {
        return Response::redirect('/admin/households/' . (int) $household['id'] . '/edit#members');
    }

    /**
     * @param array<string, mixed>|null   $household
     * @param array<string, list<string>> $errors
     */
    private function form(?array $household, HouseholdInput $input, array $errors, int $status = 200, ?string $refusal = null): Response
    {
        $id = $household === null ? null : (int) $household['id'];

        return $this->render('admin/households/form', [
            'page_title' => $household === null ? 'households.new' : 'households.edit_title',
            'household' => $household,
            'input' => $input,
            'errors' => $errors,
            'refusal' => $refusal,
            'members' => $id === null ? [] : $this->services->households()->members($id),
            'candidates' => $id === null ? [] : $this->services->households()->candidates($id),
        ], $status);
    }
}
