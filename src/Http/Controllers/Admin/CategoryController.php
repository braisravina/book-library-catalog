<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Admin;

use DigitalLibrary\Catalogue\Repository\TreeRepository;

final class CategoryController extends TreeAdminController
{
    protected function repository(): TreeRepository
    {
        return $this->services->categories();
    }

    protected function basePath(): string
    {
        return '/admin/categories';
    }

    protected function key(): string
    {
        return 'categories';
    }

    protected function entity(): string
    {
        return 'category';
    }

    protected function usage(int $id): int
    {
        return $this->services->categories()->usage($id);
    }

    protected function persist(?int $id, ?int $parentId, array $old, int $userId): int
    {
        $description = $old['description'] === '' ? null : $old['description'];
        if ($id === null) {
            return $this->services->categories()->create($parentId, $old['name'], $description);
        }
        $this->services->categories()->update($id, $parentId, $old['name'], $description);

        return $id;
    }
}
