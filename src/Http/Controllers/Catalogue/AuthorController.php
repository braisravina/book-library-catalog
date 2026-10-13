<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Catalogue;

use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Permission;
use DigitalLibrary\Validation\Validator;

/**
 * Authors are shared catalogue entities created implicitly from work and
 * edition forms. Editing them affects every record, so it is admin-only.
 */
final class AuthorController extends CatalogueBaseController
{
    use MergesRecords;

    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $query = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $page = max(1, min(10_000, (int) ($request->query('page') ?? '1')));
        $result = $this->services->authors()->paginate($query, $page, self::PER_PAGE);

        return $this->render('catalogue/authors_index', [
            'page_title' => 'catalogue.authors.title',
            'query' => $query,
            'rows' => $result['rows'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'total' => $result['total'],
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $author = $this->found($this->services->authors()->find($this->id($params)));

        return $this->render('catalogue/author_show', [
            'page_title' => null,
            'title' => $author['name'],
            'author' => $author,
            'works' => $this->services->authors()->works((int) $author['id']),
            'contributions' => $this->services->authors()->contributions((int) $author['id']),
            'can_edit' => $this->can($this->user($request), Permission::CatalogueEditAny),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $author = $this->found($this->services->authors()->find($this->id($params)));
        $old = array_map(static fn ($v): string => $v === null ? '' : (string) $v, $author);

        return $this->form($author, $old, []);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $author = $this->found($this->services->authors()->find($this->id($params)));
        $old = [];
        foreach (['name', 'sort_name', 'birth_year', 'death_year', 'notes'] as $field) {
            $old[$field] = trim((string) $request->input($field, ''));
        }
        $year = 'between:-3000,' . ((int) gmdate('Y') + 1);
        $validator = Validator::make($old, [
            'name' => ['required', 'max:255', 'no_control'],
            'sort_name' => ['required', 'max:255', 'no_control'],
            'birth_year' => [$year],
            'death_year' => [$year],
            'notes' => ['max:5000'],
        ]);
        if ($validator->fails()) {
            return $this->form($author, $old, $validator->errors(), 422);
        }
        $this->services->authors()->update(
            (int) $author['id'],
            $old['name'],
            $old['sort_name'],
            $old['birth_year'] === '' ? null : (int) $old['birth_year'],
            $old['death_year'] === '' ? null : (int) $old['death_year'],
            $old['notes'] === '' ? null : $old['notes'],
        );
        $this->audit($request, 'author.updated', 'author', (int) $author['id'], ['name' => $old['name']]);
        $this->flash('success', 'catalogue.saved');

        return Response::redirect('/authors/' . $author['id']);
    }

    /**
     * Merges this author into the chosen target author.
     *
     * @param array<string, string> $params
     */
    public function merge(Request $request, array $params): Response
    {
        $author = $this->found($this->services->authors()->find($this->id($params)));
        $repo = $this->services->authors();

        return $this->mergeRecord($request, $author, 'author', '/authors', $repo->find(...), $repo->mergeInto(...));
    }

    /**
     * Deletes an author that no work or edition credits.
     *
     * @param array<string, string> $params
     */
    public function destroy(Request $request, array $params): Response
    {
        $author = $this->found($this->services->authors()->find($this->id($params)));
        $repo = $this->services->authors();

        return $this->deleteUnused($request, $author, 'author', '/authors', $repo->usage((int) $author['id']), $repo->delete(...));
    }

    /**
     * @param array<string, mixed>        $author
     * @param array<string, string>       $old
     * @param array<string, list<string>> $errors
     */
    private function form(array $author, array $old, array $errors, int $status = 200): Response
    {
        return $this->render('catalogue/author_form', [
            'page_title' => 'catalogue.authors.edit_title',
            'author' => $author,
            'old' => $old,
            'errors' => $errors,
            'merge_options' => $this->services->authors()->options((int) $author['id']),
            'usage' => $this->services->authors()->usage((int) $author['id']),
        ], $status);
    }
}
