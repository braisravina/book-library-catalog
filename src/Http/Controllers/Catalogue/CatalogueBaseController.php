<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Catalogue;

use DigitalLibrary\Http\Controllers\Controller;
use DigitalLibrary\Validation\Validator;

abstract class CatalogueBaseController extends Controller
{
    /**
     * Option lists shared by the work/edition/copy forms: locations of the
     * user's households and the names of authors and publishers they can
     * already see (never the whole shared vocabulary).
     *
     * @return array<string, mixed>
     */
    protected function formOptions(): array
    {
        $visibility = $this->visibility();

        return [
            'category_tree' => $this->services->categories()->tree(),
            'location_tree' => $this->services->locations()->visibleTree($visibility),
            'publisher_names' => $this->services->publishers()->names($visibility),
            'author_names' => $this->services->authors()->names($visibility),
        ];
    }

    /**
     * The edition that already holds a submitted ISBN, for the "already
     * used" error: named (and linked) only when the user may see it.
     */
    protected function linkable(int $editionId): ?int
    {
        return $this->services->editions()->isVisible($editionId, $this->visibility()) ? $editionId : null;
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

    /**
     * A location may be chosen only when it is one the user can see: a
     * location of another household is refused exactly like one that does
     * not exist.
     */
    protected function validateLocation(?int $locationId, Validator $validator, string $field = 'location_id'): void
    {
        if ($locationId === null) {
            return;
        }
        $visible = array_map(static fn (array $l): int => (int) $l['id'], $this->services->locations()->visible($this->visibility()));
        if (!in_array($locationId, $visible, true)) {
            $validator->addError($field, 'validation.in');
        }
    }
}
