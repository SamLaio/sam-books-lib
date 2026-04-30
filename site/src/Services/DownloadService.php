<?php

namespace Calibre\Services;

use Calibre\Http\HttpException;
use Calibre\LibraryIndex;
use Calibre\ScanService;
use Calibre\Support\Lang;

final class DownloadService
{
    private ScanService $scanService;

    public function __construct(string $appRoot, ?ScanService $scanService = null)
    {
        $this->scanService = $scanService ?? new ScanService($appRoot);
    }

    public function resolveByBookId(int $bookId): array
    {
        $index = new LibraryIndex($this->scanService->getSqlitePath());
        $book = $index->getBookById($bookId);

        if ($book === null) {
            throw new HttpException(404, Lang::t('error.book_not_found'));
        }

        $downloadPath = $this->resolveDownloadPath($book, $this->scanService->getLibraryPath());
        if ($downloadPath === null) {
            throw new HttpException(404, Lang::t('error.download_file_not_found'));
        }

        $fileSize = filesize($downloadPath);
        if ($fileSize === false) {
            throw new \RuntimeException(Lang::t('error.download_size_unavailable'));
        }

        return [
            'path' => $downloadPath,
            'name' => $this->buildDownloadName($book, $downloadPath),
            'mime_type' => $this->detectMimeType($downloadPath),
            'size' => $fileSize,
        ];
    }

    private function normalizeStoredPath(string $path): string
    {
        return str_replace('\\', '/', trim($path));
    }

    private function pathSegments(string $path): array
    {
        $normalized = $this->normalizeStoredPath($path);
        $segments = explode('/', $normalized);

        return array_values(array_filter($segments, static function (string $segment): bool {
            return $segment !== '' && $segment !== '.' && $segment !== '..';
        }));
    }

    private function isWithinRoot(string $filePath, string $rootPath): bool
    {
        $fileRealPath = realpath($filePath);
        $rootRealPath = realpath($rootPath);

        if ($fileRealPath === false || $rootRealPath === false) {
            return false;
        }

        return $fileRealPath === $rootRealPath
            || strpos($fileRealPath, $rootRealPath . DIRECTORY_SEPARATOR) === 0;
    }

    private function resolveCandidatePath(string $candidate, string $libraryRoot): ?string
    {
        $trimmedCandidate = trim($candidate);
        if ($trimmedCandidate === '') {
            return null;
        }

        if (ScanService::isAbsolutePath($trimmedCandidate) && is_file($trimmedCandidate)) {
            return $this->isWithinRoot($trimmedCandidate, $libraryRoot) ? $trimmedCandidate : null;
        }

        if (!ScanService::isAbsolutePath($trimmedCandidate)) {
            $relativeCandidate = ScanService::resolvePath($libraryRoot, $trimmedCandidate);
            if (is_file($relativeCandidate) && $this->isWithinRoot($relativeCandidate, $libraryRoot)) {
                return $relativeCandidate;
            }
        }

        $segments = $this->pathSegments($trimmedCandidate);
        $segmentCount = count($segments);

        for ($offset = 0; $offset < $segmentCount; $offset++) {
            $suffixSegments = array_slice($segments, $offset);
            if ($suffixSegments === []) {
                continue;
            }

            $candidatePath = rtrim($libraryRoot, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . implode(DIRECTORY_SEPARATOR, $suffixSegments);

            if (is_file($candidatePath) && $this->isWithinRoot($candidatePath, $libraryRoot)) {
                return $candidatePath;
            }
        }

        return null;
    }

    private function resolveDownloadPath(array $book, string $libraryRoot): ?string
    {
        $candidates = [];

        if (isset($book['path']) && is_string($book['path'])) {
            $candidates[] = $book['path'];
        }

        if (isset($book['formats_json']) && is_string($book['formats_json']) && $book['formats_json'] !== '') {
            $formats = json_decode($book['formats_json'], true);
            if (is_array($formats)) {
                foreach ($formats as $path) {
                    if (is_string($path)) {
                        $candidates[] = $path;
                    }
                }
            }
        }

        foreach ($candidates as $candidate) {
            $resolved = $this->resolveCandidatePath($candidate, $libraryRoot);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function detectMimeType(string $filePath): string
    {
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
}
