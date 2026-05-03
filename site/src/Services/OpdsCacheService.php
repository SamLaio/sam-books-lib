<?php

namespace Calibre\Services;

final class OpdsCacheService
{
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

        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
            return;
        }

        $path = $this->cachePath($server, $query);
        @file_put_contents($path, $xml, LOCK_EX);
    }

    public function clearAll(): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }

        $entries = @scandir($this->cacheDir);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $this->cacheDir . DIRECTORY_SEPARATOR . $entry;
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
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
