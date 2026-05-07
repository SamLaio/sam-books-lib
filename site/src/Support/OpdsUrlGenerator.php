<?php

namespace Calibre\Support;

final class OpdsUrlGenerator
{
    private ?string $baseUrl;
    private string $basePath;
    private string $baseDirectory;

    public function __construct(array $server, ?string $configuredBaseUrl = null)
    {
        $this->baseUrl = $this->resolveBaseUrl($server, $configuredBaseUrl);
        $this->basePath = $this->buildBasePath($server);
        $this->baseDirectory = $this->buildBaseDirectory($this->basePath);
    }

    public function index(): string
    {
        return $this->toAbsoluteUrl($this->basePath);
    }

    public function feed(string $feed = 'index', array $params = []): string
    {
        $query = $params;

        if ($feed !== 'index') {
            $query = ['feed' => $feed] + $query;
        }

        if ($query === []) {
            return $this->toAbsoluteUrl($this->basePath);
        }

        return $this->toAbsoluteUrl($this->basePath . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public function searchDescription(): string
    {
        return $this->feed('osd');
    }

    public function download(int $bookId, string $format, string $name = ''): string
    {
        $path = rtrim($this->basePath, '/')
            . '/download/'
            . rawurlencode((string) $bookId)
            . '/'
            . rawurlencode($format);

        if ($name !== '') {
            $path .= '/' . rawurlencode($name);
        }

        return $this->toAbsoluteUrl($path);
    }

    public function searchTemplate(): string
    {
        $searchFeedUrl = $this->feed('search');
        $separator = str_contains($searchFeedUrl, '?') ? '&' : '?';

        return $searchFeedUrl . $separator . 'query={searchTerms}';
    }

    public function asset(string $path): string
    {
        $cleanPath = ltrim($path, '/');

        if ($this->baseDirectory === '/') {
            return $this->toAbsoluteUrl('/' . $cleanPath);
        }

        return $this->toAbsoluteUrl($this->baseDirectory . '/' . $cleanPath);
    }

    private function buildBasePath(array $server): string
    {
        $opdsBasePath = trim((string) ($server['OPDS_BASE_PATH'] ?? ''));
        if ($opdsBasePath !== '') {
            return $opdsBasePath[0] === '/' ? $opdsBasePath : '/' . $opdsBasePath;
        }

        $requestUri = trim((string) ($server['REQUEST_URI'] ?? ''));
        if ($requestUri !== '') {
            $path = parse_url($requestUri, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                return $path[0] === '/' ? $path : '/' . $path;
            }
        }

        $scriptName = trim((string) ($server['SCRIPT_NAME'] ?? '/opds.php'));
        if ($scriptName === '') {
            return '/opds.php';
        }

        return $scriptName[0] === '/' ? $scriptName : '/' . $scriptName;
    }

    private function buildBaseDirectory(string $basePath): string
    {
        $directory = str_replace('\\', '/', dirname($basePath));

        if ($directory === '.' || $directory === '') {
            return '/';
        }

        return $directory[0] === '/' ? rtrim($directory, '/') ?: '/' : '/' . trim($directory, '/');
    }

    private function toAbsoluteUrl(string $path): string
    {
        if ($this->baseUrl === null || $this->baseUrl === '') {
            return $path;
        }

        $parsedBaseUrl = parse_url($this->baseUrl);
        $basePath = (string) ($parsedBaseUrl['path'] ?? '');

        if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, $basePath . '/')) {
            $origin = $this->buildOrigin($parsedBaseUrl);
            return $origin . $path;
        }

        return $this->baseUrl . $path;
    }

    private function resolveBaseUrl(array $server, ?string $configuredBaseUrl): ?string
    {
        $configured = $configuredBaseUrl === null ? '' : rtrim(trim($configuredBaseUrl), '/');
        $requestBaseUrl = $this->buildRequestBaseUrl($server);

        if ($configured === '') {
            return $requestBaseUrl;
        }

        $configuredHost = (string) (parse_url($configured, PHP_URL_HOST) ?? '');
        $requestHost = (string) (parse_url((string) $requestBaseUrl, PHP_URL_HOST) ?? '');

        if ($this->isLoopbackHost($configuredHost) && $requestHost !== '' && !$this->isLoopbackHost($requestHost)) {
            return $requestBaseUrl;
        }

        return $configured;
    }

    private function buildRequestBaseUrl(array $server): ?string
    {
        $host = trim((string) ($server['HTTP_X_FORWARDED_HOST'] ?? $server['HTTP_HOST'] ?? ''));
        if ($host === '') {
            $serverName = trim((string) ($server['SERVER_NAME'] ?? ''));
            if ($serverName === '') {
                return null;
            }

            $port = (string) ($server['SERVER_PORT'] ?? '');
            $host = $serverName;
            if ($port !== '' && !in_array($port, ['80', '443'], true)) {
                $host .= ':' . $port;
            }
        }

        $forwardedProto = trim((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $isHttps = $forwardedProto !== ''
            ? strtolower($forwardedProto) === 'https'
            : (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off');
        $scheme = $isHttps ? 'https' : 'http';

        return $scheme . '://' . $host;
    }

    private function isLoopbackHost(string $host): bool
    {
        $normalized = strtolower(trim($host, '[]'));
        if ($normalized === '') {
            return false;
        }

        if ($normalized === 'localhost' || $normalized === '::1') {
            return true;
        }

        return str_starts_with($normalized, '127.');
    }

    private function buildOrigin(array $parsedBaseUrl): string
    {
        $scheme = (string) ($parsedBaseUrl['scheme'] ?? 'http');
        $host = (string) ($parsedBaseUrl['host'] ?? '');
        $port = isset($parsedBaseUrl['port']) ? ':' . (string) $parsedBaseUrl['port'] : '';

        return $scheme . '://' . $host . $port;
    }
}
