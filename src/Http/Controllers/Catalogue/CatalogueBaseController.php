<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Catalogue;

use DigitalLibrary\Http\Controllers\Controller;
use DigitalLibrary\Validation\Validator;

abstract class CatalogueBaseController extends Controller
{
    /**
     * Option lists shared by the work/edition/copy forms.
     *
     * @return array<string, mixed>
     */
    protected function formOptions(): array
    {
        return [
            'category_tree' => $this->services->categories()->tree(),
            'location_tree' => $this->services->locations()->tree(),
            'publisher_names' => $this->services->publishers()->names(),
            'author_names' => $this->services->authors()->names(),
        ];
    }

    /**
     * @param list<int> $ids
     */
    protected function validateCategories(array $ids, Validator $validator): void
    {
        foreach ($ids as $id) {
            if (!$this->services->categories()->exists($id)) {
                $validator->addError('categories', 'validation.in');

                return;
            }
        }
    }

    /**
     * The record version a submitted edit form was based on (0 if missing,
     * which never matches and therefore reports a conflict).
     */
    protected function expectedVersion(\DigitalLibrary\Http\Request $request): int
    {
        $value = (string) $request->input('row_version', '');

        return preg_match('/^[1-9]\d{0,9}$/', $value) ? (int) $value : 0;
    }

    protected function validateLocation(?int $locationId, Validator $validator, string $field = 'location_id'): void
    {
        if ($locationId !== null && !$this->services->locations()->exists($locationId)) {
            $validator->addError($field, 'validation.in');
        }
    }
}
