<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Catalogue;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Catalogue\Input\CopyInput;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Permission;
use DigitalLibrary\Validation\Validator;

/**
 * Physical copies. Members manage copies they own and may only create copies
 * owned by themselves; administrators manage any copy and choose any owner.
 */
final class CopyController extends CatalogueBaseController
{
    /**
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        $edition = $this->found($this->services->editions()->find($this->id($params)));

        return $this->form($request, $edition, null, CopyInput::blank($this->user($request)->id), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $edition = $this->found($this->services->editions()->find($this->id($params)));
        $input = CopyInput::fromRequest($request);
        $this->authorizeOwner($user, $input);

        $validator = $this->validate($input, null);
        if ($validator->fails()) {
            return $this->form($request, $edition, null, $input, $validator->errors(), 422);
        }
        $copyId = $this->services->copies()->create((int) $edition['id'], $input->toRecord(), $user->id);
        $this->audit($request, 'copy.created', 'copy', $copyId, ['edition_id' => (int) $edition['id'], 'owner_user_id' => $input->ownerId()]);
        $this->flash('success', 'catalogue.copy.created');

        return Response::redirect('/editions/' . $edition['id']);
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $copy = $this->manageableCopy($request, $params);
        $edition = $this->found($this->services->editions()->find((int) $copy['edition_id']));

        return $this->form($request, $edition, $copy, CopyInput::fromRecord($copy), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $copy = $this->manageableCopy($request, $params);
        $edition = $this->found($this->services->editions()->find((int) $copy['edition_id']));
        $input = CopyInput::fromRequest($request);
        $this->authorizeOwner($user, $input);

        $validator = $this->validate($input, (int) $copy['id']);
        if ($validator->fails()) {
            return $this->form($request, $edition, $copy, $input, $validator->errors(), 422);
        }
        if (!$this->services->copies()->update((int) $copy['id'], $input->toRecord(), $user->id, $this->expectedVersion($request))) {
            // Keep the stale version: saving again must not silently overwrite.
            return $this->form($request, $edition, ['row_version' => $this->expectedVersion($request)] + $copy, $input, [], 409, true);
        }
        $details = ['edition_id' => (int) $copy['edition_id']];
        if ((int) $copy['owner_user_id'] !== $input->ownerId()) {
            $details['owner_changed'] = ['from' => (int) $copy['owner_user_id'], 'to' => $input->ownerId()];
        }
        $this->audit($request, 'copy.updated', 'copy', (int) $copy['id'], $details);
        $this->flash('success', 'catalogue.saved');

        return Response::redirect('/editions/' . $copy['edition_id']);
    }

    /**
     * @param array<string, string> $params
     */
    public function destroy(Request $request, array $params): Response
    {
        $copy = $this->manageableCopy($request, $params);
        $this->services->copies()->delete((int) $copy['id']);
        $this->audit($request, 'copy.deleted', 'copy', (int) $copy['id'], ['edition_id' => (int) $copy['edition_id'], 'owner_user_id' => (int) $copy['owner_user_id']]);
        $this->flash('success', 'catalogue.copy.deleted');

        return Response::redirect('/editions/' . $copy['edition_id']);
    }

    /**
     * Object-level check: the copy must be the user's own unless they may
     * manage any copy.
     *
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function manageableCopy(Request $request, array $params): array
    {
        $copy = $this->found($this->services->copies()->find($this->id($params)));
        $this->authorize($this->services->authorizer()->canManageCopy($this->user($request), (int) $copy['owner_user_id']));

        return $copy;
    }

    /**
     * Assigning a copy to someone else (creating or transferring) requires
     * copy.manage_any.
     */
    private function authorizeOwner(User $user, CopyInput $input): void
    {
        if ($input->ownerId !== (string) $user->id) {
            $this->authorize($this->can($user, Permission::CopyManageAny));
        }
    }

    private function validate(CopyInput $input, ?int $copyId): Validator
    {
        $validator = Validator::make([], []);
        $input->validate($validator);
        $owner = ctype_digit($input->ownerId) ? $this->services->users()->findById((int) $input->ownerId) : null;
        if ($owner === null || !$owner->isActive) {
            $validator->addError('owner_user_id', 'validation.in');
        }
        $this->validateLocation($input->locationId(), $validator);
        if ($input->inventoryCode !== '' && $this->services->copies()->inventoryCodeTaken($input->inventoryCode, $copyId)) {
            $validator->addError('inventory_code', 'validation.inventory_taken');
        }

        return $validator;
    }

    /**
     * @param array<string, mixed>        $edition
     * @param array<string, mixed>|null   $copy
     * @param array<string, list<string>> $errors
     */
    private function form(Request $request, array $edition, ?array $copy, CopyInput $input, array $errors, int $status = 200, bool $conflict = false): Response
    {
        $user = $this->user($request);
        $canChooseOwner = $this->can($user, Permission::CopyManageAny);

        return $this->render('catalogue/copy_form', [
            'page_title' => $copy === null ? 'catalogue.copy.new_title' : 'catalogue.copy.edit_title',
            'edition' => $edition,
            'copy' => $copy,
            'conflict' => $conflict,
            'input' => $input,
            'errors' => $errors,
            'owners' => $canChooseOwner
                ? array_values(array_filter($this->services->users()->all(), static fn (User $u): bool => $u->isActive))
                : [$user],
            'can_choose_owner' => $canChooseOwner,
            'action' => $copy === null ? '/editions/' . $edition['id'] . '/copies' : '/copies/' . $copy['id'],
            'location_tree' => $this->services->locations()->tree(),
        ], $status);
    }
}
