<?php

declare(strict_types=1);

use Calibre\Database\MigrationRunner;
use Calibre\ScanService;
use Calibre\Services\AuthService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "init_runtime.php is CLI-only.\n";
    exit(1);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Calibre\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    if ($relativeClass === false || $relativeClass === '') {
        return;
    }

    $filePath = __DIR__
        . DIRECTORY_SEPARATOR
        . 'src'
        . DIRECTORY_SEPARATOR
        . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass)
        . '.php';

    if (is_file($filePath)) {
        require_once $filePath;
    }
});

try {
    $appRoot = __DIR__;
    $scanService = new ScanService($appRoot);
    $authService = new AuthService($appRoot, $scanService);
    $runner = new MigrationRunner($appRoot);

    $prepareSqliteFile = static function (string $dbPath): void {
        $directory = dirname($dbPath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create sqlite directory: {$directory}");
        }

        @chmod($directory, 0777);

        if (!is_file($dbPath)) {
            @touch($dbPath);
        }

        @chmod($dbPath, 0666);
        clearstatcache(true, $dbPath);
        if (!is_writable($dbPath)) {
            throw new RuntimeException("SQLite file is not writable: {$dbPath}");
        }
    };

    $prepareSqliteFile($authService->getSettingsDbPath());
    $prepareSqliteFile($scanService->getSqlitePath());

    // Always initialize the settings/runtime DB first so auth tables,
    // scan_jobs, integrity checks, and migration recovery all finish before
    // web/cron services start, even when auth is disabled or bootstrap
    // credentials are intentionally empty.
    $authService->ensureSettingsDatabaseReady();

    // Then sync the bootstrap admin when auth is enabled and credentials are
    // configured.
    $authService->ensureBootstrapUser();

    $libraryPdo = new PDO('sqlite:' . $scanService->getSqlitePath());
    $libraryPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $runner->migrateLibrary($libraryPdo);

    fwrite(STDOUT, "[init_runtime] runtime initialization completed.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[init_runtime] failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
