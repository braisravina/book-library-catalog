<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Admin;

use DigitalLibrary\Catalogue\Repository\TreeRepository;
use DigitalLibrary\Http\Controllers\Controller;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Validation\Validator;

/**
 * Shared administration of hierarchical vocabularies (categories, locations).
 * Route guards restrict these actions to category.manage / location.manage.
 */
abstract class TreeAdminController extends Controller
{
    abstract protected function repository(): TreeRepository;

    /** URL prefix, e.g. "/admin/categories". */
    abstract protected function basePath(): string;

    /** Translation/template namespace, e.g. "categories". */
    abstract protected function key(): string;

    /** Audit entity type, e.g. "category". */
    abstract protected function entity(): string;

    /**
     * @param array<string, string> $old
     */
    abstract protected function persist(?int $id, ?int $parentId, array $old, int $userId): int;

    abstract protected function usage(int $id): int;

    /**
     * @return array<string, list<string>> extra validation rules
     */
    protected function extraRules(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    protected function defaults(): array
    {
        return ['name' => '', 'parent_id' => '', 'description' => ''];
    }

    public function index(Request $request): Response
    {
        return $this->page($this->defaults(), [], null);
    }

    public function store(Request $request): Response
    {
        return $this->save($request, null);
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $node = $this->found($this->repository()->find($this->id($params)));
        $old = [];
        foreach (array_keys($this->defaults()) as $field) {
            $old[$field] = $node[$field] === null ? '' : (string) $node[$field];
        }

        return $this->page($old, [], $node);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $node = $this->found($this->repository()->find($this->id($params)));

        return $this->save($request, $node);
    }

    /**
     * @param array<string, string> $params
     */
    public function destroy(Request $request, array $params): Response
    {
        $node = $this->found($this->repository()->find($this->id($params)));
        $id = (int) $node['id'];
        if ($this->repository()->childCount($id) > 0) {
            $this->flash('error', 'admin.tree.delete_has_children');
        } elseif ($this->usage($id) > 0) {
            $this->flash('error', 'admin.' . $this->key() . '.delete_in_use');
        } else {
            $this->repository()->delete($id);
            $this->audit($request, $this->entity() . '.deleted', $this->entity(), $id, ['name' => $node['name']]);
            $this->flash('success', 'admin.tree.deleted');
        }

        return Response::redirect($this->basePath());
    }

    /**
     * @param array<string, mixed>|null $node
     */
    private function save(Request $request, ?array $node): Response
    {
        $old = [];
        foreach (array_keys($this->defaults()) as $field) {
            $old[$field] = trim((string) $request->input($field, ''));
        }
        $validator = Validator::make($old, [
            'name' => ['required', 'max:150', 'no_control'],
            'parent_id' => ['integer'],
            'description' => ['max:' . ($this->entity() === 'location' ? 500 : 1000)],
        ] + $this->extraRules());

        $parentId = $old['parent_id'] === '' ? null : (int) $old['parent_id'];
        $id = $node === null ? null : (int) $node['id'];
        if ($parentId !== null && !$validator->fails()) {
            if (!$this->repository()->exists($parentId)) {
                $validator->addError('parent_id', 'validation.in');
            } elseif ($id !== null && $this->repository()->wouldCreateCycle($id, $parentId)) {
                $validator->addError('parent_id', 'validation.parent_cycle');
            }
        }
        if (!$validator->fails() && $this->repository()->siblingNameTaken($parentId, $old['name'], $id)) {
            $validator->addError('name', 'validation.name_taken');
        }
        if ($validator->fails()) {
            return $this->page($old, $validator->errors(), $node, 422);
        }

        $savedId = $this->persist($id, $parentId, $old, $this->user($request)->id);
        $this->audit($request, $this->entity() . ($id === null ? '.created' : '.updated'), $this->entity(), $savedId, ['name' => $old['name'], 'parent_id' => $parentId]);
        $this->flash('success', $id === null ? 'admin.tree.created' : 'catalogue.saved');

        return Response::redirect($this->basePath());
    }

    /**
     * @param array<string, string>       $old
     * @param array<string, list<string>> $errors
     * @param array<string, mixed>|null   $node
     */
    private function page(array $old, array $errors, ?array $node, int $status = 200): Response
    {
        $tree = $this->repository()->tree();
        foreach ($tree as &$row) {
            $row['usage'] = $this->usage((int) $row['id']);
        }
        unset($row);
        // A node cannot be moved under itself or its descendants.
        $excluded = $node === null ? [] : $this->repository()->descendantIds((int) $node['id']);

        return $this->render('admin/tree', [
            'page_title' => 'admin.' . $this->key() . '.title',
            'key' => $this->key(),
            'base_path' => $this->basePath(),
            'tree' => $tree,
            'parent_options' => array_values(array_filter($tree, static fn (array $r): bool => !in_array((int) $r['id'], $excluded, true))),
            'old' => $old,
            'errors' => $errors,
            'node' => $node,
        ], $status);
    }
}
