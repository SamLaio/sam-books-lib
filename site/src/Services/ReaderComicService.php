<?php

namespace Calibre\Services;

use Calibre\Http\HttpException;
use Calibre\Support\Lang;

final class ReaderComicService
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif'];

    public function buildManifest(int $bookId, array $book, string $cbzPath): array
    {
        $entries = $this->listPages($cbzPath);
        if ($entries === []) {
            throw new HttpException(404, Lang::t('error.reader_readable_not_found'));
        }

        $sections = [];
        $toc = [];

        foreach ($entries as $index => $entry) {
            $sectionId = 'page-' . ($index + 1);
            $label = trim((string) pathinfo($entry, PATHINFO_FILENAME));
            if ($label === '') {
                $label = 'Page ' . ($index + 1);
            }

            $imageUrl = 'reader_asset.php?id=' . $bookId . '&comic=' . rawurlencode($entry);
            $sections[] = [
                'id' => $sectionId,
                'label' => $label,
                'image_url' => $imageUrl,
                'page_number' => $index + 1,
            ];
            $toc[] = [
                'id' => $sectionId,
                'label' => $label,
                'section' => $sectionId,
                'fragment' => '',
            ];
        }

        return [
            'book_id' => $bookId,
            'title' => trim((string) ($book['title'] ?? '')),
            'format' => 'cbz',
            'reading_mode' => 'comic-pages',
            'toc' => $toc,
            'sections' => $sections,
            'initial_section' => (string) ($sections[0]['id'] ?? ''),
            'initial_fragment' => '',
        ];
    }

    public function resolvePageBinary(string $cbzPath, string $entryName): array
    {
        $entryName = $this->normalizeEntryPath($entryName);
        if ($entryName === '') {
            throw new HttpException(404, Lang::t('error.reader_asset_not_found'));
        }

        $zip = new \ZipArchive();
        if ($zip->open($cbzPath) !== true) {
            throw new HttpException(404, Lang::t('error.reader_asset_not_found'));
        }

        try {
            $content = $zip->getFromName($entryName);
            if (!is_string($content)) {
                throw new HttpException(404, Lang::t('error.reader_asset_not_found'));
            }

            return [
                'mime_type' => $this->detectMimeType($entryName),
                'content' => $content,
            ];
        } finally {
            $zip->close();
        }
    }

    private function listPages(string $cbzPath): array
    {
        if (!extension_loaded('zip')) {
            throw new HttpException(500, Lang::t('error.reader_readable_not_found'));
        }

        $zip = new \ZipArchive();
        if ($zip->open($cbzPath) !== true) {
            throw new HttpException(404, Lang::t('error.reader_readable_not_found'));
        }

        try {
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index += 1) {
                $name = (string) $zip->getNameIndex($index);
                $normalized = $this->normalizeEntryPath($name);
                if ($normalized === '') {
                    continue;
                }

                $extension = strtolower((string) pathinfo($normalized, PATHINFO_EXTENSION));
                if (!in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                    continue;
                }

                $entries[] = $normalized;
            }

            natcasesort($entries);
            return array_values($entries);
        } finally {
            $zip->close();
        }
    }

    private function normalizeEntryPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? '';
        $path = ltrim($path, '/');

        if ($path === '' || str_ends_with($path, '/')) {
            return '';
        }

        $segments = array_values(array_filter(explode('/', $path), static function (string $segment): bool {
            return $segment !== '' && $segment !== '.' && $segment !== '..';
        }));

        return implode('/', $segments);
    }

    private function detectMimeType(string $entryName): string
    {
        return match (strtolower((string) pathinfo($entryName, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'avif' => 'image/avif',
            default => 'application/octet-stream',
        };
    }
}
