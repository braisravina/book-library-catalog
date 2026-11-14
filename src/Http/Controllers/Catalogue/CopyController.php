<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Catalogue;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Catalogue\CopyHasHistoryException;
use DigitalLibrary\Catalogue\CopyValidationException;
use DigitalLibrary\Catalogue\EditConflictException;
use DigitalLibrary\Catalogue\Input\CopyInput;
use DigitalLibrary\Catalogue\Isbn;
use DigitalLibrary\Http\HttpException;
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
 *
 * The controller answers 404/403 from what the request can see and checks
 * the form; every write then goes through Catalogue\CopyService, which
 * repeats the authorization inside the transaction, under the locks of
 * Households\WriteGuard, and applies the rules that depend on the
 * database (owner, household, location, inventory code, loans).
 */
final class CopyController extends CatalogueBaseController
{
    /**
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        $edition = $this->visibleEdition($this->id($params));

        return $this->form($request, $edition, null, CopyInput::blank($this->user($request)->id), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $edition = $this->visibleEdition($this->id($params));
        $input = CopyInput::fromRequest($request);
        $this->authorizeOwner($user, $input);

        $errors = $this->formErrors($input);
        if ($errors !== []) {
            return $this->form($request, $edition, null, $input, $errors, 422);
        }
        try {
            $created = $this->services->copyService()->create($user->id, (int) $edition['id'], $input);
        } catch (CopyValidationException $e) {
            return $this->form($request, $edition, null, $input, $e->errors, 422);
        }
        $this->audit($request, 'copy.created', 'copy', $created['copy_id'], ['edition_id' => $created['edition_id'], 'owner_user_id' => $input->ownerId(), 'household_id' => $created['household_id']]);
        $this->flash('success', 'catalogue.copy.created');

        return Response::redirect('/editions/' . $edition['id']);
    }

    /**
     * "Add your copy" of a book that is already catalogued, by ISBN
     * (phase H3). ISBNs are unique across the shared catalogue, so a book
     * another household holds cannot be entered again; instead the user
     * adds a copy to the existing edition. The edition is found from the
     * ISBN on the server, never from an id in the request, and nothing but
     * its bibliographic description is shown: no copies, owners,
     * households or locations, and no link to the record.
     */
    public function createByIsbn(Request $request): Response
    {
        $isbn = $this->requestedIsbn((string) $request->query('isbn', ''));
        $editionId = $this->services->books()->existingEditionForIsbn($isbn);
        if ($editionId === null) {
            // Not catalogued (any more): the ordinary "add a book" flow.
            return Response::redirect($this->services->view()->url('/books/new', ['isbn' => $isbn->isbn13]));
        }
        if ($this->services->editions()->isVisible($editionId, $this->visibility())) {
            return Response::redirect('/editions/' . $editionId . '/copies/new');
        }

        return $this->isbnForm($request, $isbn, $editionId, CopyInput::blank($this->user($request)->id), []);
    }

