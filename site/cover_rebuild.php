<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Calibre\ScanService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "cover_rebuild.php is CLI-only.\n";
    exit;
}

$libraryOverride = $argv[1] ?? null;
$sqliteOverride = $argv[2] ?? null;

try {
    $scanService = new ScanService(__DIR__);
    $result = $scanService->rebuildNonCalibreCovers($libraryOverride, $sqliteOverride);

    fwrite(STDOUT, 'Started at: ' . $result['started_at'] . PHP_EOL);
    fwrite(STDOUT, 'Finished at: ' . $result['finished_at'] . PHP_EOL);
    fwrite(STDOUT, 'Processed books: ' . $result['processed_books'] . PHP_EOL);
    fwrite(STDOUT, 'Updated covers: ' . $result['updated_covers'] . PHP_EOL);
    fwrite(STDOUT, 'Skipped calibre books: ' . $result['skipped_calibre_books'] . PHP_EOL);
    fwrite(STDOUT, 'Skipped existing covers: ' . $result['skipped_existing_covers'] . PHP_EOL);
    fwrite(STDOUT, 'Skipped missing source: ' . ($result['skipped_missing_source'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'Failed books: ' . $result['failed_books'] . PHP_EOL);
    fwrite(STDOUT, 'Removed thumbs: ' . $result['removed_thumbs'] . PHP_EOL);
} catch (\Throwable $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
