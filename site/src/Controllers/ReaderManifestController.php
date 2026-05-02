<?php

namespace Calibre\Controllers;

use Calibre\Http\HttpException;
use Calibre\Services\ReaderAccessService;
use Calibre\Services\ReaderComicService;
use Calibre\Services\ReaderEpubService;
use Calibre\Services\ReaderPdfService;
use Calibre\Support\Lang;

final class ReaderManifestController
{
    private ReaderAccessService $accessService;
    private ReaderEpubService $epubService;
    private ReaderPdfService $pdfService;
    private ReaderComicService $comicService;

    public function __construct(
        string $appRoot,
        ?ReaderAccessService $accessService = null,
        ?ReaderEpubService $epubService = null,
        ?ReaderPdfService $pdfService = null,
        ?ReaderComicService $comicService = null
    )
    {
        $root = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->accessService = $accessService ?? new ReaderAccessService($root);
        $this->epubService = $epubService ?? new ReaderEpubService($root);
        $this->pdfService = $pdfService ?? new ReaderPdfService();
        $this->comicService = $comicService ?? new ReaderComicService();
    }

    public function handle(array $query): void
    {
        $bookId = filter_var($query['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($bookId === false || $bookId === null) {
            $this->respondJson(['error' => Lang::t('error.invalid_book_id')], 400);
        }

        try {
            $resolved = $this->accessService->resolveReadableByBookId((int) $bookId);
            $format = (string) ($resolved['format'] ?? '');
            $manifest = match ($format) {
                'pdf' => $this->pdfService->buildManifest((int) $resolved['book_id'], (array) $resolved['book']),
                'cbz' => $this->comicService->buildManifest((int) $resolved['book_id'], (array) $resolved['book'], (string) $resolved['path']),
                default => $this->epubService->buildManifest((int) $resolved['book_id'], (array) $resolved['book'], (string) $resolved['path']),
            };
            $this->respondJson($manifest);
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
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
