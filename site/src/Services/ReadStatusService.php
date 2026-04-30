<?php

namespace Calibre\Services;

use Calibre\Http\HttpException;
use Calibre\LibraryIndex;
use Calibre\ScanService;
use Calibre\Support\Lang;

final class ReadStatusService
{
    private ScanService $scanService;

    public function __construct(string $appRoot, ?ScanService $scanService = null)
    {
        $this->scanService = $scanService ?? new ScanService($appRoot);
    }

    public function update(int $bookId, bool $isRead): array
    {
        $index = new LibraryIndex($this->scanService->getSqlitePath());
        $updated = $index->setReadStatus($bookId, $isRead);

        if (!$updated) {
            throw new HttpException(404, Lang::t('error.book_not_found'));
        }

        return [
            'ok' => true,
            'id' => $bookId,
            'is_read' => $isRead,
        ];
    }
}
