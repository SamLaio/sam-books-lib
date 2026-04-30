<?php

namespace Calibre\Controllers;

use Calibre\ScanService;
use Calibre\Services\ScanScheduleService;
use Calibre\Support\Lang;

final class ScanRequestController
{
    private ScanService $scanService;
    private ScanScheduleService $scheduleService;

    public function __construct(
        string $appRoot,
        ?ScanService $scanService = null,
        ?ScanScheduleService $scheduleService = null
    )
    {
        $this->scanService = $scanService ?? new ScanService($appRoot);
        $this->scheduleService = $scheduleService ?? new ScanScheduleService($appRoot);
    }

    public function handle(array $server, array $post): void
    {
        $requestMethod = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if ($requestMethod !== 'POST') {
            $this->respondJson(['error' => Lang::t('error.method_not_allowed')], 405);
        }

        $action = strtolower(trim((string) ($post['action'] ?? 'rebuild')));
        if ($action !== 'rebuild') {
            $this->respondJson(['error' => Lang::t('error.unsupported_action')], 400);
        }

        try {
            $request = $this->scheduleService->enqueueManual($action, 60);
            $this->respondJson([
                'ok' => true,
                'status' => 'queued',
                'action' => $request['action'],
                'requested_at' => $request['created_at'] ?? date('c'),
                'run_at' => $request['run_at'] ?? date('c'),
                'schedule_id' => $request['id'] ?? null,
                'message' => Lang::t('message.scan_request_queued'),
            ], 202);
        } catch (\Throwable $e) {
            $this->respondJson(['error' => $e->getMessage()], 500);
        }
    }

    private function respondJson(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
