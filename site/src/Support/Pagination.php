<?php

namespace Calibre\Support;

final class Pagination
{
    public static function pages(int $currentPage, int $totalPages, int $radius = 2): array
    {
        if ($totalPages <= 1) {
            return [];
        }

        $pages = [1, $totalPages];
        for ($page = $currentPage - $radius; $page <= $currentPage + $radius; $page++) {
            if ($page >= 1 && $page <= $totalPages) {
                $pages[] = $page;
            }
        }

        $pages = array_values(array_unique($pages));
        sort($pages);

        return $pages;
    }
}
