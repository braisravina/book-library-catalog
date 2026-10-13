<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Admin;

use DigitalLibrary\Audit\AuditDetails;
use DigitalLibrary\Audit\AuditLogRepository;
use DigitalLibrary\Audit\AuditQuery;
use DigitalLibrary\Http\Controllers\Controller;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;

/**
 * Read-only audit-log viewer for administrators (audit.view). It has no
 * state-changing actions; times are stored and displayed in UTC.
 */
final class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $query = AuditQuery::fromRequest($request, gmdate('Y-m-d'));
        $repository = $this->services->auditLog();
        $timedOut = false;
        try {
            $page = $repository->page($query);
        } catch (\PDOException $e) {
            if (!AuditLogRepository::isTimeout($e)) {
                throw $e;
            }
            $timedOut = true;
            $page = ['rows' => [], 'next' => null];
        }

        return $this->render('admin/audit/index', [
            'page_title' => 'audit.title',
            'query' => $query,
            'rows' => $page['rows'],
            'next' => $page['next'],
            'timed_out' => $timedOut,
            'actions' => $repository->actions(),
            'entity_types' => $repository->entityTypes(),
            'users' => $this->services->users()->all(),
            'per_page' => AuditLogRepository::PER_PAGE,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $event = $this->found($this->services->auditLog()->find($this->id($params)));

        return $this->render('admin/audit/show', [
            'page_title' => 'audit.event.title',
            'event' => $event,
            'details' => AuditDetails::format($event['details'] === null ? null : (string) $event['details']),
        ]);
    }
}
