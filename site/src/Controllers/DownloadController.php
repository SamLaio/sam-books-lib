<?php

namespace Calibre\Controllers;

use Calibre\Http\HttpException;
use Calibre\Http\AccelRedirect;
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
            header('Content-Disposition: ' . $this->buildAttachmentDisposition((string) $download['name']));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=3600');

            if ($requestMethod === 'HEAD') {
                exit;
            }

            $internalUri = AccelRedirect::internalUriFor($download['path'], [
                '/__bookslib_internal/books' => $this->downloadService->getLibraryPath(),
            ]);
            if ($internalUri !== null) {
                AccelRedirect::send($internalUri);
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

    private function buildAttachmentDisposition(string $name): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
        if (!is_string($fallback)) {
            $fallback = '';
        }
        $fallback = trim(preg_replace('/\s+/', ' ', $fallback) ?? '');
        if ($fallback === '' || $fallback === '.' || $fallback === '..') {
            $fallback = 'download.bin';
        }

        return 'attachment; filename="' . addcslashes($fallback, "\"\\")
            . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }
}
