<?php

namespace Calibre\Services;

use Calibre\Http\HttpException;
use Calibre\Support\Lang;

final class ReaderAccessService
{
    private OpdsAssetService $assetService;
    private const FORMAT_PRIORITY = ['epub', 'pdf', 'cbz'];

    public function __construct(string $appRoot, ?OpdsAssetService $assetService = null)
    {
        $this->assetService = $assetService ?? new OpdsAssetService($appRoot);
    }

    public function resolveReadableByBookId(int $bookId): array
    {
        $book = $this->assetService->getBookById($bookId);
        $downloads = $this->assetService->listDownloadablesForBook($book);

        foreach (self::FORMAT_PRIORITY as $wantedFormat) {
            foreach ($downloads as $download) {
                if (strtolower((string) ($download['format'] ?? '')) !== $wantedFormat) {
                    continue;
                }

                $path = trim((string) ($download['path'] ?? ''));
                if ($path === '' || !is_file($path)) {
                    continue;
                }

                return [
                    'book' => $book,
                    'download' => $download,
                    'path' => $path,
                    'format' => $wantedFormat,
                    'book_id' => (int) ($book['id'] ?? $bookId),
                    'title' => trim((string) ($book['title'] ?? '')),
                ];
            }
        }

        throw new HttpException(404, Lang::t('error.reader_readable_not_found'));
    }

    public function resolveEpubByBookId(int $bookId): array
    {
        $resolved = $this->resolveReadableByBookId($bookId);
        if (($resolved['format'] ?? '') !== 'epub') {
            throw new HttpException(404, Lang::t('error.reader_epub_not_found'));
        }

        return $resolved;
    }

    public function resolvePdfByBookId(int $bookId): array
    {
        $resolved = $this->resolveReadableByBookId($bookId);
        if (($resolved['format'] ?? '') !== 'pdf') {
            throw new HttpException(404, Lang::t('error.reader_pdf_not_found'));
        }

        return $resolved;
    }

    public function resolveCbzByBookId(int $bookId): array
    {
        $resolved = $this->resolveReadableByBookId($bookId);
        if (($resolved['format'] ?? '') !== 'cbz') {
            throw new HttpException(404, Lang::t('error.reader_readable_not_found'));
        }

        return $resolved;
    }

    public function hasReadableBook(array $book): bool
    {
        $path = trim((string) ($book['path'] ?? ''));
        if (in_array(strtolower((string) pathinfo($path, PATHINFO_EXTENSION)), self::FORMAT_PRIORITY, true)) {
            return true;
        }

        $formatsJson = $book['formats_json'] ?? null;
        if (!is_string($formatsJson) || trim($formatsJson) === '') {
            return false;
        }

        $formats = json_decode($formatsJson, true);
        if (!is_array($formats)) {
            return false;
        }

        foreach ($formats as $format => $candidatePath) {
            $normalizedFormat = strtolower(trim((string) $format));
            if (in_array($normalizedFormat, self::FORMAT_PRIORITY, true)) {
                return true;
            }

            if (is_string($candidatePath) && in_array(strtolower((string) pathinfo($candidatePath, PATHINFO_EXTENSION)), self::FORMAT_PRIORITY, true)) {
                return true;
            }
        }

        return false;
    }
}
