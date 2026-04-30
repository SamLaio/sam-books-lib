<?php

namespace Calibre\Controllers;

use Calibre\Http\HttpException;
use Calibre\Services\AuthService;
use Calibre\Services\BookDetailsService;
use Calibre\ScanService;
use Calibre\Support\Lang;

final class BookDetailsController
{
    private string $appRoot;
    private BookDetailsService $bookDetailsService;

    public function __construct(string $appRoot, ?BookDetailsService $bookDetailsService = null)
    {
        $this->appRoot = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->bookDetailsService = $bookDetailsService ?? new BookDetailsService($appRoot);
    }

    public function handle(array $server, array $query): void
    {
        $requestMethod = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if ($requestMethod !== 'GET') {
            $this->respondJson(['error' => Lang::t('error.method_not_allowed')], 405);
        }

        $bookId = filter_var($query['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($bookId === false || $bookId === null) {
            $this->respondJson(['error' => Lang::t('error.invalid_book_id')], 400);
        }

        try {
            $details = $this->bookDetailsService->getDetails((int) $bookId);
            $details = $this->applyActionUrls($details);
            $this->respondJson($details);
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

    private function applyActionUrls(array $details): array
    {
        $bookId = isset($details['id']) ? (int) $details['id'] : 0;
        $details['download_url'] = $bookId > 0 ? 'download.php?id=' . $bookId : null;
        $details['send_url'] = null;

        if ($bookId < 1) {
            return $details;
        }

        $scanService = new ScanService($this->appRoot);
        $authService = new AuthService($this->appRoot, $scanService);
        $currentUser = $authService->getCurrentUser();
        $currentUserEmail = is_array($currentUser) ? trim((string) ($currentUser['email'] ?? '')) : '';
        $canSendBookByEmail = $currentUserEmail !== ''
            && filter_var($currentUserEmail, FILTER_VALIDATE_EMAIL) !== false
            && $authService->isSmtpConfigured();

        if ($canSendBookByEmail) {
            $details['send_url'] = 'send.php?id=' . $bookId;
        }

        return $details;
    }
}
