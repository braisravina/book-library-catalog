<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Catalogue;

use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Permission;
use DigitalLibrary\Validation\Validator;

final class PublisherController extends CatalogueBaseController
{
    use MergesRecords;

    public function index(Request $request): Response
    {
        return $this->render('catalogue/publishers_index', [
            'page_title' => 'catalogue.publishers.title',
            'publishers' => $this->services->publishers()->allWithCounts(),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $publisher = $this->found($this->services->publishers()->find($this->id($params)));

        return $this->render('catalogue/publisher_show', [
            'page_title' => null,
            'title' => $publisher['name'],
            'publisher' => $publisher,
            'editions' => $this->services->publishers()->editions((int) $publisher['id']),
            'can_edit' => $this->can($this->user($request), Permission::CatalogueEditAny),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $publisher = $this->found($this->services->publishers()->find($this->id($params)));

        return $this->form($publisher, (string) $publisher['name'], []);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $publisher = $this->found($this->services->publishers()->find($this->id($params)));
        $name = trim((string) $request->input('name', ''));
        $validator = Validator::make(['name' => $name], ['name' => ['required', 'max:255', 'no_control']]);
        if (!$validator->fails() && !$this->services->publishers()->rename((int) $publisher['id'], $name)) {
            $validator->addError('name', 'validation.name_taken');
        }
        if ($validator->fails()) {
            return $this->form($publisher, $name, $validator->errors(), 422);
        }
        $this->audit($request, 'publisher.updated', 'publisher', (int) $publisher['id'], ['name' => $name]);
        $this->flash('success', 'catalogue.saved');

        return Response::redirect('/publishers/' . $publisher['id']);
    }

    /**
     * @param array<string, string> $params
     */
    public function merge(Request $request, array $params): Response
    {
        $publisher = $this->found($this->services->publishers()->find($this->id($params)));
        $repo = $this->services->publishers();

        return $this->mergeRecord($request, $publisher, 'publisher', '/publishers', $repo->find(...), $repo->mergeInto(...));
    }

    /**
     * @param array<string, string> $params
     */
    public function destroy(Request $request, array $params): Response
    {
        $publisher = $this->found($this->services->publishers()->find($this->id($params)));
        $repo = $this->services->publishers();

        return $this->deleteUnused($request, $publisher, 'publisher', '/publishers', $repo->usage((int) $publisher['id']), $repo->delete(...));
    }

    /**
     * @param array<string, mixed>        $publisher
     * @param array<string, list<string>> $errors
     */
    private function form(array $publisher, string $name, array $errors, int $status = 200): Response
    {
        return $this->render('catalogue/publisher_form', [
            'page_title' => 'catalogue.publishers.edit_title',
            'publisher' => $publisher,
            'name' => $name,
            'errors' => $errors,
            'merge_options' => $this->services->publishers()->options((int) $publisher['id']),
            'usage' => $this->services->publishers()->usage((int) $publisher['id']),
        ], $status);
    }
}
