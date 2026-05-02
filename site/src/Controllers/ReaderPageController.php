<?php

namespace Calibre\Controllers;

use Calibre\Http\HttpException;
use Calibre\Services\ReaderAccessService;
use Calibre\Services\ReaderEpubService;
use Calibre\Support\Lang;

final class ReaderPageController
{
    private ReaderAccessService $accessService;
    private ReaderEpubService $epubService;

    public function __construct(string $appRoot, ?ReaderAccessService $accessService = null, ?ReaderEpubService $epubService = null)
    {
        $root = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->accessService = $accessService ?? new ReaderAccessService($root);
        $this->epubService = $epubService ?? new ReaderEpubService($root);
    }

    public function handle(array $query): void
    {
        $bookId = filter_var($query['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $sectionId = trim((string) ($query['section'] ?? ''));
        $theme = strtolower(trim((string) ($query['theme'] ?? 'light')));

        if ($bookId === false || $bookId === null) {
            $this->respondTextError(400, Lang::t('error.invalid_book_id'));
        }

        if ($sectionId === '') {
            $this->respondTextError(400, Lang::t('error.reader_section_required'));
        }

        try {
            $resolved = $this->accessService->resolveReadableByBookId((int) $bookId);
            if ((string) ($resolved['format'] ?? '') !== 'epub') {
                $this->respondTextError(400, Lang::t('error.reader_section_required'));
            }
            $html = $this->epubService->renderSectionDocument((int) $resolved['book_id'], (string) $resolved['path'], $sectionId, $theme);
            http_response_code(200);
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            echo $html;
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
