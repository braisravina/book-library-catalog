<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Catalogue;

use DigitalLibrary\Catalogue\DuplicateIsbnException;
use DigitalLibrary\Catalogue\Input\EditionInput;
use DigitalLibrary\Catalogue\Tree;
use DigitalLibrary\Database\Transaction;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Permission;
use DigitalLibrary\Validation\Validator;

final class EditionController extends CatalogueBaseController
{
    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $id = $this->id($params);
        $edition = $this->found($this->services->editions()->find($id));
        $authorizer = $this->services->authorizer();
        $copies = $this->services->copies()->forEdition($id);
        foreach ($copies as &$copy) {
            $copy['can_manage'] = $authorizer->canManageCopy($user, (int) $copy['owner_user_id']);
        }
        unset($copy);

        return $this->render('catalogue/edition_show', [
            'page_title' => null,
            'title' => $edition['title'],
            'edition' => $edition,
            'authors' => $this->services->works()->authors((int) $edition['work_id']),
            'contributors' => $this->services->editions()->contributors($id),
            'categories' => $this->services->categories()->forWork((int) $edition['work_id']),
            'other_editions' => array_values(array_filter(
                $this->services->works()->editions((int) $edition['work_id']),
                static fn (array $e): bool => (int) $e['id'] !== $id,
            )),
            'copies' => $copies,
            'covers' => $this->services->coverRecords()->forEdition($id),
            'location_paths' => Tree::paths($this->services->locations()->all()),
            'can_edit' => $authorizer->canEditRecord($user, $edition['created_by'] === null ? null : (int) $edition['created_by']),
            'can_edit_work' => $authorizer->canEditRecord($user, $edition['work_created_by'] === null ? null : (int) $edition['work_created_by']),
            'can_delete' => $this->can($user, Permission::CatalogueDelete),
            'can_add_copy' => $this->can($user, Permission::CopyManageOwn),
            'max_upload_bytes' => $this->services->config->int('UPLOAD_MAX_BYTES', 5 * 1024 * 1024),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $edition = $this->editableEdition($request, $params);

        return $this->form(
            $edition,
            EditionInput::fromRecord($edition, $this->services->editions()->contributors((int) $edition['id'])),
            [],
        );
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $edition = $this->editableEdition($request, $params);
        $input = EditionInput::fromRequest($request);
        $validator = Validator::make([], []);
        $input->validate($validator);
        if ($validator->fails()) {
            return $this->form($edition, $input, $validator->errors(), 422);
        }

        try {
            $this->services->books()->updateEdition((int) $edition['id'], $input, $this->user($request)->id);
        } catch (DuplicateIsbnException $e) {
            $validator->addError('isbn', 'validation.isbn_taken');

            return $this->form($edition, $input, $validator->errors(), 409, $e->existingEditionId);
        }
        $this->audit($request, 'edition.updated', 'edition', (int) $edition['id']);
        $this->flash('success', 'catalogue.saved');

        return Response::redirect('/editions/' . $edition['id']);
    }

    /**
     * Administrators only (route guard). Refused while physical copies exist.
     *
     * @param array<string, string> $params
     */
    public function destroy(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $edition = $this->found($this->services->editions()->find($id));
        if ($this->services->editions()->copyCount($id) > 0) {
            $this->flash('error', 'catalogue.edition.delete_has_copies');

            return Response::redirect('/editions/' . $id);
        }

        $covers = $this->services->coverRecords()->forEdition($id);
        Transaction::run($this->services->pdo(), function () use ($id, $covers): void {
            foreach ($covers as $cover) {
                $this->services->coverRecords()->delete((int) $cover['id'], $id);
            }
            $this->services->editions()->delete($id);
        });
        foreach ($covers as $cover) {
            $this->services->covers()->delete((string) $cover['storage_key']);
        }
        $this->audit($request, 'edition.deleted', 'edition', $id, ['title' => $edition['title'], 'isbn_13' => $edition['isbn_13']]);
        $this->flash('success', 'catalogue.edition.deleted');

        return Response::redirect('/works/' . $edition['work_id']);
    }

    /**
     * Loads the edition and enforces record-level edit permission.
     *
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function editableEdition(Request $request, array $params): array
    {
        $edition = $this->found($this->services->editions()->find($this->id($params)));
        $this->authorize($this->services->authorizer()->canEditRecord(
            $this->user($request),
            $edition['created_by'] === null ? null : (int) $edition['created_by'],
        ));

        return $edition;
    }

    /**
     * @param array<string, mixed>        $edition
     * @param array<string, list<string>> $errors
     */
    private function form(array $edition, EditionInput $input, array $errors, int $status = 200, ?int $duplicateId = null): Response
    {
        return $this->render('catalogue/edition_form', [
            'page_title' => 'catalogue.edition.edit_title',
            'edition' => $edition,
            'input' => $input,
            'errors' => $errors,
            'duplicate_id' => $duplicateId,
            'action' => '/editions/' . $edition['id'],
            'cancel' => '/editions/' . $edition['id'],
        ] + $this->formOptions(), $status);
    }
}
