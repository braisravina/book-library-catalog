<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers;

use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;

final class HealthController extends Controller
{
    /**
     * Liveness/readiness probe. Reports only up/down, never versions or errors.
     */
    public function show(Request $request): Response
    {
        try {
            $this->services->pdo()->query('SELECT 1')->fetchColumn();
            $response = Response::json(['status' => 'ok']);
        } catch (\Throwable $e) {
            error_log('[digital-library] health check failed: ' . $e->getMessage());
            $response = Response::json(['status' => 'unavailable'], 503);
        }
        $response->setHeader('Cache-Control', 'no-store');
        $response->setHeader('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
