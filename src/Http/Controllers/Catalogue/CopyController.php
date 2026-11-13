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
 *
 * Every copy belongs to a household, decided by Households\CopyHousehold
 * (never read straight from the request): the default household while the
 * household feature is disabled, an explicit and authorized choice when it
 * is enabled. Household membership gives no right to edit a copy: editing
 * stays with the copy's owner and administrators.
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
        $householdId = $this->household($user, $input, null, $validator);
        if ($validator->fails()) {
            return $this->form($request, $edition, null, $input, $validator->errors(), 422);
        }
        $copyId = $this->services->copies()->create((int) $edition['id'], ['household_id' => $householdId] + $input->toRecord(), $user->id);
        $this->audit($request, 'copy.created', 'copy', $copyId, ['edition_id' => (int) $edition['id'], 'owner_user_id' => $input->ownerId(), 'household_id' => $householdId]);
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
        $householdId = $this->household($user, $input, $copy, $validator);
        if ($validator->fails()) {
            return $this->form($request, $edition, $copy, $input, $validator->errors(), 422);
        }
        if (!$this->services->copies()->update((int) $copy['id'], ['household_id' => $householdId] + $input->toRecord(), $user->id, $this->expectedVersion($request))) {
            // Keep the stale version: saving again must not silently overwrite.
            return $this->form($request, $edition, ['row_version' => $this->expectedVersion($request)] + $copy, $input, [], 409, true);
        }
        $details = ['edition_id' => (int) $copy['edition_id']];
        if ((int) $copy['owner_user_id'] !== $input->ownerId()) {
            $details['owner_changed'] = ['from' => (int) $copy['owner_user_id'], 'to' => $input->ownerId()];
        }
        $previousHousehold = $copy['household_id'] === null ? null : (int) $copy['household_id'];
        if ($previousHousehold !== $householdId) {
            $details['household_changed'] = ['from' => $previousHousehold, 'to' => $householdId];
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
        // Lending history is kept: such copies are withdrawn, not deleted.
        if ($this->services->loans()->countForCopy((int) $copy['id']) > 0) {
            $this->flash('error', 'loans.error.copy_has_history');

            return Response::redirect('/copies/' . (int) $copy['id']);
        }
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

    /**
     * The household the copy will belong to, with every refusal recorded as
     * a validation error. Checked only when something is being set: the
     * household must be one the user may use, the copy's owner must be able
     * to reach it, and its location must belong to the same household.
     *
     * @param array<string, mixed>|null $copy the copy being edited
     */
    private function household(User $user, CopyInput $input, ?array $copy, Validator $validator): ?int
    {
        $resolver = $this->services->copyHousehold();
        [$householdId, $error] = $resolver->resolve($user, $this->services->householdScope($user), $input->householdId, $copy);
        if ($error !== null) {
            $validator->addError('household_id', $error);

            return $householdId;
        }
        if (!$resolver->enabled() || $householdId === null) {
            return $householdId;
        }
        $errors = $validator->errors();
        $previous = $copy === null || $copy['household_id'] === null ? null : (int) $copy['household_id'];
        $ownerChanged = $copy === null || (int) $copy['owner_user_id'] !== $input->ownerId();
        if (($previous !== $householdId || $ownerChanged) && !isset($errors['owner_user_id'])) {
            $owner = $this->services->users()->findById($input->ownerId());
            if ($owner !== null && !$resolver->ownerFits($owner, $householdId)) {
                $validator->addError('owner_user_id', 'households.validation.owner_not_member');
            }
        }
        $locationId = $input->locationId();
        if ($locationId !== null && !isset($errors['location_id'])) {
            $locationHousehold = $this->services->locations()->householdId($locationId);
            if ($locationHousehold !== null && $locationHousehold !== $householdId) {
                $validator->addError('location_id', 'households.validation.location_household');
            }
        }

        return $householdId;
    }

    private function currentStatus(int $copyId): ?string
    {
        $copy = $this->services->copies()->find($copyId);

        return $copy === null ? null : (string) $copy['status'];
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
        // "On loan" is driven by the loans table: it is set by lending and
        // cleared by recording the return, never edited by hand.
        $activeLoan = $copyId === null ? null : $this->services->loans()->activeForCopy($copyId);
        if ($activeLoan !== null && $input->status !== 'on_loan') {
            $validator->addError('status', 'loans.validation.status_active_loan');
        } elseif ($activeLoan === null && $input->status === 'on_loan' && ($copyId === null || $this->currentStatus($copyId) !== 'on_loan')) {
            $validator->addError('status', 'loans.validation.status_use_lending');
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
            'household_options' => $this->services->copyHousehold()->options($user, $this->services->householdScope($user), $copy),
            'action' => $copy === null ? '/editions/' . $edition['id'] . '/copies' : '/copies/' . $copy['id'],
            'location_tree' => $this->services->locations()->tree(),
        ], $status);
    }
}
