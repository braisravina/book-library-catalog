<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Catalogue;

use DigitalLibrary\Catalogue\DuplicateIsbnException;
use DigitalLibrary\Catalogue\EditConflictException;
use DigitalLibrary\Catalogue\Input\EditionInput;
use DigitalLibrary\Catalogue\Input\WorkInput;
use DigitalLibrary\Catalogue\Isbn;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Metadata\MetadataPrefill;
use DigitalLibrary\Security\Permission;
use DigitalLibrary\Validation\Validator;

final class WorkController extends CatalogueBaseController
{
    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $work = $this->visibleWork($params);
        $id = (int) $work['id'];

        return $this->render('catalogue/work_show', [
            'page_title' => null,
            'title' => $work['title'],
            'work' => $work,
            'authors' => $this->services->works()->authors($id),
            'categories' => $this->services->categories()->forWork($id),
            'editions' => $this->services->works()->editions($id, $this->visibility()),
            'can_edit' => $this->services->authorizer()->canEditRecord($user, $work['created_by'] === null ? null : (int) $work['created_by']),
            'can_add_edition' => $this->can($user, Permission::CatalogueCreate),
            'can_delete' => $this->can($user, Permission::CatalogueDelete),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $work = $this->editableWork($request, $params);
        $id = (int) $work['id'];
        $authors = array_map(static fn (array $a): array => ['name' => $a['name'], 'role' => $a['role']], $this->services->works()->authors($id));

        return $this->form($work, WorkInput::fromRecord($work, $authors, $this->services->works()->categoryIds($id)), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $work = $this->editableWork($request, $params);
        $input = WorkInput::fromRequest($request);
        $validator = Validator::make([], []);
        $input->validate($validator);
        $this->validateCategories($input->categoryIds, $validator);
        if ($validator->fails()) {
            return $this->form($work, $input, $validator->errors(), 422);
        }
        try {
            $this->services->books()->updateWork((int) $work['id'], $input, $this->user($request)->id, $this->expectedVersion($request));
        } catch (EditConflictException) {
            return $this->form(['row_version' => $this->expectedVersion($request)] + $work, $input, [], 409, true);
        }
        $this->audit($request, 'work.updated', 'work', (int) $work['id']);
        $this->flash('success', 'catalogue.saved');

        return Response::redirect('/works/' . $work['id']);
    }

    /**
     * @param array<string, string> $params
     */
    public function createEdition(Request $request, array $params): Response
    {
        $work = $this->visibleWork($params);
        $input = EditionInput::blank('', (string) $work['title']);
        $prefilled = false;
        // "Add as a new edition" from the add-a-book review: carry the ISBN
        // over and pre-fill edition fields from the cached lookup only (no
        // outbound request). The user reviews everything before saving.
        $isbn = Isbn::tryParse(substr((string) $request->query('isbn', ''), 0, 32));
        if ($isbn !== null) {
            $input = EditionInput::blank($isbn->isbn13, (string) $work['title']);
            $metadata = $this->services->metadata();
            $cached = $metadata->enabled() ? $metadata->lookup($isbn, $this->user($request)->id, network: false) : null;
            if ($cached !== null && $cached->found()) {
                $edition = MetadataPrefill::inputs($cached->metadata, $isbn)[1];
                $input = new EditionInput(
                    $edition->title !== '' ? $edition->title : (string) $work['title'],
                    $edition->subtitle, $edition->publisher, $edition->publicationYear, '', $edition->language,
                    $edition->pageCount, '', $isbn->isbn13, '', '', [],
                );
                $prefilled = true;
            }
        }

        return $this->editionForm($work, $input, [], prefilled: $prefilled);
    }

    /**
     * @param array<string, string> $params
     */
    public function storeEdition(Request $request, array $params): Response
    {
        $work = $this->visibleWork($params);
        $input = EditionInput::fromRequest($request);
        $validator = Validator::make([], []);
        $input->validate($validator);
        if ($validator->fails()) {
            return $this->editionForm($work, $input, $validator->errors(), 422);
        }
        try {
            $editionId = $this->services->books()->addEdition((int) $work['id'], $input, $this->user($request)->id);
        } catch (DuplicateIsbnException $e) {
            $validator->addError('isbn', 'validation.isbn_taken');

            return $this->editionForm($work, $input, $validator->errors(), 409, $this->linkable($e->existingEditionId));
        }
        $this->audit($request, 'edition.created', 'edition', $editionId, ['work_id' => (int) $work['id'], 'isbn_13' => $input->parsedIsbn()?->isbn13]);
        $this->flash('success', 'catalogue.add.created');

        return Response::redirect('/editions/' . $editionId);
    }

    /**
     * Administrators only (route guard). Refused while editions exist.
     *
     * @param array<string, string> $params
     */
    public function destroy(Request $request, array $params): Response
    {
        $work = $this->visibleWork($params);
        $id = (int) $work['id'];
        if ($this->services->works()->editionCount($id) > 0) {
            $this->flash('error', 'catalogue.work.delete_has_editions');

            return Response::redirect('/works/' . $id);
        }
        $this->services->works()->delete($id);
        $this->audit($request, 'work.deleted', 'work', $id, ['title' => $work['title']]);
        $this->flash('success', 'catalogue.work.deleted');

        return Response::redirect('/books');
    }

    /**
     * The work, or 404 when it does not exist or is outside the user's view.
     *
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function visibleWork(array $params): array
    {
        return $this->found($this->services->works()->find($this->id($params), $this->visibility()));
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function editableWork(Request $request, array $params): array
    {
        $work = $this->visibleWork($params);
        $this->authorize($this->services->authorizer()->canEditRecord(
            $this->user($request),
            $work['created_by'] === null ? null : (int) $work['created_by'],
        ));

        return $work;
    }

    /**
     * @param array<string, mixed>        $work
     * @param array<string, list<string>> $errors
     */
    private function form(array $work, WorkInput $input, array $errors, int $status = 200, bool $conflict = false): Response
    {
        return $this->render('catalogue/work_form', [
            'page_title' => 'catalogue.work.edit_title',
            'work' => $work,
            'conflict' => $conflict,
            'input' => $input,
            'errors' => $errors,
        ] + $this->formOptions(), $status);
    }

    /**
     * @param array<string, mixed>        $work
     * @param array<string, list<string>> $errors
     */
    private function editionForm(array $work, EditionInput $input, array $errors, int $status = 200, ?int $duplicateId = null, bool $prefilled = false): Response
    {
        return $this->render('catalogue/edition_form', [
            'prefilled' => $prefilled,
            'page_title' => 'catalogue.edition.new_title',
            'edition' => ['work_id' => $work['id'], 'work_title' => $work['title']],
            'conflict' => false,
            'input' => $input,
            'errors' => $errors,
            'duplicate_id' => $duplicateId,
            'action' => '/works/' . $work['id'] . '/editions',
            'cancel' => '/works/' . $work['id'],
        ] + $this->formOptions(), $status);
    }
}
