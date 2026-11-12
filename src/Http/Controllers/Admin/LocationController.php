<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Admin;

use DigitalLibrary\Catalogue\Repository\TreeRepository;
use DigitalLibrary\Catalogue\Vocabulary;

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

    protected function persist(?int $id, ?int $parentId, array $old, int $userId): int
    {
        $description = $old['description'] === '' ? null : $old['description'];
        if ($id === null) {
            return $this->services->locations()->create($parentId, $old['name'], $old['kind'], $description, $userId);
        }
        $this->services->locations()->update($id, $parentId, $old['name'], $old['kind'], $description);

        return $id;
    }
}
