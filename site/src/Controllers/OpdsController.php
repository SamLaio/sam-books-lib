<?php

namespace Calibre\Controllers;

use Calibre\Http\HttpException;
use Calibre\Services\OpdsAssetService;
use Calibre\Services\OpdsService;
use Calibre\Support\Lang;

final class OpdsController
{
    private string $appRoot;
    private OpdsService $opdsService;
    private OpdsAssetService $assetService;

    public function __construct(
        string $appRoot,
        ?OpdsService $opdsService = null,
        ?OpdsAssetService $assetService = null
    ) {
        $this->appRoot = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->opdsService = $opdsService ?? new OpdsService($appRoot);
        $this->assetService = $assetService ?? new OpdsAssetService($appRoot);
    }

    public function handle(array $server, array $query): void
    {
        $requestMethod = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
            $this->failResponse(405, Lang::t('error.method_not_allowed'));
        }

        $feed = strtolower(trim((string) ($query['feed'] ?? 'index')));

        try {
            if ($feed === 'osd') {
                $this->emitXmlResponse(
                    'application/opensearchdescription+xml; charset=UTF-8',
                    $this->opdsService->renderSearchDescription($server),
                    $requestMethod
                );
            }

            if ($feed === 'cover') {
                $bookId = $this->readBookId($query);
                $cover = $this->assetService->resolveCoverByBookId($bookId);
                $this->streamBinaryResponse($cover, $requestMethod, false);
            }

            if ($feed === 'download') {
                $bookId = $this->readBookId($query);
                $format = trim((string) ($query['format'] ?? ''));
                $download = $this->assetService->resolveDownloadByBookId($bookId, $format === '' ? null : $format);
                $this->streamBinaryResponse($download, $requestMethod, true);
            }

            $this->emitXmlResponse(
                $this->resolveCatalogContentType($server, $feed),
                $this->opdsService->renderCatalog($server, $query),
                $requestMethod
            );
        } catch (HttpException $e) {
            $this->failResponse($e->getStatusCode(), $e->getMessage());
        } catch (\Throwable $e) {
            $this->failResponse(500, $e->getMessage());
        }
    }

    private function readBookId(array $query): int
    {
        $bookId = filter_var($query['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($bookId === false || $bookId === null) {
            throw new HttpException(400, Lang::t('error.invalid_book_id'));
        }

        return (int) $bookId;
    }

    private function emitXmlResponse(string $contentType, string $body, string $requestMethod): void
    {
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: inline');
        header('X-Content-Type-Options: nosniff');

        if ($requestMethod === 'HEAD') {
            exit;
        }

        echo $body;
        exit;
    }

    private function resolveCatalogContentType(array $server, string $feed): string
    {
        $accept = strtolower((string) ($server['HTTP_ACCEPT'] ?? ''));
        $contentType = $this->opdsService->responseContentType($feed);

        $this->logReadiumRequest($server, $feed, $contentType);

        if ($this->prefersXmlPreview($accept)) {
            return 'application/xml; charset=UTF-8';
        }

        return $contentType;
    }

    private function prefersXmlPreview(string $accept): bool
    {
        if ($accept === '') {
            return false;
        }

        if (str_contains($accept, 'application/atom+xml')) {
            return false;
        }

        return str_contains($accept, 'text/html')
            || str_contains($accept, 'application/xhtml+xml')
            || str_contains($accept, 'application/xml');
    }

    private function logReadiumRequest(array $server, string $feed, string $contentType): void
    {
        $userAgent = (string) ($server['HTTP_USER_AGENT'] ?? '');
        if (stripos($userAgent, 'readium-desktop') === false) {
            return;
        }

        $message = sprintf(
            '[OPDS][readium] method=%s uri=%s feed=%s accept=%s accept_encoding=%s content_type=%s',
            (string) ($server['REQUEST_METHOD'] ?? 'GET'),
            (string) ($server['REQUEST_URI'] ?? ''),
            $feed,
            (string) ($server['HTTP_ACCEPT'] ?? ''),
            (string) ($server['HTTP_ACCEPT_ENCODING'] ?? ''),
            $contentType
        );

        $logPath = $this->appRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'opds-readium.log';
        @file_put_contents($logPath, $message . PHP_EOL, FILE_APPEND);
    }

    private function streamBinaryResponse(array $asset, string $requestMethod, bool $attachment): void
    {
        header('Content-Type: ' . (string) ($asset['mime_type'] ?? 'application/octet-stream'));
        header('Content-Length: ' . (string) ((int) ($asset['size'] ?? 0)));
        header('X-Content-Type-Options: nosniff');

        if ($attachment) {
            $name = (string) ($asset['name'] ?? 'download.bin');
            header(
                'Content-Disposition: attachment; filename="' . addcslashes($name, "\"\\")
                . '"; filename*=UTF-8\'\'' . rawurlencode($name)
            );
            header('Cache-Control: private, max-age=3600');
        } else {
            header('Cache-Control: public, max-age=3600');
        }

        if ($requestMethod === 'HEAD') {
            exit;
        }

        $this->streamFile((string) ($asset['path'] ?? ''));
    }

    private function streamFile(string $filePath): void
    {
        if (!is_file($filePath)) {
            $this->failResponse(404, 'File not found.');
        }

        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            $this->failResponse(500, 'Cannot open file.');
        }

        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            if ($chunk === false) {
                fclose($handle);
                $this->failResponse(500, 'Cannot read file.');
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
