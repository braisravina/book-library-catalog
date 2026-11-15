<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Admin;

use DigitalLibrary\Catalogue\Repository\TreeRepository;
use DigitalLibrary\Catalogue\Vocabulary;
use DigitalLibrary\Households\LocationException;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Validation\Validator;

/**
 * Location administration (location.manage: administrators only).
 *
 * Each household has its own location tree (phase H3): a root is created
 * for a household chosen explicitly, a child belongs to its parent's
 * household, and a location is never moved under another household's
 * tree. The rules live in Households\LocationService, which applies them
 * inside one guarded transaction; this controller checks the form and
 * shows the trees, one per household.
 *
 * While the household feature is disabled the page is the single tree it
 * always was.
 */
final class LocationController extends TreeAdminController
{
    protected function repository(): TreeRepository
    {
        return $this->services->locations();
    }

    protected function basePath(): string
    {
        return '/admin/locations';
    }

    protected function key(): string
    {
        return 'locations';
    }

    protected function entity(): string
    {
        return 'location';
    }

    protected function usage(int $id): int
    {
        return $this->services->locations()->usage($id);
    }

    protected function defaults(): array
    {
        return ['name' => '', 'parent_id' => '', 'kind' => 'other', 'description' => ''];
    }

    protected function extraRules(): array
    {
        return ['kind' => ['required', 'in:' . implode(',', Vocabulary::LOCATION_KINDS)]];
    }

    public function store(Request $request): Response
    {
        return $this->saveLocation($request, null);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        return $this->saveLocation($request, $this->found($this->repository()->find($this->id($params))));
    }

    /**
     * @param array<string, string> $params
     */
    public function destroy(Request $request, array $params): Response
    {
        $node = $this->found($this->repository()->find($this->id($params)));
        try {
            $deleted = $this->services->locationService()->delete($this->user($request)->id, (int) $node['id']);
        } catch (LocationException $e) {
            $this->flash('error', $e->getMessage());

            return Response::redirect($this->basePath());
        }
        $this->flash('success', 'admin.tree.deleted');

        return Response::redirect($this->basePath());
    }

    /**
     * The household choice for a new root location: every household, by
     * id => name (empty while the household feature is disabled).
     */
    protected function pageExtras(?array $node): array
    {
        if (!$this->services->locationService()->enabled()) {
            return [];
        }
        $households = [];
        foreach ($this->services->households()->all() as $household) {
            $households[(string) $household['id']] = (string) $household['name'];
        }

        return ['households' => $households];
    }

    /**
     * @param array<string, mixed>|null $node the location being edited
     */
    private function saveLocation(Request $request, ?array $node): Response
    {
        $old = [];
        foreach (array_keys($this->defaults()) as $field) {
            $old[$field] = trim((string) $request->input($field, ''));
        }
        $old['household_id'] = trim((string) $request->input('household_id', ''));
        $validator = Validator::make($old, [
            'name' => ['required', 'max:150', 'no_control'],
            'parent_id' => ['integer'],
            'description' => ['max:500'],
        ] + $this->extraRules());
        if ($validator->fails()) {
            return $this->page($old, $validator->errors(), $node, 422);
        }

        $service = $this->services->locationService();
        $userId = $this->user($request)->id;
        $parentId = $old['parent_id'] === '' ? null : (int) $old['parent_id'];
        $description = $old['description'] === '' ? null : $old['description'];
        try {
            if ($node === null) {
                $saved = $service->create($userId, $parentId, $old['household_id'], $old['name'], $old['kind'], $description);
                $id = $saved['id'];
            } else {
                $id = (int) $node['id'];
                $saved = $service->update($userId, $id, $parentId, $old['name'], $old['kind'], $description, (int) $request->input('row_version', '0'));
            }
        } catch (LocationException $e) {
            if ($e->field === 'row_version') {
                $node['row_version'] = (int) $request->input('row_version', '0');
                return $this->page($old, ['name' => [$e->getMessage()]], $node, 409);
            }
            return $this->page($old, [($e->field !== '' ? $e->field : 'name') => [$e->getMessage()]], $node, 422);
        }
        $this->flash('success', $node === null ? 'admin.tree.created' : 'catalogue.saved');

        return Response::redirect($this->basePath());
    }

    protected function persist(?int $id, ?int $parentId, array $old, int $userId): int
    {
        throw new \LogicException('Locations are saved through Households\LocationService.');
    }
}
