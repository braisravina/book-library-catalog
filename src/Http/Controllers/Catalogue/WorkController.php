<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Catalogue;

use DigitalLibrary\Catalogue\DuplicateIsbnException;
use DigitalLibrary\Catalogue\EditConflictException;
use DigitalLibrary\Catalogue\Input\EditionInput;
use DigitalLibrary\Catalogue\Input\WorkInput;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
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
        $work = $this->found($this->services->works()->find($this->id($params)));
        $id = (int) $work['id'];

        return $this->render('catalogue/work_show', [
            'page_title' => null,
            'title' => $work['title'],
            'work' => $work,
            'authors' => $this->services->works()->authors($id),
            'categories' => $this->services->categories()->forWork($id),
            'editions' => $this->services->works()->editions($id),
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
        $work = $this->found($this->services->works()->find($this->id($params)));

        return $this->editionForm($work, EditionInput::blank('', (string) $work['title']), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function storeEdition(Request $request, array $params): Response
    {
        $work = $this->found($this->services->works()->find($this->id($params)));
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

            return $this->editionForm($work, $input, $validator->errors(), 409, $e->existingEditionId);
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
        $work = $this->found($this->services->works()->find($this->id($params)));
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
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function editableWork(Request $request, array $params): array
    {
        $work = $this->found($this->services->works()->find($this->id($params)));
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
    private function editionForm(array $work, EditionInput $input, array $errors, int $status = 200, ?int $duplicateId = null): Response
    {
        return $this->render('catalogue/edition_form', [
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
