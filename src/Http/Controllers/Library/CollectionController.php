<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Library;

use DigitalLibrary\Collections\CollectionException;
use DigitalLibrary\Collections\CollectionInput;
use DigitalLibrary\Collections\CollectionRepository;
use DigitalLibrary\Http\Controllers\Controller;
use DigitalLibrary\Http\HttpException;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Permission;
use DigitalLibrary\Validation\Validator;

/**
 * Personal and shared collections of catalogue editions.
 *
 * Private collections are visible to their owner and to administrators
 * holding collection.manage_any; shared ones to every user who can view the
 * catalogue. Viewing never implies editing: changes need
 * Authorizer::canManageCollection(). A collection the user may not see is
 * answered with 404 so its existence is not revealed.
 */
final class CollectionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $canOwn = $this->can($user, Permission::CollectionManageOwn);
        $canAll = $this->can($user, Permission::CollectionManageAny);
        $scopes = array_keys(array_filter(['mine' => $canOwn, 'shared' => true, 'all' => $canAll]));
        $scope = (string) $request->query('scope', $scopes[0]);
        $scope = in_array($scope, $scopes, true) ? $scope : $scopes[0];
        $page = max(1, min(10_000, (int) ($request->query('page') ?? '1')));
        $result = $this->services->collections()->list($scope, $user->id, $page);

        return $this->render('collections/index', [
            'page_title' => 'collections.title',
            'scope' => $scope,
            'scopes' => $scopes,
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / CollectionRepository::PER_PAGE)),
            'can_create' => $canOwn,
            'user_id' => $user->id,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $collection = $this->visible($request, $params);
        $page = max(1, min(10_000, (int) ($request->query('page') ?? '1')));
        $items = $this->services->collections()->items((int) $collection['id'], $page);

        return $this->render('collections/show', [
            'page_title' => null,
            'title' => (string) $collection['name'],
            'collection' => $collection,
            'items' => $items['rows'],
            'total' => $items['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($items['total'] / CollectionRepository::ITEMS_PER_PAGE)),
            'offset' => ($page - 1) * CollectionRepository::ITEMS_PER_PAGE,
            'can_manage' => $this->services->authorizer()->canManageCollection($this->user($request), (int) $collection['owner_user_id']),
            'is_owner' => (int) $collection['owner_user_id'] === $this->user($request)->id,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null, CollectionInput::blank(), []);
    }

    public function store(Request $request): Response
    {
        $input = CollectionInput::fromRequest($request);
        $validator = Validator::make([], []);
        $input->validate($validator);
        if ($validator->fails()) {
            return $this->form(null, $input, $validator->errors(), 422);
        }
        try {
            $id = $this->services->collectionService()->create($this->user($request), $input);
        } catch (CollectionException $e) {
            if ($e->getMessage() === 'collections.error.forbidden') {
                throw HttpException::forbidden();
            }

            return $this->form(null, $input, ['name' => [$e->getMessage()]], 422);
        }
        $this->audit($request, 'collection.created', 'collection', $id, ['visibility' => $input->visibility]);
        $this->flash('success', 'collections.created');

        return Response::redirect('/collections/' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $collection = $this->manageable($request, $params);

        return $this->form($collection, CollectionInput::fromRecord($collection), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $collection = $this->manageable($request, $params);
        $input = CollectionInput::fromRequest($request);
        $validator = Validator::make([], []);
        $input->validate($validator);
        if ($validator->fails()) {
            return $this->form($collection, $input, $validator->errors(), 422);
        }
        try {
            $this->services->collectionService()->update($this->user($request), (int) $collection['id'], $input);
        } catch (CollectionException $e) {
            $this->refuse($e);

            return $this->form($collection, $input, ['name' => [$e->getMessage()]], 422);
        }
        $this->audit($request, 'collection.updated', 'collection', (int) $collection['id'], [
            'owner_user_id' => (int) $collection['owner_user_id'],
            'visibility' => $input->visibility,
        ]);
        $this->flash('success', 'collections.updated');

        return Response::redirect('/collections/' . (int) $collection['id']);
    }

    /**
     * Deletion requires an explicit confirmation checkbox (works without JavaScript).
     *
     * @param array<string, string> $params
     */
    public function destroy(Request $request, array $params): Response
    {
        $collection = $this->manageable($request, $params);
        if ($request->input('confirm') !== '1') {
            $this->flash('error', 'collections.delete.confirm_required');

            return Response::redirect('/collections/' . (int) $collection['id'] . '/edit');
        }
        try {
            $this->services->collectionService()->delete($this->user($request), (int) $collection['id']);
        } catch (CollectionException $e) {
            $this->refuse($e);
            throw $e;
        }
        $this->audit($request, 'collection.deleted', 'collection', (int) $collection['id'], [
            'owner_user_id' => (int) $collection['owner_user_id'],
            'items' => (int) $collection['item_count'],
        ]);
        $this->flash('success', 'collections.deleted');

        return Response::redirect('/collections');
    }

    /**
     * @param array<string, string> $params
     */
    public function addItem(Request $request, array $params): Response
    {
        $collection = $this->manageable($request, $params);
        $raw = (string) $request->input('edition_id', '');
        $edition = preg_match('/^[1-9]\d{0,9}$/', $raw) === 1 ? $this->services->editions()->find((int) $raw) : null;
        if ($edition === null) {
            throw HttpException::notFound();
        }
        try {
            $this->services->collectionService()->addItem($this->user($request), (int) $collection['id'], (int) $edition['id']);
        } catch (CollectionException $e) {
            $this->refuse($e);
            $this->flash('error', $e->getMessage());

            return Response::redirect('/editions/' . (int) $edition['id']);
        }
        $this->audit($request, 'collection.item_added', 'collection', (int) $collection['id'], ['edition_id' => (int) $edition['id']]);
        $this->flash('success', 'collections.item_added');

        return Response::redirect($request->input('return') === 'collection' ? '/collections/' . (int) $collection['id'] : '/editions/' . (int) $edition['id']);
    }

    /**
     * Edition page form: the collection is chosen in a select, so it arrives in the body.
     */
    public function addItemFromEdition(Request $request): Response
    {
        return $this->addItem($request, ['id' => (string) $request->input('collection_id', '')]);
    }

    /**
     * @param array<string, string> $params
     */
    public function removeItem(Request $request, array $params): Response
    {
        $collection = $this->manageable($request, $params);
        $editionId = $this->id($params, 'edition');
        try {
            $this->services->collectionService()->removeItem($this->user($request), (int) $collection['id'], $editionId);
        } catch (CollectionException $e) {
            $this->refuse($e);
            throw HttpException::notFound();
        }
        $this->audit($request, 'collection.item_removed', 'collection', (int) $collection['id'], ['edition_id' => $editionId]);
        $this->flash('success', 'collections.item_removed');

        return Response::redirect('/collections/' . (int) $collection['id']);
    }

    /**
     * @param array<string, string> $params
     */
    public function moveItem(Request $request, array $params): Response
    {
        $collection = $this->manageable($request, $params);
        $editionId = $this->id($params, 'edition');
        try {
            $this->services->collectionService()->move($this->user($request), (int) $collection['id'], $editionId, (string) $request->input('direction', ''));
        } catch (CollectionException $e) {
            $this->refuse($e);
            if ($e->getMessage() === 'collections.error.not_in') {
                throw HttpException::notFound();
            }
            $this->flash('error', $e->getMessage());
        }
        $page = max(1, min(10_000, (int) ($request->input('page') ?? '1')));

        return Response::redirect($this->services->view()->url('/collections/' . (int) $collection['id'], ['page' => $page > 1 ? $page : null]) . '#item-' . $editionId);
    }

    /**
     * Maps authorization and existence refusals from the service.
     */
    private function refuse(CollectionException $e): void
    {
        match ($e->getMessage()) {
            'collections.error.forbidden' => throw HttpException::forbidden(),
            'collections.error.not_found' => throw HttpException::notFound(),
            default => null,
        };
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function visible(Request $request, array $params): array
    {
        $collection = $this->services->collections()->find($this->id($params));
        if ($collection === null || !$this->services->authorizer()->canViewCollection($this->user($request), (int) $collection['owner_user_id'], (string) $collection['visibility'])) {
            throw HttpException::notFound();
        }

        return $collection;
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function manageable(Request $request, array $params): array
    {
        $collection = $this->visible($request, $params);
        $this->authorize($this->services->authorizer()->canManageCollection($this->user($request), (int) $collection['owner_user_id']));

        return $collection;
    }

    /**
     * @param array<string, mixed>|null   $collection
     * @param array<string, list<string>> $errors
     */
    private function form(?array $collection, CollectionInput $input, array $errors, int $status = 200): Response
    {
        return $this->render('collections/form', [
            'page_title' => $collection === null ? 'collections.new' : 'collections.edit',
            'collection' => $collection,
            'input' => $input,
            'errors' => $errors,
            'visibilities' => CollectionInput::VISIBILITIES,
        ], $status);
    }
}
