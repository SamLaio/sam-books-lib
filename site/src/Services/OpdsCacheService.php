<?php

namespace Calibre\Services;

final class OpdsCacheService
{
    private const MAX_CACHE_BYTES = 10 * 1024 * 1024;

    private string $cacheDir;

    public function __construct(string $appRoot)
    {
        $this->cacheDir = rtrim($appRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'data'
            . DIRECTORY_SEPARATOR
            . 'opds-cache';
    }

    public function read(array $server, array $query): ?string
    {
        $path = $this->cachePath($server, $query);
        if (!is_file($path)) {
            return null;
        }

        $body = @file_get_contents($path);
        if (!is_string($body) || $body === '') {
            return null;
        }

        return $body;
    }

    public function write(array $server, array $query, string $xml): void
    {
        if ($xml === '') {
            return;
        }

        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0777, true) && !is_dir($this->cacheDir)) {
            return;
        }
        @chmod($this->cacheDir, 0777);

        $path = $this->cachePath($server, $query);
        $this->pruneForIncomingFile($path, strlen($xml));
        @file_put_contents($path, $xml, LOCK_EX);
        if (is_file($path)) {
            @chmod($path, 0666);
        }
    }

    public function clearAll(): int
    {
        if (!is_dir($this->cacheDir)) {
            return 0;
        }

        $entries = @scandir($this->cacheDir);
        if (!is_array($entries)) {
            return 0;
        }

        $deleted = 0;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $this->cacheDir . DIRECTORY_SEPARATOR . $entry;
            if (is_file($fullPath) && @unlink($fullPath)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function pruneForIncomingFile(string $incomingPath, int $incomingBytes): void
    {
        $files = $this->listCacheFiles();
        if ($files === []) {
            return;
        }

        $totalBytes = 0;
        foreach ($files as $file) {
            if ($file['path'] === $incomingPath) {
                continue;
            }

            $totalBytes += $file['size'];
        }

        if ($totalBytes + $incomingBytes <= self::MAX_CACHE_BYTES) {
            return;
        }

        usort($files, static function (array $a, array $b): int {
            if ($a['mtime'] === $b['mtime']) {
                return strcmp($a['path'], $b['path']);
            }

            return $a['mtime'] <=> $b['mtime'];
        });

        foreach ($files as $file) {
            if ($file['path'] === $incomingPath) {
                continue;
            }

            if (@unlink($file['path'])) {
                $totalBytes -= $file['size'];
            }

            if ($totalBytes + $incomingBytes <= self::MAX_CACHE_BYTES) {
                return;
            }
        }
    }

    private function listCacheFiles(): array
    {
        if (!is_dir($this->cacheDir)) {
            return [];
        }

        $entries = @scandir($this->cacheDir);
        if (!is_array($entries)) {
            return [];
        }

        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $this->cacheDir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path)) {
                continue;
            }

            $size = @filesize($path);
            $mtime = @filemtime($path);
            $files[] = [
                'path' => $path,
                'size' => is_int($size) ? max(0, $size) : 0,
                'mtime' => is_int($mtime) ? $mtime : 0,
            ];
        }

        return $files;
    }

    private function cachePath(array $server, array $query): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . $this->cacheKey($server, $query) . '.xml';
    }

    private function cacheKey(array $server, array $query): string
    {
        $normalizedQuery = $this->normalizeQuery($query);
        $accept = strtolower(trim((string) ($server['HTTP_ACCEPT'] ?? '')));

        $payload = [
            'accept' => $accept,
            'query' => $normalizedQuery,
            'visibility' => trim((string) ($server['OPDS_VISIBILITY_KEY'] ?? 'public')),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function normalizeQuery(array $query): array
    {
        $normalized = [];
        foreach ($query as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $k = trim((string) $key);
            if ($k === '') {
                continue;
            }

            $normalized[$k] = trim((string) $value);
        }

        if (!isset($normalized['feed']) || trim((string) $normalized['feed']) === '') {
            $normalized['feed'] = 'index';
        }

        ksort($normalized);

        return $normalized;
    }
}
