<?php

namespace Calibre\Controllers;

use Calibre\Http\HttpException;
use Calibre\Services\DownloadService;
use Calibre\Support\Lang;

final class DownloadController
{
    private DownloadService $downloadService;

    public function __construct(string $appRoot, ?DownloadService $downloadService = null)
    {
        $this->downloadService = $downloadService ?? new DownloadService($appRoot);
    }

    public function handle(array $server, array $query): void
    {
        $requestMethod = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
            $this->failResponse(405, Lang::t('error.method_not_allowed'));
        }

        $bookId = filter_var($query['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($bookId === false || $bookId === null) {
            $this->failResponse(400, Lang::t('error.invalid_book_id'));
        }

        try {
            $download = $this->downloadService->resolveByBookId((int) $bookId);

            header('Content-Type: ' . $download['mime_type']);
            header('Content-Length: ' . (string) $download['size']);
            header(
                'Content-Disposition: attachment; filename="' . addcslashes($download['name'], "\"\\")
                . '"; filename*=UTF-8\'\'' . rawurlencode($download['name'])
            );
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=3600');

            if ($requestMethod === 'HEAD') {
                exit;
            }

            $this->streamFile($download['path']);
        } catch (HttpException $e) {
            $this->failResponse($e->getStatusCode(), $e->getMessage());
        } catch (\Throwable $e) {
            $this->failResponse(500, $e->getMessage());
        }
    }

    private function streamFile(string $filePath): void
    {
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            $this->failResponse(500, Lang::t('error.download_open_failed'));
        }

        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            if ($chunk === false) {
                fclose($handle);
                $this->failResponse(500, Lang::t('error.download_read_failed'));
            }

            echo $chunk;
        }

        fclose($handle);
        exit;
    }

    private function failResponse(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
        exit;
    }
}
