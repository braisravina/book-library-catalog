<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Catalogue;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Catalogue\DuplicateIsbnException;
use DigitalLibrary\Catalogue\Input\CopyInput;
use DigitalLibrary\Catalogue\Input\EditionInput;
use DigitalLibrary\Catalogue\Input\WorkInput;
use DigitalLibrary\Catalogue\Isbn;
use DigitalLibrary\Catalogue\SearchCriteria;
use DigitalLibrary\Catalogue\Vocabulary;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Metadata\BookMetadata;
use DigitalLibrary\Metadata\MetadataPrefill;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Permission;
use DigitalLibrary\Validation\Validator;

/**
 * Browsing/search and the "add a book" flow:
 *   1. ISBN step: an ISBN is validated and checked for duplicates first;
 *   2. duplicate: offer the existing record or "add another copy";
 *   3. full form: creates work + edition (+ optional first copy).
 */
final class CatalogueController extends CatalogueBaseController
{
    public function index(Request $request): Response
    {
        $criteria = SearchCriteria::fromRequest($request);
        $result = $this->services->search()->search($criteria);

        return $this->render('catalogue/index', [
            'page_title' => 'catalogue.title',
            'criteria' => $criteria,
            'result' => $result,
            'category_tree' => $this->services->categories()->tree(),
            'location_tree' => $this->services->locations()->tree(),
            'owners' => array_values(array_filter($this->services->users()->all(), static fn (User $u): bool => $u->isActive)),
            'languages' => array_values(array_intersect(Vocabulary::LANGUAGES, $this->services->editions()->languagesInUse())),
        ]);
    }

    public function create(Request $request): Response
    {
        if ($request->query('manual') === '1') {
            return $this->bookForm($request, WorkInput::blank(), EditionInput::blank());
        }
        $rawIsbn = trim((string) $request->query('isbn', ''));
        if ($rawIsbn === '') {
            return $this->render('catalogue/isbn_step', ['page_title' => 'catalogue.add.title', 'isbn' => '', 'error' => null]);
        }
        $isbn = Isbn::tryParse($rawIsbn);
        if ($isbn === null) {
            return $this->render('catalogue/isbn_step', ['page_title' => 'catalogue.add.title', 'isbn' => $rawIsbn, 'error' => 'validation.isbn'], 422);
        }
        $existing = $this->services->books()->existingEditionForIsbn($isbn);
        if ($existing !== null) {
            return $this->duplicate($request, $existing, 200);
        }

        // Not catalogued yet: pre-fill from the metadata providers (if enabled).
        // Everything is shown for review; nothing is saved until the user submits.
        $work = WorkInput::blank();
        $edition = EditionInput::blank($isbn->isbn13);
        $lookup = null;
        $metadata = $this->services->metadata();
        if ($metadata->enabled()) {
            $result = $metadata->lookup($isbn, $this->user($request)->id);
            if ($result->found()) {
                [$work, $edition] = MetadataPrefill::inputs($result->metadata, $isbn);
            }
            $lookup = [
                'found' => $result->found(),
                'sources' => $result->sources,
                'failures' => $result->failures,
                'record_id' => $result->found() ? $result->primaryRecordId : null,
                'cover_available' => $result->metadata->coverUrl !== '',
                'import_cover' => true,
            ];
        }

        return $this->bookForm($request, $work, $edition, lookup: $lookup);
    }

