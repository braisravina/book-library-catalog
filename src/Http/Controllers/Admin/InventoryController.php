<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Admin;

use DigitalLibrary\Households\TransferException;
use DigitalLibrary\Http\Controllers\Controller;
use DigitalLibrary\Http\HttpException;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;

final class InventoryController extends Controller
{
    private function feature(): void
    {
        if (!$this->services->householdsEnabled()) {
            throw HttpException::notFound();
        }
    }

    private function number(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'unassigned') {
            return null;
        }
        if (!is_string($value) || !preg_match('/^[1-9]\d{0,9}$/D', $value) || (int) $value > 4294967295) {
            throw TransferException::because('input');
        }
        return (int) $value;
    }

    public function index(Request $request): Response
    {
        $this->feature();
        try {
            $source = $request->query('source') === null ? $this->services->households()->defaultId() : $this->number($request->query('source'));
            return $this->overview($request, $source, [], 200, max(0, (int) $request->query('after', '0')));
        } catch (TransferException $e) {
            return $this->overview($request, null, $e->conflicts, 422);
        }
    }

    private function overview(Request $request, ?int $source, array $conflicts = [], int $status = 200, int $after = 0): Response
    {
        return $this->render('admin/inventory', ['page_title' => 'transfers.title', 'conflicts' => $conflicts]
            + $this->services->inventoryTransfers()->overview($this->user($request)->id, $source, $after), $status);
    }

    public function preview(Request $request): Response
    {
        $this->feature();
        try {
            if ($request->input('source') === null || $request->input('destination') === null) {
                throw TransferException::because('input');
            }
            $rootValue = $request->rawInput('root');
            $kindValue = $request->rawInput('kind');
            if (($rootValue !== null && !is_string($rootValue)) || ($kindValue !== null && !is_string($kindValue))) {
                throw TransferException::because('input');
            }
            $root = explode(':', $rootValue ?? '', 2);
            $input = ['kind' => $request->input('kind', 'copies'), 'source' => $this->number($request->rawInput('source')),
                'destination' => $this->number($request->rawInput('destination')), 'location' => $this->number($request->rawInput('location')),
                'parent' => $this->number($request->rawInput('parent')), 'owner' => $this->number($request->rawInput('owner')),
                'root' => $this->number($root[0]), 'root_version' => $this->number($root[1] ?? null),
                'reason' => $request->input('reason', ''), 'selected' => $request->strictInputList('selected')];
            if ($request->input('intent') === 'dry-run') {
                $plan = $this->services->inventoryTransfers()->preview($this->user($request)->id, $input);
                return $this->render('admin/transfer', ['page_title' => 'transfers.preview', 'operation' => null, 'plan' => $plan, 'conflicts' => []]);
            }
            $id = $this->services->inventoryTransfers()->plan($this->user($request)->id, $input);
            return Response::redirect('/admin/inventory/transfers/' . $id);
        } catch (TransferException $e) {
            return $this->overview($request, null, $e->conflicts, 422);
        }
    }

    public function show(Request $request, array $params): Response
    {
        $this->feature();
        $operation = $this->services->inventoryTransfers()->show($this->user($request)->id, $this->id($params));
        return $this->render('admin/transfer', ['page_title' => 'transfers.preview', 'operation' => $operation, 'plan' => $operation['plan'], 'conflicts' => []]);
    }

    public function apply(Request $request, array $params): Response
    {
        $this->feature();
        $id = $this->id($params);
        try {
            if ($request->input('confirmed') !== 'yes') {
                throw TransferException::because('confirmation');
            }
            $this->services->inventoryTransfers()->apply($this->user($request)->id, $id, (string) $request->input('digest', ''));
            return Response::redirect('/admin/inventory/transfers/' . $id);
        } catch (TransferException $e) {
            $operation = $this->services->inventoryTransfers()->show($this->user($request)->id, $id);
            return $this->render('admin/transfer', ['page_title' => 'transfers.preview', 'operation' => $operation, 'plan' => $operation['plan'], 'conflicts' => $e->conflicts], 409);
        }
    }

    public function cancel(Request $request, array $params): Response
    {
        $this->feature();
        $this->services->inventoryTransfers()->cancel($this->user($request)->id, $this->id($params));
        return Response::redirect('/admin/inventory/transfers/' . $this->id($params));
    }
}
