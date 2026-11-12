<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Catalogue;

use DigitalLibrary\Database\Transaction;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;

/**
 * Shared merge/delete handling for shared catalogue entities (authors and
 * publishers). Route guards restrict these actions to catalogue.edit_any.
 */
trait MergesRecords
{
    /**
     * @param array<string, mixed>        $source
     * @param callable(int): ?array        $find     loads a record by id
     * @param callable(int, int): void     $merge    merges source id into target id
     */
    private function mergeRecord(Request $request, array $source, string $entity, string $basePath, callable $find, callable $merge): Response
    {
        $sourceId = (int) $source['id'];
        $raw = (string) $request->input('target_id', '');
        $targetId = preg_match('/^[1-9]\d{0,9}$/', $raw) ? (int) $raw : 0;
        $target = $targetId > 0 && $targetId !== $sourceId ? $find($targetId) : null;
        if ($target === null) {
            $this->flash('error', 'catalogue.merge.invalid_target');

            return Response::redirect("{$basePath}/{$sourceId}/edit");
        }

        Transaction::run($this->services->pdo(), static fn () => $merge($sourceId, $targetId));
        $this->audit($request, "{$entity}.merged", $entity, $targetId, [
            'merged_id' => $sourceId,
            'merged_name' => (string) $source['name'],
            'into_name' => (string) $target['name'],
        ]);
        $this->flash('success', 'catalogue.merge.done');

        return Response::redirect("{$basePath}/{$targetId}");
    }

    /**
     * @param array<string, mixed> $record
     */
    private function deleteUnused(Request $request, array $record, string $entity, string $basePath, int $usage, callable $delete): Response
    {
        $id = (int) $record['id'];
        if ($usage > 0) {
            $this->flash('error', 'catalogue.merge.delete_in_use');

            return Response::redirect("{$basePath}/{$id}/edit");
        }
        $delete($id);
        $this->audit($request, "{$entity}.deleted", $entity, $id, ['name' => (string) $record['name']]);
        $this->flash('success', 'catalogue.merge.deleted');

        return Response::redirect($basePath);
    }
}
