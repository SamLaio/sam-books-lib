<?php

namespace Calibre\Services;

use Calibre\Book;
use Calibre\CalibreLibrary;
use Calibre\Http\HttpException;
use Calibre\LibraryIndex;
use Calibre\ScanService;
use Calibre\Support\Lang;

final class OpdsAssetService
{
    private string $appRoot;
    private ScanService $scanService;

    public function __construct(string $appRoot, ?ScanService $scanService = null)
    {
        $this->appRoot = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->scanService = $scanService ?? new ScanService($this->appRoot);
    }

    public function getBookById(int $bookId): array
    {
        $index = new LibraryIndex($this->scanService->getSqlitePath());
        $book = $index->getBookById($bookId);

        if ($book === null) {
            throw new HttpException(404, Lang::t('error.book_not_found'));
        }

        return $book;
    }

    public function getLibraryPath(): string
    {
        return $this->scanService->getLibraryPath();
    }

    public function getThumbDir(): string
    {
        return $this->scanService->getThumbDir();
    }

    public function listDownloadablesForBook(array $book): array
    {
        $libraryRoot = $this->scanService->getLibraryPath();
        $downloads = [];
        $seenPaths = [];

        foreach ($this->decodeFormatCandidates($book) as $format => $candidatePath) {
            $resolvedPath = $this->resolveCandidatePath(
                $candidatePath,
                [$libraryRoot],
                [$libraryRoot]
            );

            if ($resolvedPath === null) {
                continue;
            }

            $pathKey = strtolower($format) . '|' . $resolvedPath;
            if (isset($seenPaths[$pathKey])) {
                continue;
            }

            $fileSize = filesize($resolvedPath);
            if ($fileSize === false) {
                continue;
            }

            $downloads[] = [
                'format' => strtolower($format),
                'path' => $resolvedPath,
                'mime_type' => $this->detectMimeType($resolvedPath),
                'size' => $fileSize,
                'name' => $this->buildDownloadName($book, $resolvedPath),
                'modified_at' => $this->normalizeFileDate(filemtime($resolvedPath)),
            ];
            $seenPaths[$pathKey] = true;
        }

        return $downloads;
    }

    public function resolveDownloadByBookId(int $bookId, ?string $requestedFormat = null): array
    {
        $book = $this->getBookById($bookId);
        $downloads = $this->listDownloadablesForBook($book);

        if ($downloads === []) {
            throw new HttpException(404, Lang::t('error.download_file_not_found'));
        }

        if ($requestedFormat === null || trim($requestedFormat) === '') {
            return $downloads[0];
        }

        $normalizedFormat = strtolower(trim($requestedFormat));
        foreach ($downloads as $download) {
            if (($download['format'] ?? '') === $normalizedFormat) {
                return $download;
            }
        }

        throw new HttpException(404, Lang::t('error.requested_book_format_not_found'));
    }

    public function resolveCoverForBook(array $book): ?array
    {
        $cover = $this->resolveExistingCoverForBook($book);
        if ($cover !== null) {
            return $cover;
        }

        $lazyCoverPath = $this->resolveLazyCoverPath($book);
        if ($lazyCoverPath === null) {
            return null;
        }

        $book['cover_path'] = $lazyCoverPath;

        return $this->resolveExistingCoverForBook($book);
    }

    public function resolveExistingCoverForBook(array $book): ?array
    {
        $coverPath = trim((string) ($book['cover_path'] ?? ''));
        if ($coverPath === '') {
            return null;
        }

        $libraryRoot = $this->scanService->getLibraryPath();
        $thumbRoot = $this->scanService->getThumbDir();
        $legacyThumbRoot = $this->appRoot . DIRECTORY_SEPARATOR . 'thumb';
        $resolvedPath = $this->resolveCandidatePath(
            $coverPath,
            [$libraryRoot, $this->appRoot],
            [$libraryRoot, $thumbRoot, $legacyThumbRoot]
        );

        if ($resolvedPath === null) {
            return null;
        }

        $fileSize = filesize($resolvedPath);
        if ($fileSize === false) {
            return null;
        }

        return [
            'path' => $resolvedPath,
            'mime_type' => $this->detectMimeType($resolvedPath),
            'size' => $fileSize,
            'modified_at' => $this->normalizeFileDate(filemtime($resolvedPath)),
        ];
    }

