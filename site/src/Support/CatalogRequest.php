<?php

namespace Calibre\Support;

final class CatalogRequest
{
    public const PER_PAGE_OPTIONS = [20, 50, 100, 500];
    public const ALLOWED_SORT_FIELDS = ['is_read', 'title', 'author', 'series', 'added_at'];

    private string $query;
    private int $perPage;
    private int $requestedPage;
    private string $sortField;
    private string $sortDirection;
    private bool $rebuildRequested;
    private bool $coverRebuildRequested;

    private function __construct(
        string $query,
        int $perPage,
        int $requestedPage,
        string $sortField,
        string $sortDirection,
        bool $rebuildRequested,
        bool $coverRebuildRequested
    ) {
        $this->query = $query;
        $this->perPage = $perPage;
        $this->requestedPage = $requestedPage;
        $this->sortField = $sortField;
        $this->sortDirection = $sortDirection;
        $this->rebuildRequested = $rebuildRequested;
        $this->coverRebuildRequested = $coverRebuildRequested;
    }

    public static function fromGlobals(
        array $server,
        array $get,
        array $post,
        string $defaultSortField = 'added_at',
        string $defaultSortDirection = 'desc'
    ): self
    {
        $requestMethod = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $source = $requestMethod === 'POST' ? $post : $get;

        $query = self::normalizeQuery((string) ($source['q'] ?? ''));
        $perPage = (int) ($source['per_page'] ?? 20);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 20;
        }

        $requestedPage = max(1, (int) ($source['page'] ?? 1));
        $sortField = self::normalizeSortField((string) ($source['sort'] ?? $defaultSortField));
        $sortDirection = self::normalizeSortDirection((string) ($source['direction'] ?? $defaultSortDirection));
        $action = trim((string) ($post['action'] ?? ''));
        $rebuildRequested = $requestMethod === 'POST' && $action === 'rebuild';
        $coverRebuildRequested = $requestMethod === 'POST' && $action === 'rebuild_cover';

        return new self(
            $query,
            $perPage,
            $requestedPage,
            $sortField,
            $sortDirection,
            $rebuildRequested,
            $coverRebuildRequested
        );
    }

    public static function normalizeQuery(string $query): string
    {
        $normalized = str_replace(["\r", "\n", "\t"], ' ', $query);
        $normalized = preg_replace('/[[:cntrl:]]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/[=]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\|{3,}/u', ' || ', $normalized) ?? $normalized;
        $normalized = preg_replace('/(?<!\|)\|(?!\|)/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\+{2,}/u', ' + ', $normalized) ?? $normalized;
        $normalized = preg_replace('/-{2,}/u', ' - ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\(\s*\)+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    public static function normalizeSortField(string $sortField): string
    {
        return in_array($sortField, self::ALLOWED_SORT_FIELDS, true) ? $sortField : 'added_at';
    }

    public static function normalizeSortDirection(string $sortDirection): string
    {
        return strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getPerPage(): int
    {
        return $this->perPage;
    }

    public function getRequestedPage(): int
    {
        return $this->requestedPage;
    }

    public function getSortField(): string
    {
        return $this->sortField;
    }

    public function getSortDirection(): string
    {
        return $this->sortDirection;
    }

    public function isRebuildRequested(): bool
    {
        return $this->rebuildRequested;
    }

    public function isCoverRebuildRequested(): bool
    {
        return $this->coverRebuildRequested;
    }
}