    public function store(Request $request): Response
    {
        $user = $this->user($request);
        $work = WorkInput::fromRequest($request);
        $edition = EditionInput::fromRequest($request, $work->title, $work->subtitle);
        $addCopy = $request->input('add_copy') === '1' && $this->can($user, Permission::CopyManageOwn);
        $copy = $addCopy ? new CopyInput(
            (string) $user->id,
            trim((string) $request->input('copy_location_id', '')),
            '',
            trim((string) $request->input('copy_condition', '')),
            'available',
            false, '', '', '', '', '',
        ) : null;

        $validator = Validator::make([], []);
        $work->validate($validator);
        $edition->validate($validator);
        $this->validateCategories($work->categoryIds, $validator);
        if ($copy !== null) {
            $copyValidator = Validator::make([], []);
            $copy->validate($copyValidator);
            foreach ($copyValidator->errors() as $field => $errors) {
                foreach ($errors as $error) {
                    $validator->addError('copy_' . $field, $error);
                }
            }
            $this->validateLocation($copy->locationId(), $validator, 'copy_location_id');
        }

        // A cached provider record is only honoured for the same ISBN.
        $recordInput = (string) $request->input('metadata_record_id', '');
        $providerData = null;
        $recordId = null;
        if (preg_match('/^[1-9]\d{0,9}$/', $recordInput) && $edition->parsedIsbn() !== null) {
            $providerData = $this->services->metadata()->cachedRecord((int) $recordInput, $edition->parsedIsbn());
            $recordId = $providerData === null ? null : (int) $recordInput;
        }
        $importCover = $request->input('import_cover') === '1' && $providerData !== null && $providerData->coverUrl !== '';
        $lookup = $recordId === null ? null : [
            'found' => true, 'sources' => [], 'failures' => [], 'record_id' => $recordId,
            'cover_available' => $providerData->coverUrl !== '', 'import_cover' => $importCover,
        ];

        // Books without an ISBN: warn about same-title records unless the
        // user has confirmed this is a different book.
        $titleMatches = [];
        if ($edition->parsedIsbn() === null && $edition->isbn === '' && $request->input('confirm_new') !== '1' && !$validator->fails()) {
            $titleMatches = $this->services->editions()->titleMatches($work->title);
        }
        if ($validator->fails() || $titleMatches !== []) {
            return $this->bookForm($request, $work, $edition, $validator->errors(), $titleMatches, $addCopy, $copy, 422, $lookup);
        }

        try {
            $ids = $this->services->books()->createBook($work, $edition, $copy, $user->id, $recordId);
        } catch (DuplicateIsbnException $e) {
            return $this->duplicate($request, $e->existingEditionId, 409);
        }

        $this->audit($request, 'work.created', 'work', $ids['work_id'], ['title' => $work->title]);
        $this->audit($request, 'edition.created', 'edition', $ids['edition_id'], ['isbn_13' => $edition->parsedIsbn()?->isbn13]);
        if ($ids['copy_id'] !== null) {
            $this->audit($request, 'copy.created', 'copy', $ids['copy_id'], ['edition_id' => $ids['edition_id']]);
        }
        if ($importCover && $providerData instanceof BookMetadata) {
            $coverId = $this->services->coverImporter()->import($ids['edition_id'], $providerData, $user->id);
            if ($coverId !== null) {
                $this->audit($request, 'cover.imported', 'cover', $coverId, ['edition_id' => $ids['edition_id'], 'metadata_record_id' => $recordId]);
            } else {
                $this->flash('info', 'metadata.cover_failed');
            }
        }
        $this->flash('success', 'catalogue.add.created');

        return Response::redirect('/editions/' . $ids['edition_id']);
    }

    private function duplicate(Request $request, int $editionId, int $status): Response
    {
        $edition = $this->found($this->services->editions()->find($editionId));

        return $this->render('catalogue/duplicate', [
            'page_title' => 'catalogue.duplicate.title',
            'edition' => $edition,
            'authors' => $this->services->works()->authors((int) $edition['work_id']),
            'copy_count' => $this->services->editions()->copyCount($editionId),
            'can_add_copy' => $this->can($this->user($request), Permission::CopyManageOwn),
        ], $status);
    }

    /**
     * @param array<string, list<string>>       $errors
     * @param list<array<string, mixed>>        $titleMatches
     */
    private function bookForm(
        Request $request,
        WorkInput $work,
        EditionInput $edition,
        array $errors = [],
        array $titleMatches = [],
        bool $addCopy = true,
        ?CopyInput $copy = null,
        int $status = 200,
        ?array $lookup = null,
    ): Response {
        return $this->render('catalogue/book_form', [
            'page_title' => 'catalogue.add.title',
            'work' => $work,
            'edition' => $edition,
            'errors' => $errors,
            'title_matches' => $titleMatches,
            'add_copy' => $addCopy,
            'copy' => $copy,
            'can_add_copy' => $this->can($this->user($request), Permission::CopyManageOwn),
            'lookup' => $lookup,
        ] + $this->formOptions(), $status);
    }
}