    public function canLazyResolveCoverForBook(array $book): bool
    {
        $formats = $this->decodeFormatCandidates($book);

        return isset($formats['epub']) || isset($formats['cbz']);
    }

    private function resolveLazyCoverPath(array $book): ?string
    {
        $formats = $this->decodeFormatCandidates($book);
        if ($formats === []) {
            return null;
        }

        $metadata = [];
        $metadataJson = $book['metadata_json'] ?? null;
        if (is_string($metadataJson) && trim($metadataJson) !== '') {
            $decodedMetadata = json_decode($metadataJson, true);
            if (is_array($decodedMetadata)) {
                $metadata = $decodedMetadata;
            }
        }

        $library = new CalibreLibrary($this->scanService->getLibraryPath(), $this->scanService->getThumbDir());
        $bookModel = new Book(
            (string) ($book['title'] ?? ''),
            (string) ($book['author'] ?? ''),
            (string) ($book['path'] ?? ''),
            $formats,
            $metadata,
            null
        );

        $coverPath = $library->ensureBookCover($bookModel);
        if ($coverPath === null || !is_file($coverPath)) {
            return null;
        }

        $bookId = (int) ($book['id'] ?? 0);
        if ($bookId > 0) {
            $index = new LibraryIndex($this->scanService->getSqlitePath());
            try {
                $index->updateBookCoverPathById($bookId, $coverPath);
            } finally {
                $index->close();
            }
        }

        return $coverPath;
    }

    public function resolveCoverByBookId(int $bookId): array
    {
        $book = $this->getBookById($bookId);
        $cover = $this->resolveCoverForBook($book);

        if ($cover === null) {
            throw new HttpException(404, Lang::t('error.cover_image_not_found'));
        }

        return $cover;
    }

    private function decodeFormatCandidates(array $book): array
    {
        $decoded = [];
        $formatsJson = $book['formats_json'] ?? null;

        if (is_string($formatsJson) && trim($formatsJson) !== '') {
            $formats = json_decode($formatsJson, true);
            if (is_array($formats)) {
                foreach ($formats as $format => $path) {
                    if (!is_string($path) || trim($path) === '') {
                        continue;
                    }

                    $normalizedFormat = is_string($format) && trim($format) !== ''
                        ? strtolower(trim($format))
                        : strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

                    if ($normalizedFormat === '') {
                        $normalizedFormat = 'file';
                    }

                    $decoded[$normalizedFormat] = $path;
                }
            }
        }

        if ($decoded !== []) {
            return $decoded;
        }

        $path = trim((string) ($book['path'] ?? ''));
        if ($path === '') {
            return [];
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = 'file';
        }

        return [$extension => $path];
    }

