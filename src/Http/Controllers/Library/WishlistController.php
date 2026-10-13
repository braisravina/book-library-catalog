<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Library;

use DigitalLibrary\Http\Controllers\Controller;
use DigitalLibrary\Http\HttpException;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Validation\Validator;
use DigitalLibrary\Wishlist\WishlistException;
use DigitalLibrary\Wishlist\WishlistInput;
use DigitalLibrary\Wishlist\WishlistRepository;

/**
 * Private, per-user wishlists. Every entry is visible to and changeable by
 * its owner only; there is deliberately no administrator override, and other
 * users' entries are answered with 404 so their existence is not revealed.
 *
 * Wishlist contents are personal and not catalogue changes, so they are not
 * written to the audit log.
 */
final class WishlistController extends Controller
{
    private const FILTERS = ['wanted', 'acquired', 'archived', 'all'];

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $status = (string) $request->query('status', 'wanted');
        $status = in_array($status, self::FILTERS, true) ? $status : 'wanted';
        $page = max(1, min(10_000, (int) ($request->query('page') ?? '1')));
        $result = $this->services->wishlist()->forUser($user->id, $status, $page);

        return $this->render('wishlist/index', [
            'page_title' => 'wishlist.title',
            'status' => $status,
            'filters' => self::FILTERS,
            'counts' => $this->services->wishlist()->countsForUser($user->id),
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / WishlistRepository::PER_PAGE)),
        ]);
    }

    /**
     * New entry: blank, or pre-filled from a catalogue edition (?edition=ID).
     */
    public function create(Request $request): Response
    {
        $edition = $this->sourceEdition($request);
        $input = $edition === null
            ? WishlistInput::blank()
            : WishlistInput::fromEdition($edition, $this->authorNames((int) $edition['work_id']));

        return $this->form(null, $input, [], $edition);
    }

    public function store(Request $request): Response
    {
        $user = $this->user($request);
        $edition = $this->sourceEdition($request);
        $input = WishlistInput::fromRequest($request);
        $validator = Validator::make([], []);
        $input->validate($validator);
        if ($validator->fails()) {
            return $this->form(null, $input, $validator->errors(), $edition, 422);
        }
        try {
            $id = $this->services->wishlistService()->add(
                $user,
                $input,
                $edition === null ? null : ['id' => (int) $edition['id'], 'work_id' => (int) $edition['work_id']],
            );
        } catch (WishlistException $e) {
            if ($e->getMessage() === 'wishlist.error.forbidden') {
                throw HttpException::forbidden();
            }

            return $this->form(null, $input, [], $edition, 409, $e->getMessage(), $e->existingId);
        }
        $this->flash('success', 'wishlist.added');

        return Response::redirect('/wishlist#item-' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $item = $this->ownItem($request, $params);

        return $this->form($item, WishlistInput::fromRecord($item), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $item = $this->ownItem($request, $params);
        $input = WishlistInput::fromRequest($request);
        $validator = Validator::make([], []);
        $input->validate($validator);
        if ($validator->fails()) {
            return $this->form($item, $input, $validator->errors(), null, 422);
        }
        try {
            $this->services->wishlistService()->update($this->user($request), (int) $item['id'], $input);
        } catch (WishlistException $e) {
            if ($e->getMessage() === 'wishlist.error.not_found') {
                throw HttpException::notFound();
            }

            return $this->form($item, $input, [], null, 409, $e->getMessage(), $e->existingId);
        }
        $this->flash('success', 'wishlist.updated');

        return Response::redirect('/wishlist?status=' . rawurlencode((string) $item['status']) . '#item-' . (int) $item['id']);
    }

    /**
     * Mark acquired, archive, or restore to wanted.
     *
     * @param array<string, string> $params
     */
    public function status(Request $request, array $params): Response
    {
        $item = $this->ownItem($request, $params);
        $status = (string) $request->input('status', '');
        try {
            $this->services->wishlistService()->setStatus($this->user($request), (int) $item['id'], $status);
        } catch (WishlistException $e) {
            if ($e->getMessage() === 'wishlist.error.not_found') {
                throw HttpException::notFound();
            }
            $this->flash('error', $e->getMessage());

            return Response::redirect('/wishlist?status=' . rawurlencode((string) $item['status']));
        }
        $this->flash('success', 'wishlist.status_changed.' . $status);

        return Response::redirect('/wishlist?status=' . rawurlencode($status) . '#item-' . (int) $item['id']);
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function ownItem(Request $request, array $params): array
    {
        $item = $this->services->wishlist()->find($this->id($params));
        if ($item === null || !$this->services->authorizer()->canManageWishlistItem($this->user($request), (int) $item['user_id'])) {
            throw HttpException::notFound();
        }

        return $item;
    }

    /**
     * The catalogue edition named by ?edition= (GET) or edition_id (POST).
     *
     * @return array<string, mixed>|null
     */
    private function sourceEdition(Request $request): ?array
    {
        $raw = (string) ($request->input('edition_id') ?? $request->query('edition') ?? '');
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^[1-9]\d{0,9}$/', $raw) !== 1) {
            throw HttpException::notFound();
        }

        return $this->found($this->services->editions()->find((int) $raw));
    }

    /**
     * @return list<string>
     */
    private function authorNames(int $workId): array
    {
        return array_values(array_map(
            static fn (array $a): string => (string) $a['name'],
            array_filter($this->services->works()->authors($workId), static fn (array $a): bool => ($a['role'] ?? 'author') === 'author'),
        ));
    }

    /**
     * @param array<string, mixed>|null   $item
     * @param array<string, list<string>> $errors
     * @param array<string, mixed>|null   $edition
     */
    private function form(?array $item, WishlistInput $input, array $errors, ?array $edition = null, int $status = 200, ?string $refusal = null, ?int $existingId = null): Response
    {
        return $this->render('wishlist/form', [
            'page_title' => $item === null ? 'wishlist.new' : 'wishlist.edit',
            'item' => $item,
            'input' => $input,
            'errors' => $errors,
            'edition' => $edition,
            'refusal' => $refusal,
            'existing_id' => $existingId,
            'priorities' => WishlistInput::PRIORITIES,
        ], $status);
    }
}
