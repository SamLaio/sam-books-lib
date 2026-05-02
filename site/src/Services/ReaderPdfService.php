<?php

namespace Calibre\Services;

final class ReaderPdfService
{
    public function buildManifest(int $bookId, array $book): array
    {
        return [
            'book_id' => $bookId,
            'title' => trim((string) ($book['title'] ?? '')),
            'format' => 'pdf',
            'reading_mode' => 'pdf-document',
            'toc' => [],
            'sections' => [],
            'initial_section' => '',
            'initial_fragment' => '',
            'document_url' => 'reader_asset.php?id=' . $bookId . '&document=1',
        ];
    }
}
