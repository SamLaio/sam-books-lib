<?php

namespace Calibre\Controllers;

use Calibre\Http\HttpException;
use Calibre\Services\ReadStatusService;
use Calibre\Support\Lang;

final class ReadStatusController
{
    private ReadStatusService $readStatusService;

    public function __construct(string $appRoot, ?ReadStatusService $readStatusService = null)
    {
        $this->readStatusService = $readStatusService ?? new ReadStatusService($appRoot);
    }

    public function handle(array $server, array $post): void
    {
        $requestMethod = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if ($requestMethod !== 'POST') {
            $this->respondJson(['error' => Lang::t('error.method_not_allowed')], 405);
        }

        $bookId = filter_var($post['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $isReadValue = $post['is_read'] ?? null;

        if ($bookId === false || $bookId === null || !in_array((string) $isReadValue, ['0', '1'], true)) {
            $this->respondJson(['error' => Lang::t('error.invalid_read_status_payload')], 400);
        }

        try {
            $result = $this->readStatusService->update((int) $bookId, (string) $isReadValue === '1');
            $this->respondJson($result);
        } catch (HttpException $e) {
            $this->respondJson(['error' => $e->getMessage()], $e->getStatusCode());
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
