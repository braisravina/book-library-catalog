<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Library;

use DigitalLibrary\Export\CatalogueExport;
use DigitalLibrary\Export\CsvWriter;
use DigitalLibrary\Export\Dataset;
use DigitalLibrary\Export\JsonWriter;
use DigitalLibrary\Export\PersonalExport;
use DigitalLibrary\Http\Controllers\Controller;
use DigitalLibrary\Http\HttpException;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Permission;

/**
 * CSV and JSON downloads.
 *
 * Personal exports (/export/personal…) use only the signed-in user's id;
 * there is no user parameter to change. The catalogue export
 * (/admin/export…) needs catalogue.export (administrators) and never
 * includes wishlists, loans or private collections. Downloads are streamed
 * from keyset-batched queries inside one read-only, consistent-snapshot
 * transaction, are never cached, and are recorded in the audit log.
 */
final class ExportController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->render('export/index', [
            'page_title' => 'export.title',
            'personal' => PersonalExport::DATASETS,
            'catalogue' => $this->can($this->user($request), Permission::CatalogueExport) ? CatalogueExport::DATASETS : [],
        ]);
    }

    public function personalJson(Request $request): Response
    {
        $user = $this->user($request);
        $export = new PersonalExport($this->services->pdo(), $user->id);
        $this->audit($request, 'export.personal', 'user', $user->id, ['format' => 'json', 'dataset' => 'all']);

        return $this->json('personal', ['scope' => 'personal', 'user_id' => $user->id], array_values($export->datasets()), 'library-personal');
    }

    /**
     * @param array<string, string> $params
     */
    public function personalCsv(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $dataset = (new PersonalExport($this->services->pdo(), $user->id))->datasets()[$params['dataset'] ?? ''] ?? throw HttpException::notFound();
        $this->audit($request, 'export.personal', 'user', $user->id, ['format' => 'csv', 'dataset' => $dataset->name]);

        return $this->csv($dataset, 'library-personal-' . $dataset->name);
    }

    public function catalogueJson(Request $request): Response
    {
        $export = new CatalogueExport($this->services->pdo());
        $this->audit($request, 'export.catalogue', 'catalogue', null, ['format' => 'json', 'dataset' => 'all']);

        return $this->json('catalogue', ['scope' => 'catalogue'], array_values($export->datasets()), 'library-catalogue');
    }

    /**
     * @param array<string, string> $params
     */
    public function catalogueCsv(Request $request, array $params): Response
    {
        $dataset = (new CatalogueExport($this->services->pdo()))->datasets()[$params['dataset'] ?? ''] ?? throw HttpException::notFound();
        $this->audit($request, 'export.catalogue', 'catalogue', null, ['format' => 'csv', 'dataset' => $dataset->name]);

        return $this->csv($dataset, 'library-catalogue-' . $dataset->name);
    }

    private function csv(Dataset $dataset, string $basename): Response
    {
        return Response::stream(
            fn ($out) => $this->snapshot(static fn () => CsvWriter::write($out, $dataset->columns, $dataset->rows())),
            $this->headers('text/csv; charset=UTF-8', $basename . '.csv'),
        );
    }

    /**
     * @param array<string, mixed> $meta
     * @param list<Dataset>        $datasets
     */
    private function json(string $type, array $meta, array $datasets, string $basename): Response
    {
        return Response::stream(
            fn ($out) => $this->snapshot(static fn () => JsonWriter::write($out, $type, $meta, $datasets)),
            $this->headers('application/json; charset=UTF-8', $basename . '.json'),
        );
    }

    /**
     * Runs the export in a read-only transaction with a consistent snapshot,
     * so all datasets reflect the same moment.
     */
    private function snapshot(callable $write): void
    {
        $pdo = $this->services->pdo();
        @set_time_limit(300);
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $write();
        } finally {
            $pdo->exec('COMMIT');
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $contentType, string $filename): array
    {
        // $filename is built from whitelisted dataset names only.
        return [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $this->dated($filename) . '"',
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    private function dated(string $filename): string
    {
        $dot = strrpos($filename, '.');

        return substr($filename, 0, (int) $dot) . '-' . gmdate('Ymd') . substr($filename, (int) $dot);
    }
}
