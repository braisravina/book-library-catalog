<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Catalogue;

use DigitalLibrary\Auth\User;
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
     * Whether the user may change the edition (and its covers): the editing
     * rule for shared records (Authorizer::canEditSharedRecord()). This is
     * what the pages use to offer the action and what the edit forms
     * answer 403 from; BookService decides again inside the transaction.
     */
    protected function mayEditEdition(User $user, int $editionId, ?int $createdBy): bool
    {
        $authorizer = $this->services->authorizer();
        // Asked only when it can matter: for someone who could edit their own record.
        if (!$authorizer->canEditRecord($user, $createdBy)) {
            return false;
        }

        return $authorizer->canEditSharedRecord($user, $createdBy, $this->services->editions()->heldOutsideView($editionId, $this->visibility()));
    }

    /**
     * Whether the user may change the work (see mayEditEdition()).
     */
    protected function mayEditWork(User $user, int $workId, ?int $createdBy): bool
    {
        $authorizer = $this->services->authorizer();
        if (!$authorizer->canEditRecord($user, $createdBy)) {
            return false;
        }

        return $authorizer->canEditSharedRecord($user, $createdBy, $this->services->works()->sharedBeyondView($workId, $this->visibility()));
    }

    /**
     * Whether to tell the user that a record they catalogued is now part
     * of the shared catalogue and is changed by an administrator. It says
     * only that; never who else holds the book, where, or how many copies.
     */
    protected function sharedRecordNotice(User $user, ?int $createdBy, bool $mayEdit): bool
    {
        return !$mayEdit && $this->services->authorizer()->canEditRecord($user, $createdBy);
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
}
