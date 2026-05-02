<?php

namespace Calibre\Controllers;

use Calibre\Http\HttpException;
use Calibre\Services\ReaderAccessService;
use Calibre\Services\ReaderComicService;
use Calibre\Services\ReaderEpubService;
use Calibre\Support\Lang;

final class ReaderAssetController
{
    private ReaderAccessService $accessService;
    private ReaderEpubService $epubService;
    private ReaderComicService $comicService;

    public function __construct(
        string $appRoot,
        ?ReaderAccessService $accessService = null,
        ?ReaderEpubService $epubService = null,
        ?ReaderComicService $comicService = null
    )
    {
        $root = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->accessService = $accessService ?? new ReaderAccessService($root);
        $this->epubService = $epubService ?? new ReaderEpubService($root);
        $this->comicService = $comicService ?? new ReaderComicService();
    }

    public function handle(array $query): void
    {
        $bookId = filter_var($query['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $asset = trim((string) ($query['asset'] ?? ''));
        $document = trim((string) ($query['document'] ?? ''));
        $comic = trim((string) ($query['comic'] ?? ''));

        if ($bookId === false || $bookId === null) {
            $this->respondTextError(400, Lang::t('error.invalid_book_id'));
        }

        if ($asset === '' && $document === '' && $comic === '') {
            $this->respondTextError(400, Lang::t('error.reader_asset_required'));
        }

        try {
            $resolved = $this->accessService->resolveReadableByBookId((int) $bookId);

            if ($document !== '') {
                if ((string) ($resolved['format'] ?? '') !== 'pdf') {
                    $this->respondTextError(404, Lang::t('error.reader_pdf_not_found'));
                }

                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="book-' . (int) $resolved['book_id'] . '.pdf"');
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
                header('Expires: 0');
                readfile((string) $resolved['path']);
                exit;
            }

            if ($comic !== '') {
                if ((string) ($resolved['format'] ?? '') !== 'cbz') {
                    $this->respondTextError(404, Lang::t('error.reader_asset_not_found'));
                }

                $binary = $this->comicService->resolvePageBinary((string) $resolved['path'], $comic);
                header('Content-Type: ' . (string) ($binary['mime_type'] ?? 'application/octet-stream'));
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
                header('Expires: 0');
                echo (string) ($binary['content'] ?? '');
                exit;
            }

            if ((string) ($resolved['format'] ?? '') !== 'epub') {
                $this->respondTextError(404, Lang::t('error.reader_asset_not_found'));
            }

            $binary = $this->epubService->resolveAssetBinary((int) $resolved['book_id'], (string) $resolved['path'], $asset);
            header('Content-Type: ' . (string) ($binary['mime_type'] ?? 'application/octet-stream'));
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            echo (string) ($binary['content'] ?? '');
            exit;
        } catch (HttpException $e) {
            $this->respondTextError($e->getStatusCode(), $e->getMessage());
        } catch (\Throwable $e) {
            $this->respondTextError(500, $e->getMessage());
        }
    }

    private function respondTextError(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
        exit;
    }
}