    private function resolveCandidatePath(string $candidate, array $baseRoots, array $allowedRoots): ?string
    {
        $trimmedCandidate = trim($candidate);
        if ($trimmedCandidate === '') {
            return null;
        }

        $resolvedPath = $this->resolveAbsolutePath($trimmedCandidate, $allowedRoots);
        if ($resolvedPath !== null) {
            return $resolvedPath;
        }

        foreach ($baseRoots as $baseRoot) {
            $normalizedRoot = trim((string) $baseRoot);
            if ($normalizedRoot === '') {
                continue;
            }

            if (!ScanService::isAbsolutePath($trimmedCandidate)) {
                $relativePath = ScanService::resolvePath($normalizedRoot, $trimmedCandidate);
                $resolvedPath = $this->resolveAbsolutePath($relativePath, $allowedRoots);
                if ($resolvedPath !== null) {
                    return $resolvedPath;
                }
            }

            $segments = $this->pathSegments($trimmedCandidate);
            $segmentCount = count($segments);

            for ($offset = 0; $offset < $segmentCount; $offset++) {
                $suffixSegments = array_slice($segments, $offset);
                if ($suffixSegments === []) {
                    continue;
                }

                $candidatePath = rtrim($normalizedRoot, DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR
                    . implode(DIRECTORY_SEPARATOR, $suffixSegments);

                $resolvedPath = $this->resolveAbsolutePath($candidatePath, $allowedRoots);
                if ($resolvedPath !== null) {
                    return $resolvedPath;
                }
            }
        }

        return null;
    }

    private function resolveAbsolutePath(string $candidatePath, array $allowedRoots): ?string
    {
        if (!is_file($candidatePath)) {
            return null;
        }

        return $this->isWithinAllowedRoots($candidatePath, $allowedRoots) ? $candidatePath : null;
    }

    private function isWithinAllowedRoots(string $filePath, array $allowedRoots): bool
    {
        $fileRealPath = realpath($filePath);
        if ($fileRealPath === false) {
            return false;
        }

        foreach ($allowedRoots as $allowedRoot) {
            $rootRealPath = realpath($allowedRoot);
            if ($rootRealPath === false) {
                continue;
            }

            if ($fileRealPath === $rootRealPath || strpos($fileRealPath, $rootRealPath . DIRECTORY_SEPARATOR) === 0) {
                return true;
            }
        }

        return false;
    }

    private function pathSegments(string $path): array
    {
        $normalized = str_replace('\\', '/', trim($path));
        $segments = explode('/', $normalized);

        return array_values(array_filter($segments, static function (string $segment): bool {
            return $segment !== '' && $segment !== '.' && $segment !== '..';
        }));
    }

    private function detectMimeType(string $filePath): string
    {
        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
        $knownType = $this->mimeTypeFromExtension($extension);
        if ($knownType !== null) {
            return $knownType;
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mimeType = finfo_file($finfo, $filePath);
                finfo_close($finfo);

                if (is_string($mimeType) && $mimeType !== '') {
                    return $mimeType;
                }
            }
        }

        return 'application/octet-stream';
    }

    private function mimeTypeFromExtension(string $extension): ?string
    {
        return match ($extension) {
            'epub' => 'application/epub+zip',
            'mobi' => 'application/x-mobipocket-ebook',
            'azw', 'azw3' => 'application/vnd.amazon.ebook',
            'pdf' => 'application/pdf',
            'cbz' => 'application/vnd.comicbook+zip',
            'cbr' => 'application/vnd.comicbook-rar',
            'txt' => 'text/plain',
            'html', 'htm' => 'text/html',
            'rtf' => 'application/rtf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            default => null,
        };
    }

    private function sanitizeDownloadPart(?string $value, string $fallback): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return $fallback;
        }

        $normalized = preg_replace('/[\\\\\\/:*?"<>|]+/', ' ', $normalized);
        if ($normalized === null) {
            return $fallback;
        }

        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        if ($normalized === null) {
            return $fallback;
        }

        $normalized = trim($normalized, " .\t\n\r\0\x0B");

        return $normalized === '' ? $fallback : $normalized;
    }

    private function buildDownloadName(array $book, string $downloadPath): string
    {
        $title = $this->sanitizeDownloadPart($book['title'] ?? null, 'Unknown Title');
        $author = $this->sanitizeDownloadPart($book['author'] ?? null, 'Unknown Author');
        $extension = pathinfo($downloadPath, PATHINFO_EXTENSION);

        $downloadName = $title . ' - ' . $author;
        if ($extension !== '') {
            $downloadName .= '.' . $extension;
        }

        return $downloadName;
    }

    private function normalizeFileDate($timestamp): ?string
    {
        if (!is_int($timestamp) || $timestamp <= 0) {
            return null;
        }

        return gmdate(DATE_ATOM, $timestamp);
    }
}
