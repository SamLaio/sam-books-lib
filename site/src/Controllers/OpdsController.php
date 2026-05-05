<?php

namespace Calibre\Controllers;

use Calibre\Http\HttpException;
use Calibre\Services\OpdsAssetService;
use Calibre\Services\OpdsCacheService;
use Calibre\Services\OpdsService;
use Calibre\Services\AuthService;
use Calibre\Support\CatalogRequest;
use Calibre\Support\Lang;

final class OpdsController
{
    private string $appRoot;
    private OpdsService $opdsService;
    private OpdsAssetService $assetService;
    private OpdsCacheService $cacheService;
    private AuthService $authService;

    public function __construct(
        string $appRoot,
        ?OpdsService $opdsService = null,
        ?OpdsAssetService $assetService = null,
        ?OpdsCacheService $cacheService = null,
        ?AuthService $authService = null
    ) {
        $this->appRoot = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->opdsService = $opdsService ?? new OpdsService($appRoot);
        $this->assetService = $assetService ?? new OpdsAssetService($appRoot);
        $this->cacheService = $cacheService ?? new OpdsCacheService($appRoot);
        $this->authService = $authService ?? new AuthService($appRoot);
    }

    public function handle(array $server, array $query): void
    {
        $requestMethod = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
            $this->failResponse(405, Lang::t('error.method_not_allowed'));
        }

        $feed = strtolower(trim((string) ($query['feed'] ?? 'index')));
        $query = $this->normalizeCatalogQuery($feed, $query);
        $visibility = $this->resolveUserVisibility($server);
        $server['OPDS_VISIBILITY_KEY'] = $visibility['key'];

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
                $this->assertBookVisible($bookId, $visibility);
                $cover = $this->assetService->resolveCoverByBookId($bookId);
                $this->streamBinaryResponse($cover, $requestMethod, false);
            }

            if ($feed === 'download') {
                $bookId = $this->readBookId($query);
                $this->assertBookVisible($bookId, $visibility);
                $format = trim((string) ($query['format'] ?? ''));
                $download = $this->assetService->resolveDownloadByBookId($bookId, $format === '' ? null : $format);
                $this->streamBinaryResponse($download, $requestMethod, true);
            }

            $contentType = $this->resolveCatalogContentType($server, $feed);
            $cachedXml = $this->cacheService->read($server, $query);
            if ($cachedXml !== null) {
                $this->emitXmlResponse($contentType, $cachedXml, $requestMethod);
            }

            $xml = $this->opdsService->renderCatalog($server, $query, $visibility);
            $this->cacheService->write($server, $query, $xml);
            $this->emitXmlResponse($contentType, $xml, $requestMethod);
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

    private function normalizeCatalogQuery(string $feed, array $query): array
    {
        if ($feed !== 'search') {
            return $query;
        }

        $search = null;
        foreach (['query', 'q', 'searchTerms', 'term'] as $key) {
            if (!isset($query[$key]) || !is_scalar($query[$key])) {
                continue;
            }

            $candidate = trim((string) $query[$key]);
            if ($candidate === '') {
                continue;
            }

            $search = $candidate;
            break;
        }

        foreach (['q', 'searchTerms', 'term'] as $alias) {
            unset($query[$alias]);
        }

        if ($search !== null) {
            $query['query'] = CatalogRequest::normalizeQuery($search);
        }

        return $query;
    }

    private function assertBookVisible(int $bookId, array $visibility): void
    {
        if (!$this->opdsService->isBookVisible($bookId, $visibility)) {
            throw new HttpException(404, Lang::t('error.book_not_found'));
        }
    }

    /**
     * @return array{hidden_authors:array<int,string>,hidden_tags:array<int,string>,key:string}
     */
    private function resolveUserVisibility(array $server): array
    {
        $user = null;
        $userId = filter_var($server['OPDS_AUTH_USER_ID'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($userId !== false && $userId !== null) {
            $user = $this->authService->getUserById((int) $userId);
        } else {
            $user = $this->authService->getCurrentUser();
        }

        $hiddenAuthors = $this->authService->getUserHiddenAuthors($user);
        $hiddenTags = $this->authService->getUserHiddenTags($user);
        $payload = [
            'user_id' => is_array($user) ? (int) ($user['id'] ?? 0) : 0,
            'hidden_authors' => $hiddenAuthors,
            'hidden_tags' => $hiddenTags,
        ];

        return [
            'hidden_authors' => $hiddenAuthors,
            'hidden_tags' => $hiddenTags,
            'key' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
        ];
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
