<?php

namespace Calibre\Support;

final class CatalogUrlGenerator
{
    private string $query;
    private int $perPage;
    private string $sortField;
    private string $sortDirection;

    public function __construct(string $query, int $perPage, string $sortField, string $sortDirection)
    {
        $this->query = $query;
        $this->perPage = $perPage;
        $this->sortField = CatalogRequest::normalizeSortField($sortField);
        $this->sortDirection = CatalogRequest::normalizeSortDirection($sortDirection);
    }

    public function page(int $page): string
    {
        return $this->build(['page' => max(1, $page)]);
    }

    public function sort(string $targetField): string
    {
        $targetField = CatalogRequest::normalizeSortField($targetField);
        $nextDirection = 'asc';

        if ($this->sortField === $targetField) {
            $nextDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        }

        return $this->build([
            'page' => 1,
            'sort' => $targetField,
            'direction' => $nextDirection,
        ]);
    }

    public function clear(): string
    {
        return $this->build([
            'q' => '',
            'page' => 1,
        ]);
    }

    public function search(string $query): string
    {
        return $this->build([
            'q' => $query,
            'page' => 1,
        ]);
    }

    public function perPage(int $perPage): string
    {
        return $this->build([
            'per_page' => $perPage,
            'page' => 1,
        ]);
    }

    private function build(array $overrides): string
    {
        $params = [
            'q' => $this->query,
            'per_page' => $this->perPage,
            'sort' => $this->sortField,
            'direction' => $this->sortDirection,
            'page' => 1,
        ];

        foreach ($overrides as $key => $value) {
            $params[$key] = $value;
        }

        $params['sort'] = CatalogRequest::normalizeSortField((string) $params['sort']);
        $params['direction'] = CatalogRequest::normalizeSortDirection((string) $params['direction']);
        $params['page'] = max(1, (int) $params['page']);
        $params['per_page'] = in_array((int) $params['per_page'], CatalogRequest::PER_PAGE_OPTIONS, true)
            ? (int) $params['per_page']
            : 20;

        $query = trim((string) $params['q']);
        if ($query === '') {
            unset($params['q']);
        } else {
            $params['q'] = $query;
        }

        if ($params['page'] <= 1) {
            unset($params['page']);
        }

        $queryString = http_build_query($params);

        return $queryString === '' ? 'index.php' : 'index.php?' . $queryString;
    }
}
