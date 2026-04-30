<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Calibre\ScanService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "scan.php is CLI-only.\n";
    exit(1);
}

set_time_limit(0);
ignore_user_abort(true);

$libraryOverride = $argv[1] ?? null;
$sqliteOverride = $argv[2] ?? null;

try {
    $scanService = new ScanService(__DIR__);
    $libraryPath = $scanService->getLibraryPath($libraryOverride);
    $sqlitePath = $scanService->getSqlitePath($sqliteOverride);
    $thumbDir = $scanService->getThumbDir();

    fwrite(STDOUT, '[' . date('c') . "] Scan started\n");
    fwrite(STDOUT, "Library: {$libraryPath}\n");
    fwrite(STDOUT, "SQLite: {$sqlitePath}\n");
    fwrite(STDOUT, "Thumb:  {$thumbDir}\n");

    $result = $scanService->rebuildIndex($libraryOverride, $sqliteOverride);

    fwrite(STDOUT, '[' . $result['finished_at'] . "] Scan completed\n");
    fwrite(STDOUT, 'Scanned books: ' . $result['scanned_books'] . PHP_EOL);
    fwrite(STDOUT, 'Saved books: ' . $result['saved_books'] . PHP_EOL);
    fwrite(STDOUT, 'Processed non-calibre this run: ' . ($result['processed_books_this_run'] ?? $result['saved_books_this_run'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'Non-calibre per-run limit: ' . ($result['scan_max_books_per_run'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'Need next run: ' . (!empty($result['next_run_required']) ? 'yes' : 'no') . PHP_EOL);
    fwrite(STDOUT, 'Skipped missing source: ' . ($result['skipped_missing_source'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'Backfilled covers: ' . $result['backfilled_covers'] . PHP_EOL);
    fwrite(STDOUT, 'Removed thumbs: ' . ($result['removed_thumbs'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'Last rebuild at: ' . ($result['last_rebuild_at'] ?? 'null') . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Scan failed: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    exit(1);
}