    public function storeByIsbn(Request $request): Response
    {
        $user = $this->user($request);
        $isbn = $this->requestedIsbn((string) $request->input('isbn', ''));
        $editionId = $this->services->books()->existingEditionForIsbn($isbn) ?? throw HttpException::notFound();
        $input = CopyInput::fromRequest($request);
        $this->authorizeOwner($user, $input);

        $errors = $this->formErrors($input);
        if ($errors !== []) {
            return $this->isbnForm($request, $isbn, $editionId, $input, $errors, 422);
        }
        try {
            $created = $this->services->copyService()->createByIsbn($user->id, $isbn, $input);
        } catch (CopyValidationException $e) {
            return $this->isbnForm($request, $isbn, $editionId, $input, $e->errors, 422);
        }
        $this->audit($request, 'copy.created', 'copy', $created['copy_id'], ['edition_id' => $created['edition_id'], 'owner_user_id' => $input->ownerId(), 'household_id' => $created['household_id'], 'via' => 'isbn']);
        $this->flash('success', 'catalogue.copy.created');

        // The copy is in one of the user's households, so the edition is now in their view.
        return Response::redirect('/editions/' . $created['edition_id']);
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $copy = $this->manageableCopy($request, $params);
        $edition = $this->visibleEdition((int) $copy['edition_id']);

        return $this->form($request, $edition, $copy, CopyInput::fromRecord($copy), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $copy = $this->manageableCopy($request, $params);
        $edition = $this->visibleEdition((int) $copy['edition_id']);
        $input = CopyInput::fromRequest($request);
        $this->authorizeOwner($user, $input);

        $errors = $this->formErrors($input);
        if ($errors !== []) {
            return $this->form($request, $edition, $copy, $input, $errors, 422);
        }
        try {
            $saved = $this->services->copyService()->update($user->id, (int) $copy['id'], $input, $this->expectedVersion($request));
        } catch (CopyValidationException $e) {
            return $this->form($request, $edition, $copy, $input, $e->errors, 422);
        } catch (EditConflictException) {
            // Keep the stale version: saving again must not silently overwrite.
            return $this->form($request, $edition, ['row_version' => $this->expectedVersion($request)] + $copy, $input, [], 409, true);
        }
        $details = ['edition_id' => $saved['edition_id']];
        if ($saved['owner_from'] !== $saved['owner_to']) {
            $details['owner_changed'] = ['from' => $saved['owner_from'], 'to' => $saved['owner_to']];
        }
        if ($saved['household_from'] !== $saved['household_to']) {
            $details['household_changed'] = ['from' => $saved['household_from'], 'to' => $saved['household_to']];
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
        try {
            $deleted = $this->services->copyService()->delete($this->user($request)->id, (int) $copy['id']);
        } catch (CopyHasHistoryException $e) {
            // Lending history is kept: such copies are withdrawn, not deleted.
            $this->flash('error', $e->getMessage());

            return Response::redirect('/copies/' . (int) $copy['id']);
        }
        $this->audit($request, 'copy.deleted', 'copy', (int) $copy['id'], ['edition_id' => $deleted['edition_id'], 'owner_user_id' => $deleted['owner_user_id'], 'household_id' => $deleted['household_id']]);
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
        // 404 before 403: a copy outside the user's households does not exist for them.
        $copy = $this->found($this->services->copies()->find($this->id($params), $this->visibility()));
        $this->authorize($this->services->authorizer()->canManageCopy($this->user($request), (int) $copy['owner_user_id']));

        return $copy;
    }

    /**
     * The edition a copy belongs to, or 404 when it is outside the user's view.
     *
     * @return array<string, mixed>
     */
    private function visibleEdition(int $id): array
    {
        return $this->found($this->services->editions()->find($id, $this->visibility()));
    }

    /**
     * A submitted ISBN, normalised; anything that is not an ISBN is 404.
     */
    private function requestedIsbn(string $raw): Isbn
    {
        return Isbn::tryParse(mb_substr(trim($raw), 0, 32)) ?? throw HttpException::notFound();
    }

    /**
     * Assigning a copy to someone else (creating or transferring) requires
     * copy.manage_any (checked again by CopyService).
     */
    private function authorizeOwner(User $user, CopyInput $input): void
    {
        if ($input->ownerId !== (string) $user->id) {
            $this->authorize($this->can($user, Permission::CopyManageAny));
        }
    }

    /**
     * Errors of the submitted form itself (formats and lengths). Whatever
     * depends on the database is checked by CopyService in the write
     * transaction.
     *
     * @return array<string, list<string>>
     */
    private function formErrors(CopyInput $input): array
    {
        $validator = Validator::make([], []);
        $input->validate($validator);

        return $validator->errors();
    }

    /**
     * @param array<string, mixed>        $edition
     * @param array<string, mixed>|null   $copy
     * @param array<string, list<string>> $errors
     */
    private function form(Request $request, array $edition, ?array $copy, CopyInput $input, array $errors, int $status = 200, bool $conflict = false): Response
    {
        return $this->render('catalogue/copy_form', [
            'page_title' => $copy === null ? 'catalogue.copy.new_title' : 'catalogue.copy.edit_title',
            'edition' => $edition,
            'edition_url' => '/editions/' . (int) $edition['id'],
            'isbn' => null,
            'copy' => $copy,
            'conflict' => $conflict,
            'input' => $input,
            'errors' => $errors,
            'action' => $copy === null ? '/editions/' . $edition['id'] . '/copies' : '/copies/' . $copy['id'],
        ] + $this->formChoices($request, $copy), $status);
    }

    /**
     * The form for a copy of an edition that is not in the user's view:
     * the shared bibliographic description only, addressed by ISBN.
     *
     * @param array<string, list<string>> $errors
     */
    private function isbnForm(Request $request, Isbn $isbn, int $editionId, CopyInput $input, array $errors, int $status = 200): Response
    {
        return $this->render('catalogue/copy_form', [
            'page_title' => 'catalogue.copy.new_title',
            'edition' => $this->found($this->services->editions()->bibliographic($editionId)),
            'edition_url' => null,
            'isbn' => $isbn->isbn13,
            'copy' => null,
            'conflict' => false,
            'input' => $input,
            'errors' => $errors,
            'action' => '/books/copies',
        ] + $this->formChoices($request, null), $status);
    }

    /**
     * Owners, households and locations the form offers. Locations are
     * those of the user's households, each marked with its household so
     * the form shows only the ones of the household chosen.
     *
     * @param array<string, mixed>|null $copy
     *
     * @return array<string, mixed>
     */
    private function formChoices(Request $request, ?array $copy): array
    {
        $user = $this->user($request);
        $canChooseOwner = $this->can($user, Permission::CopyManageAny);

        return [
            'owners' => $canChooseOwner
                ? array_values(array_filter($this->services->users()->all(), static fn (User $u): bool => $u->isActive))
                : [$user],
            'can_choose_owner' => $canChooseOwner,
            'household_options' => $this->services->copyHousehold()->options($user, $this->services->householdScope($user), $copy),
            'location_tree' => $this->services->locations()->visibleTree($this->visibility()),
        ];
    }
}
