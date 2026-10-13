<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Catalogue;

use DigitalLibrary\Database\Transaction;
use DigitalLibrary\Http\HttpException;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Upload\UploadException;

/**
 * Cover images: uploaded through CoverImageProcessor (validated and
 * re-encoded), stored outside the web root, and served only to
 * authenticated users with catalogue.view, by numeric id.
 */
final class CoverController extends CatalogueBaseController
{
    /**
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        $edition = $this->found($this->services->editions()->find($this->id($params)));
        $this->authorize($this->services->authorizer()->canEditRecord(
            $this->user($request),
            $edition['created_by'] === null ? null : (int) $edition['created_by'],
        ));
        $editionId = (int) $edition['id'];

        try {
            $image = $this->services->covers()->store($request->file('cover') ?? ['error' => UPLOAD_ERR_NO_FILE]);
        } catch (UploadException $e) {
            $this->flash('error', $e->getMessage());

            return Response::redirect('/editions/' . $editionId . '#covers');
        }

        try {
            $coverId = Transaction::run(
                $this->services->pdo(),
                fn (): int => $this->services->coverRecords()->addPrimary($editionId, $image, $this->user($request)->id),
            );
        } catch (\Throwable $e) {
            // Never leave orphaned files behind when the database write fails.
            $this->services->covers()->delete($image->storageKey);
            throw $e;
        }
        $this->audit($request, 'cover.uploaded', 'cover', $coverId, ['edition_id' => $editionId, 'sha256' => $image->sha256]);
        $this->flash('success', 'catalogue.cover.uploaded');

        return Response::redirect('/editions/' . $editionId . '#covers');
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $cover = $this->found($this->services->coverRecords()->find($this->id($params)));
        $thumbnail = $request->query('size') === 'thumb';
        $path = $this->services->covers()->path((string) $cover['storage_key'], $thumbnail);
        if ($path === null) {
            throw HttpException::notFound();
        }
        $etag = '"' . substr((string) $cover['sha256'], 0, 32) . ($thumbnail ? '-t' : '') . '"';
        $headers = [
            'Cache-Control' => 'private, max-age=86400',
            'ETag' => $etag,
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Disposition' => 'inline; filename="cover-' . (int) $cover['id'] . '.jpg"',
        ];
        if ($request->header('If-None-Match') === $etag) {
            return new Response('', 304, $headers);
        }
        $response = Response::binary((string) file_get_contents($path), 'image/jpeg');
        foreach ($headers as $name => $value) {
            $response->setHeader($name, $value);
        }

        return $response;
    }

    /**
     * @param array<string, string> $params
     */
    public function destroy(Request $request, array $params): Response
    {
        $cover = $this->found($this->services->coverRecords()->find($this->id($params)));
        $editionId = (int) $cover['edition_id'];
        $edition = $this->found($this->services->editions()->find($editionId));
        $this->authorize($this->services->authorizer()->canEditRecord(
            $this->user($request),
            $edition['created_by'] === null ? null : (int) $edition['created_by'],
        ));

        Transaction::run($this->services->pdo(), fn () => $this->services->coverRecords()->delete((int) $cover['id'], $editionId));
        $this->services->covers()->delete((string) $cover['storage_key']);
        $this->audit($request, 'cover.deleted', 'cover', (int) $cover['id'], ['edition_id' => $editionId]);
        $this->flash('success', 'catalogue.cover.deleted');

        return Response::redirect('/editions/' . $editionId . '#covers');
    }
}
