<?php

namespace Calibre;

final class ScanService
{
    public const JOB_TYPE_SCAN = 'rebuild';
    public const JOB_TYPE_COVER_REBUILD = 'rebuild_cover';

    private string $appRoot;
    private array $config;

    public function __construct(string $appRoot, ?array $config = null)
    {
        $this->appRoot = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->config = $config ?? self::loadConfig($this->appRoot);
    }

    public static function loadConfig(string $appRoot): array
    {
        $configFile = rtrim($appRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config.env';
        if (!file_exists($configFile)) {
            return [];
        }

        $parsedConfig = parse_ini_file($configFile);
        if ($parsedConfig === false) {
            throw new \RuntimeException('Failed to parse config.env');
        }

        return $parsedConfig;
    }

    public static function readSetting(array $config, array $envKeys, string $configKey, ?string $default = null): ?string
    {
        foreach ($envKeys as $envKey) {
            $envValue = getenv($envKey);
            if ($envValue !== false && trim($envValue) !== '') {
                return trim($envValue);
            }
        }

        if (isset($config[$configKey]) && trim((string) $config[$configKey]) !== '') {
            return trim((string) $config[$configKey]);
        }

        return $default;
    }

    public static function isAbsolutePath(string $path): bool
    {
        return (bool) preg_match('/^(?:[A-Za-z]:[\\\\\\/]|[\\\\\\/]{2}|\\/)/', $path);
    }

    public static function resolvePath(string $appRoot, string $rawPath): string
    {
        if (self::isAbsolutePath($rawPath)) {
            return $rawPath;
        }

        return rtrim($appRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rawPath);
    }

    public function getLibraryPath(?string $override = null): string
    {
        $libraryPath = $override;
        if ($libraryPath === null || trim($libraryPath) === '') {
            $libraryPath = self::readSetting(
                $this->config,
                ['BOOKS_CALIBRE_LIBRARY_PATH', 'CALIBRE_LIBRARY_PATH'],
                'CALIBRE_LIBRARY_PATH'
            );
        }

        if ($libraryPath === null || trim($libraryPath) === '') {
            throw new \RuntimeException('CALIBRE_LIBRARY_PATH is not set (env/config.env)');
        }

        return self::resolvePath($this->appRoot, trim($libraryPath));
    }

    public function getSqlitePath(?string $override = null): string
    {
        return $this->getSqliteBasePath($override);
    }

    public function getSqliteBasePath(?string $override = null): string
    {
        $sqlitePath = $override;
        if ($sqlitePath === null || trim($sqlitePath) === '') {
            $sqlitePath = self::readSetting(
                $this->config,
                ['BOOKS_SQLITE_INDEX_PATH', 'SQLITE_INDEX_PATH'],
                'SQLITE_INDEX_PATH',
                'data/library_index.sqlite'
            );
        }

        if ($sqlitePath === null || trim($sqlitePath) === '') {
            throw new \RuntimeException('SQLITE_INDEX_PATH is not set (env/config.env)');
        }

        return self::resolvePath($this->appRoot, trim($sqlitePath));
    }

    public function getThumbDir(): string
    {
        return $this->appRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'thumb';
    }

    public function getLockFile(string $jobType = self::JOB_TYPE_SCAN): string
    {
        return $this->appRoot
            . DIRECTORY_SEPARATOR
            . 'data'
            . DIRECTORY_SEPARATOR
            . 'job.'
            . $this->normalizeJobTypeSuffix($jobType)
            . '.lock';
    }

    public function getLogFile(string $jobType = self::JOB_TYPE_SCAN): string
    {
        $fileName = $jobType === self::JOB_TYPE_COVER_REBUILD
            ? 'cover_rebuild.log'
            : 'scan.log';

        return $this->appRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $fileName;
    }

    public function getScheduleStateFile(): string
    {
        return $this->appRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'job.schedule.json';
    }

    public function getScanResumeFlagFile(): string
    {
        return $this->appRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'scan.resume.json';
    }

    public function getScanRequestFile(): string
    {
        return $this->appRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'scan.request.json';
    }

    public function enqueueScanRequest(string $action = 'rebuild'): array
    {
        $normalizedAction = trim(strtolower($action));
        if ($normalizedAction === '') {
            $normalizedAction = 'rebuild';
        }

        $path = $this->getScanRequestFile();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create scan request directory: {$dir}");
        }

        $existing = $this->getPendingScanRequest();
        $payload = [
            'action' => $normalizedAction,
            'requested_at' => date('c'),
            'request_count' => (int) (($existing['request_count'] ?? 0)) + 1,
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode scan request payload.');
        }

        if (@file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write scan request file: {$path}");
        }

        return $payload;
    }

    public function getPendingScanRequest(): ?array
    {
        $path = $this->getScanRequestFile();
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $action = trim(strtolower((string) ($decoded['action'] ?? '')));
        if ($action === '') {
            $action = 'rebuild';
        }

        return [
            'action' => $action,
            'requested_at' => (string) ($decoded['requested_at'] ?? ''),
            'request_count' => isset($decoded['request_count']) && is_numeric((string) $decoded['request_count'])
                ? (int) $decoded['request_count']
                : 1,
        ];
    }

    public function clearPendingScanRequest(): void
    {
        $path = $this->getScanRequestFile();
        if (!is_file($path)) {
            return;
        }

        @unlink($path);
    }

    public function getScanIntervalMinutes(): int
    {
        $rawInterval = self::readSetting(
            $this->config,
            ['BOOKS_SCAN_INTERVAL_MINUTES', 'SCAN_INTERVAL_MINUTES'],
            'SCAN_INTERVAL_MINUTES',
            '5'
        );

        if ($rawInterval === null || trim($rawInterval) === '') {
            return 5;
        }

        $normalizedInterval = trim($rawInterval);
        if (!preg_match('/^\d+$/', $normalizedInterval)) {
            throw new \RuntimeException('SCAN_INTERVAL_MINUTES must be 0 (disable auto scan) or an integer greater than or equal to 1.');
        }

        $interval = (int) $normalizedInterval;
        if ($interval === 0) {
            return 0;
        }

        if ($interval < 1) {
            throw new \RuntimeException('SCAN_INTERVAL_MINUTES must be 0 (disable auto scan) or an integer greater than or equal to 1.');
        }

        return $interval;
    }

    public function getScanBatchSize(): int
    {
        $rawBatchSize = self::readSetting(
            $this->config,
            ['BOOKS_SCAN_BATCH_SIZE', 'SCAN_BATCH_SIZE'],
            'SCAN_BATCH_SIZE',
            '50'
        );

        if ($rawBatchSize === null || trim($rawBatchSize) === '') {
            return 50;
        }

        $normalizedBatchSize = trim($rawBatchSize);
        if (!ctype_digit($normalizedBatchSize)) {
            throw new \RuntimeException('SCAN_BATCH_SIZE must be an integer between 1 and 1000.');
        }

        $batchSize = (int) $normalizedBatchSize;
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new \RuntimeException('SCAN_BATCH_SIZE must be an integer between 1 and 1000.');
        }

        return $batchSize;
    }

    public function getScanWatchdogTimeoutSeconds(): int
    {
        $rawTimeout = self::readSetting(
            $this->config,
            ['BOOKS_SCAN_WATCHDOG_TIMEOUT_SECONDS', 'SCAN_WATCHDOG_TIMEOUT_SECONDS'],
            'SCAN_WATCHDOG_TIMEOUT_SECONDS',
            '900'
        );

        if ($rawTimeout === null || trim($rawTimeout) === '') {
            return 900;
        }

        $normalizedTimeout = trim($rawTimeout);
        if (!ctype_digit($normalizedTimeout)) {
            throw new \RuntimeException('SCAN_WATCHDOG_TIMEOUT_SECONDS must be an integer between 0 and 86400.');
        }

        $timeout = (int) $normalizedTimeout;
        if ($timeout < 0 || $timeout > 86400) {
            throw new \RuntimeException('SCAN_WATCHDOG_TIMEOUT_SECONDS must be an integer between 0 and 86400.');
        }

        return $timeout;
    }

    public function getScanMaxBooksPerRun(): int
    {
        $rawValue = self::readSetting(
            $this->config,
            ['BOOKS_SCAN_MAX_BOOKS_PER_RUN', 'SCAN_MAX_BOOKS_PER_RUN'],
            'SCAN_MAX_BOOKS_PER_RUN',
            '500'
        );

        if ($rawValue === null || trim($rawValue) === '') {
            return 500;
        }

        $normalizedValue = trim($rawValue);
        if (!ctype_digit($normalizedValue)) {
            throw new \RuntimeException('SCAN_MAX_BOOKS_PER_RUN must be an integer between 0 and 100000.');
        }

        $value = (int) $normalizedValue;
        if ($value < 0 || $value > 100000) {
            throw new \RuntimeException('SCAN_MAX_BOOKS_PER_RUN must be an integer between 0 and 100000.');
        }

        return $value;
    }

    public function getScanTemporarySqlitePath(string $sqlitePath): string
    {
        $rawPath = self::readSetting(
            $this->config,
            ['BOOKS_SCAN_TMP_SQLITE_PATH', 'SCAN_TMP_SQLITE_PATH'],
            'SCAN_TMP_SQLITE_PATH',
            '/tmp/books-scan.tmp.sqlite'
        );

        if ($rawPath === null || trim($rawPath) === '') {
            return dirname($sqlitePath) . DIRECTORY_SEPARATOR . 'tmp.a.sqlite';
        }

        return self::resolvePath($this->appRoot, trim($rawPath));
    }

    public function getSiteTitle(): string
    {
        $defaultTitle = \Calibre\Support\Lang::t('layout.default_title');
        $title = self::readSetting(
            $this->config,
            ['BOOKS_SITE_TITLE', 'SITE_TITLE'],
            'SITE_TITLE',
            $defaultTitle
        );

        return $title === null || trim($title) === '' ? $defaultTitle : trim($title);
    }

    public function getSiteBaseUrl(): ?string
    {
        $baseUrl = self::readSetting(
            $this->config,
            ['BOOKS_SITE_BASE_URL', 'SITE_BASE_URL'],
            'SITE_BASE_URL'
        );

        if ($baseUrl === null || trim($baseUrl) === '') {
            return null;
        }

        $normalized = rtrim(trim($baseUrl), '/');
        if (!preg_match('#^https?://#i', $normalized)) {
            throw new \RuntimeException('SITE_BASE_URL must start with http:// or https://');
        }

        return $normalized;
    }

    public function getOpdsPageSize(): int
    {
        $rawSize = self::readSetting(
            $this->config,
            ['BOOKS_OPDS_PAGE_SIZE', 'OPDS_PAGE_SIZE'],
            'OPDS_PAGE_SIZE',
            '30'
        );

        if ($rawSize === null || trim($rawSize) === '') {
            return 30;
        }

        $normalizedSize = trim($rawSize);
        if (!ctype_digit($normalizedSize)) {
            throw new \RuntimeException('OPDS_PAGE_SIZE must be an integer between 1 and 500.');
        }

        $pageSize = (int) $normalizedSize;
        if ($pageSize < 1 || $pageSize > 500) {
            throw new \RuntimeException('OPDS_PAGE_SIZE must be an integer between 1 and 500.');
        }

        return $pageSize;
    }

    public function getCatalogDefaultSortField(): string
    {
        $value = ScanService::readSetting(
            $this->config,
            ['BOOKS_CATALOG_DEFAULT_SORT_FIELD', 'CATALOG_DEFAULT_SORT_FIELD'],
            'CATALOG_DEFAULT_SORT_FIELD',
            'added_at'
        );

        if ($value === null) {
            return 'added_at';
        }

        return \Calibre\Support\CatalogRequest::normalizeSortField((string) $value);
    }

    public function getCatalogDefaultSortDirection(): string
    {
        $value = ScanService::readSetting(
            $this->config,
            ['BOOKS_CATALOG_DEFAULT_SORT_DIRECTION', 'CATALOG_DEFAULT_SORT_DIRECTION'],
            'CATALOG_DEFAULT_SORT_DIRECTION',
            'desc'
        );

        if ($value === null) {
            return 'desc';
        }

        return \Calibre\Support\CatalogRequest::normalizeSortDirection((string) $value);
    }

    public function reserveScheduledRunIfDue(): array
    {
        $intervalMinutes = $this->getScanIntervalMinutes();
        if ($intervalMinutes === 0) {
            return [
                'due' => false,
                'disabled' => true,
                'interval_minutes' => $intervalMinutes,
                'last_started_at' => null,
                'reserved_started_at' => null,
                'next_run_at' => null,
            ];
        }

        return $this->withScheduleStateLock(function ($handle, array $state) use ($intervalMinutes): array {
            $lastStartedAt = $this->normalizeScheduledRunAt($state['last_started_at'] ?? null);
            $nextRunAt = $this->calculateNextRunAt($lastStartedAt, $intervalMinutes);
            $now = new \DateTimeImmutable();

            if ($nextRunAt !== null) {
                $nextRun = new \DateTimeImmutable($nextRunAt);
                if ($now < $nextRun) {
                    return [
                        'due' => false,
                        'disabled' => false,
                        'interval_minutes' => $intervalMinutes,
                        'last_started_at' => $lastStartedAt,
                        'reserved_started_at' => null,
                        'next_run_at' => $nextRunAt,
                    ];
                }
            }

            $reservedStartedAt = $now->format(DATE_ATOM);
            $this->writeScheduleState($handle, $reservedStartedAt);

            return [
                'due' => true,
                'disabled' => false,
                'interval_minutes' => $intervalMinutes,
                'last_started_at' => $lastStartedAt,
                'reserved_started_at' => $reservedStartedAt,
                'next_run_at' => $this->calculateNextRunAt($reservedStartedAt, $intervalMinutes),
            ];
        });
    }

    public function restoreScheduledRunReservation(?string $lastStartedAt): void
    {
        $this->withScheduleStateLock(function ($handle, array $state) use ($lastStartedAt): void {
            $this->writeScheduleState($handle, $this->normalizeScheduledRunAt($lastStartedAt));
        });
    }

    public function cleanupStaleScanState(?string $sqliteOverride = null): void
    {
        $sqlitePath = $this->getSqliteBasePath($sqliteOverride);
        $scanResumeFlagPath = $this->getScanResumeFlagFile();
        $temporarySqlitePath = $this->getScanTemporarySqlitePath($sqlitePath);
        $legacyTemporarySqlitePath = dirname($sqlitePath) . DIRECTORY_SEPARATOR . 'tmp.a.sqlite';

        $flagTemporaryPath = null;
        if (is_file($scanResumeFlagPath)) {
            $raw = @file_get_contents($scanResumeFlagPath);
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['temporary_sqlite_path']) && is_string($decoded['temporary_sqlite_path'])) {
                    $candidate = trim($decoded['temporary_sqlite_path']);
                    if ($candidate !== '') {
                        $flagTemporaryPath = $candidate;
                    }
                }
            }
        }

        if (is_string($flagTemporaryPath) && $flagTemporaryPath !== '') {
            $this->cleanupSqliteArtifacts($flagTemporaryPath);
        }

        $this->cleanupSqliteArtifacts($temporarySqlitePath);
        if ($this->normalizePathForCompare($legacyTemporarySqlitePath) !== $this->normalizePathForCompare($temporarySqlitePath)) {
            $this->cleanupSqliteArtifacts($legacyTemporarySqlitePath);
        }

        $this->removeScanResumeFlag($scanResumeFlagPath);
        $this->cleanupSqliteArtifacts($sqlitePath . '.swap-backup');
    }

    public function reconcileResumeState(?string $sqliteOverride = null): array
    {
        $sqlitePath = $this->getSqliteBasePath($sqliteOverride);
        $flagPath = $this->getScanResumeFlagFile();
        $temporarySqlitePath = $this->getScanTemporarySqlitePath($sqlitePath);

        $flagExists = is_file($flagPath);
        $temporaryExists = is_file($temporarySqlitePath);
        $cleanedBrokenFlag = false;

        // Broken state: flag exists, but corresponding temporary sqlite is gone.
        if ($flagExists && !$temporaryExists) {
            $this->removeScanResumeFlag($flagPath);
            $cleanedBrokenFlag = true;
            $flagExists = false;
        }

        return [
            'flag_exists' => $flagExists,
            'temporary_exists' => $temporaryExists,
            'cleaned_broken_flag' => $cleanedBrokenFlag,
            'flag_path' => $flagPath,
            'temporary_sqlite_path' => $temporarySqlitePath,
        ];
    }

    public function rebuildIndex(?string $libraryOverride = null, ?string $sqliteOverride = null): array
    {
        $libraryPath = $this->getLibraryPath($libraryOverride);
        $sqlitePath = $this->getSqliteBasePath($sqliteOverride);
        $thumbDir = $this->getThumbDir();
        $this->prepareThumbDirectory($thumbDir, $sqlitePath);

        return $this->withLock(function () use ($libraryPath, $sqlitePath, $thumbDir): array {
            return $this->runRebuild($libraryPath, $sqlitePath, $thumbDir);
        }, self::JOB_TYPE_SCAN);
    }

    public function rebuildNonCalibreCovers(?string $libraryOverride = null, ?string $sqliteOverride = null): array
    {
        $libraryPath = $this->getLibraryPath($libraryOverride);
        $sqlitePath = $this->getSqliteBasePath($sqliteOverride);
        $thumbDir = $this->getThumbDir();
        $this->prepareThumbDirectory($thumbDir, $sqlitePath);

        return $this->withLock(function () use ($libraryPath, $sqlitePath, $thumbDir): array {
            $startedAt = date('c');
            $library = new CalibreLibrary($libraryPath, $thumbDir);
            $index = new LibraryIndex($sqlitePath);

            $processed = 0;
            $updated = 0;
            $skippedCalibre = 0;
            $skippedHasCover = 0;
            $skippedMissingSource = 0;
            $failed = 0;

            try {
                foreach ($index->iterateCoverRegenerationCandidates() as $row) {
                    $processed++;
                    $metadata = is_array($row['metadata'] ?? null) ? (array) $row['metadata'] : [];
                    if (!$this->isNonCalibreBook($metadata)) {
                        $skippedCalibre++;
                        continue;
                    }

                    $coverPath = is_string($row['cover_path'] ?? null) ? trim((string) $row['cover_path']) : '';
                    if ($coverPath !== '' && file_exists($coverPath)) {
                        $skippedHasCover++;
                        continue;
                    }

                    $book = new Book(
                        (string) ($row['title'] ?? ''),
                        (string) ($row['author'] ?? ''),
                        (string) ($row['path'] ?? ''),
                        is_array($row['formats'] ?? null) ? (array) $row['formats'] : [],
                        $metadata,
                        $coverPath !== '' ? $coverPath : null
                    );
                    if (!$this->hasBookSource($book)) {
                        $skippedMissingSource++;
                        continue;
                    }

                    $resolvedCover = $library->ensureBookCover($book);
                    if ($resolvedCover === null || !file_exists($resolvedCover)) {
                        $failed++;
                        continue;
                    }

                    $bookId = (int) ($row['id'] ?? 0);
                    if ($bookId <= 0 || !$index->updateBookCoverPathById($bookId, $resolvedCover)) {
                        $failed++;
                        continue;
                    }

                    $updated++;
                }
            } finally {
                $index->close();
            }

            $removedThumbs = $this->synchronizeThumbDirectory($sqlitePath, $thumbDir);
            $this->cleanupScanTemporaryFiles($sqlitePath);

            return [
                'started_at' => $startedAt,
                'finished_at' => date('c'),
                'library_path' => $libraryPath,
                'sqlite_path' => $sqlitePath,
                'processed_books' => $processed,
                'updated_covers' => $updated,
                'skipped_calibre_books' => $skippedCalibre,
                'skipped_existing_covers' => $skippedHasCover,
                'skipped_missing_source' => $skippedMissingSource,
                'failed_books' => $failed,
                'removed_thumbs' => $removedThumbs,
            ];
        }, self::JOB_TYPE_COVER_REBUILD);
    }

    private function withLock(callable $callback, string $jobType = self::JOB_TYPE_SCAN): array
    {
        $lockFile = $this->getLockFile($jobType);
        $lockDir = dirname($lockFile);

        if (!is_dir($lockDir) && !mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
            throw new \RuntimeException("Cannot create lock directory: {$lockDir}");
        }

        $lockHandle = fopen($lockFile, 'c+');
        if ($lockHandle === false) {
            throw new \RuntimeException("Cannot open lock file: {$lockFile}");
        }

        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            throw new \RuntimeException("Another job is running. lock={$lockFile}");
        }

        try {
            return $callback();
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function runRebuild(string $libraryPath, string $sqlitePath, string $thumbDir): array
    {
        $startedAt = date('c');
        $temporarySqlitePath = $this->buildTemporarySqlitePath($sqlitePath);
        $scanResumeFlagPath = $this->getScanResumeFlagFile();
        $resumeScan = $this->shouldResumeScan($scanResumeFlagPath, $temporarySqlitePath);

        if (!$resumeScan) {
            $this->cleanupSqliteArtifacts($temporarySqlitePath);
        }

        $library = new CalibreLibrary($libraryPath, $thumbDir);
        $existingSnapshots = $this->loadExistingBookSnapshots($sqlitePath);
        if ($existingSnapshots !== []) {
            $library->setPreviousBookCache($existingSnapshots);
        }

        $temporaryIndex = new LibraryIndex($temporarySqlitePath);
        $alreadyIndexedPaths = [];
        if ($resumeScan) {
            foreach ($temporaryIndex->getAllBookPaths() as $path) {
                $alreadyIndexedPaths[$path] = true;
            }
        }

        $this->writeScanResumeFlag($scanResumeFlagPath, [
            'started_at' => $startedAt,
            'last_progress_at' => $startedAt,
            'library_path' => $libraryPath,
            'sqlite_path' => $sqlitePath,
            'temporary_sqlite_path' => $temporarySqlitePath,
            'resume' => $resumeScan,
            'already_saved_books' => count($alreadyIndexedPaths),
        ]);

        $scanMaxBooksPerRun = $this->getScanMaxBooksPerRun();
        $scannedBooks = 0;
        $coverBackfilled = 0;
        $skippedByResume = 0;
        $skippedMissingSource = 0;
        $processedNewFsBooks = 0;
        $reachedRunLimit = false;
        $heartbeatEveryBooks = 25;
        $progressLogEveryBooks = 500;
        $books = $library->iterateLibrary();
        $processedBooks = (function () use ($books, $library, $existingSnapshots, $alreadyIndexedPaths, $scanMaxBooksPerRun, $scanResumeFlagPath, $startedAt, $libraryPath, $sqlitePath, $temporarySqlitePath, $heartbeatEveryBooks, $progressLogEveryBooks, &$scannedBooks, &$coverBackfilled, &$skippedByResume, &$skippedMissingSource, &$processedNewFsBooks, &$reachedRunLimit): \Generator {
            foreach ($books as $book) {
                if (!$book instanceof Book) {
                    continue;
                }

                if (!$this->hasBookSource($book)) {
                    $skippedMissingSource++;
                    continue;
                }

                $scannedBooks++;
                if ($heartbeatEveryBooks > 0 && ($scannedBooks % $heartbeatEveryBooks) === 0) {
                    $this->writeScanResumeFlag($scanResumeFlagPath, [
                        'started_at' => $startedAt,
                        'last_progress_at' => date('c'),
                        'library_path' => $libraryPath,
                        'sqlite_path' => $sqlitePath,
                        'temporary_sqlite_path' => $temporarySqlitePath,
                        'resume' => true,
                        'already_saved_books' => max(0, count($alreadyIndexedPaths) + $processedNewFsBooks),
                        'partial' => true,
                        'scan_max_books_per_run' => $scanMaxBooksPerRun,
                        'scanned_books' => $scannedBooks,
                        'processed_new_fs_books' => $processedNewFsBooks,
                    ]);
                }
                if ($progressLogEveryBooks > 0 && ($scannedBooks % $progressLogEveryBooks) === 0) {
                    $this->appendScanLogLine(
                        sprintf(
                            '[%s] Scan progress: scanned=%d, processed_non_calibre=%d',
                            date('c'),
                            $scannedBooks,
                            $processedNewFsBooks
                        )
                    );
                }
                $normalizedPath = $this->normalizeBookPath($book->getPath());
                if (isset($alreadyIndexedPaths[$normalizedPath])) {
                    $skippedByResume++;
                    continue;
                }

                $metadata = $book->getMetadata();
                $sourceType = strtolower(trim((string) ($metadata['source_type'] ?? '')));
                $isFilesystemOnly = $sourceType !== 'db';
                if ($isFilesystemOnly && $scanMaxBooksPerRun > 0 && $processedNewFsBooks >= $scanMaxBooksPerRun) {
                    $reachedRunLimit = true;
                    break;
                }

                $originalCover = null;
                $bookCoverPath = $book->getCoverPath();
                if (is_string($bookCoverPath)) {
                    $candidateBookCoverPath = trim($bookCoverPath);
                    if ($candidateBookCoverPath !== '' && file_exists($candidateBookCoverPath)) {
                        $originalCover = $candidateBookCoverPath;
                    }
                }
                $snapshot = $existingSnapshots[$normalizedPath] ?? null;

                $snapshotCoverPath = null;
                if (is_array($snapshot) && isset($snapshot['cover_path']) && is_string($snapshot['cover_path'])) {
                    $candidateCoverPath = trim($snapshot['cover_path']);
                    if ($candidateCoverPath !== '' && file_exists($candidateCoverPath)) {
                        $snapshotCoverPath = $candidateCoverPath;
                    }
                }

                if ($originalCover !== null) {
                    $resolvedCover = $originalCover;
                } elseif ($snapshotCoverPath !== null) {
                    // Reuse existing detail-cover if already available to avoid re-extracting archive cover.
                    $resolvedCover = $snapshotCoverPath;
                } else {
                    $resolvedCover = $library->ensureBookCover($book);
                }

                if (($originalCover === null || !file_exists($originalCover))
                    && $resolvedCover !== null
                    && file_exists($resolvedCover)) {
                    $coverBackfilled++;
                }

                if ($isFilesystemOnly) {
                    $processedNewFsBooks++;
                }

                yield new Book(
                    $book->getTitle(),
                    $book->getAuthor(),
                    $book->getPath(),
                    $book->getFormats(),
                    $metadata,
                    $resolvedCover
                );
            }
        })();

        $readStates = $this->loadExistingReadStates($sqlitePath);
        $savedBooks = 0;
        $savedBooksCurrentRun = 0;
        $lastRebuildAt = null;
        $persistedLogEveryBooks = 500;
        $persistProgressCallback = function (int $persistedCount) use ($scanResumeFlagPath, $startedAt, $libraryPath, $sqlitePath, $temporarySqlitePath, $scanMaxBooksPerRun, $scannedBooks, $processedNewFsBooks, $persistedLogEveryBooks): void {
            $this->writeScanResumeFlag($scanResumeFlagPath, [
                'started_at' => $startedAt,
                'last_progress_at' => date('c'),
                'library_path' => $libraryPath,
                'sqlite_path' => $sqlitePath,
                'temporary_sqlite_path' => $temporarySqlitePath,
                'resume' => true,
                'already_saved_books' => max(0, $persistedCount),
                'partial' => true,
                'scan_max_books_per_run' => $scanMaxBooksPerRun,
                'phase' => 'persisting',
                'scanned_books' => $scannedBooks,
                'processed_new_fs_books' => $processedNewFsBooks,
            ]);
            if ($persistedLogEveryBooks > 0 && ($persistedCount % $persistedLogEveryBooks) === 0 && $persistedCount > 0) {
                $this->appendScanLogLine(
                    sprintf(
                        '[%s] Persist progress: saved=%d',
                        date('c'),
                        $persistedCount
                    )
                );
            }
        };

        try {
            if ($resumeScan) {
                $savedBooksCurrentRun = $temporaryIndex->append($processedBooks, $readStates, $this->getScanBatchSize(), $persistProgressCallback);
            } else {
                $savedBooksCurrentRun = $temporaryIndex->rebuild($processedBooks, $readStates, $this->getScanBatchSize(), $persistProgressCallback);
            }
            $savedBooks = $temporaryIndex->countBooks();
            $lastRebuildAt = $temporaryIndex->getLastRebuildAt();
        } finally {
            $temporaryIndex->close();
        }
        $nextRunRequired = $reachedRunLimit;
        $removedThumbs = 0;

        if ($nextRunRequired) {
            $this->writeScanResumeFlag($scanResumeFlagPath, [
                'started_at' => $startedAt,
                'last_progress_at' => date('c'),
                'library_path' => $libraryPath,
                'sqlite_path' => $sqlitePath,
                'temporary_sqlite_path' => $temporarySqlitePath,
                'resume' => true,
                'already_saved_books' => $savedBooks,
                'partial' => true,
                'scan_max_books_per_run' => $scanMaxBooksPerRun,
            ]);
        } else {
            $this->promoteTemporarySqlite($sqlitePath, $temporarySqlitePath);
            $removedThumbs = $this->synchronizeThumbDirectory($sqlitePath, $thumbDir);
            $this->removeScanResumeFlag($scanResumeFlagPath);
            $this->cleanupScanTemporaryFiles($sqlitePath);
        }

        return [
            'started_at' => $startedAt,
            'finished_at' => date('c'),
            'library_path' => $libraryPath,
            'sqlite_path' => $sqlitePath,
            'thumb_dir' => $thumbDir,
            'scanned_books' => $scannedBooks,
            'resumed' => $resumeScan,
            'skipped_by_resume' => $skippedByResume,
            'skipped_missing_source' => $skippedMissingSource,
            'saved_books' => $savedBooks,
            'saved_books_this_run' => $savedBooksCurrentRun,
            'scan_max_books_per_run' => $scanMaxBooksPerRun,
            'processed_books_this_run' => $processedNewFsBooks,
            'next_run_required' => $nextRunRequired,
            'backfilled_covers' => $coverBackfilled,
            'removed_thumbs' => $removedThumbs,
            'last_rebuild_at' => $lastRebuildAt,
        ];
    }

    private function loadExistingReadStates(string $sqlitePath): array
    {
        if (!is_file($sqlitePath)) {
            return [];
        }

        try {
            $index = new LibraryIndex($sqlitePath);

            try {
                return $index->exportReadStatesByPath();
            } finally {
                $index->close();
            }
        } catch (\Throwable) {
            return [];
        }
    }

    private function loadExistingBookSnapshots(string $sqlitePath): array
    {
        if (!is_file($sqlitePath)) {
            return [];
        }

        try {
            $index = new LibraryIndex($sqlitePath);

            try {
                return $index->exportScanSnapshotsByPath();
            } finally {
                $index->close();
            }
        } catch (\Throwable) {
            return [];
        }
    }

    private function buildTemporarySqlitePath(string $sqlitePath): string
    {
        $temporaryPath = $this->getScanTemporarySqlitePath($sqlitePath);
        $temporaryDir = dirname($temporaryPath);
        if (!is_dir($temporaryDir) && !mkdir($temporaryDir, 0755, true) && !is_dir($temporaryDir)) {
            throw new \RuntimeException("Cannot create temporary sqlite directory: {$temporaryDir}");
        }

        return $temporaryPath;
    }

    private function promoteTemporarySqlite(string $basePath, string $temporaryPath): void
    {
        $backupPath = $basePath . '.swap-backup';

        try {
            $this->cleanupSqliteArtifacts($backupPath);

            if (is_file($basePath)) {
                $this->moveSqliteArtifacts($basePath, $backupPath, false);
            }

            $this->moveSqliteArtifacts($temporaryPath, $basePath, false);
            $this->cleanupSqliteArtifacts($temporaryPath);
            $this->cleanupSqliteArtifacts($backupPath);
            $this->cleanupLegacySlotFiles($basePath);
        } catch (\Throwable $e) {
            if (is_file($backupPath)) {
                try {
                    $this->moveSqliteArtifacts($backupPath, $basePath, false);
                } catch (\Throwable) {
                }
            }

            $this->cleanupSqliteArtifacts($temporaryPath);
            throw $e;
        }
    }

    private function moveSqliteArtifacts(string $sourceBasePath, string $targetBasePath, bool $removeSource = true): void
    {
        $artifacts = [
            '' => '',
            '-wal' => '-wal',
            '-shm' => '-shm',
        ];

        foreach ($artifacts as $sourceSuffix => $targetSuffix) {
            $sourcePath = $sourceBasePath . $sourceSuffix;
            if (!is_file($sourcePath)) {
                continue;
            }

            $targetPath = $targetBasePath . $targetSuffix;
            if ($removeSource) {
                if (is_file($targetPath) && !@unlink($targetPath)) {
                    throw new \RuntimeException("Cannot replace sqlite artifact: {$targetPath}");
                }
                $this->transferSqliteArtifact($sourcePath, $targetPath);
                continue;
            }

            if (!@copy($sourcePath, $targetPath)) {
                throw new \RuntimeException("Cannot copy sqlite artifact from {$sourcePath} to {$targetPath}");
            }
        }
    }

    private function transferSqliteArtifact(string $sourcePath, string $targetPath): void
    {
        if (@rename($sourcePath, $targetPath)) {
            return;
        }

        if (!@copy($sourcePath, $targetPath)) {
            throw new \RuntimeException("Cannot move sqlite artifact from {$sourcePath} to {$targetPath}");
        }

        if (!@unlink($sourcePath) && is_file($sourcePath)) {
            @unlink($targetPath);
            throw new \RuntimeException("Cannot remove source sqlite artifact after copy: {$sourcePath}");
        }
    }

    private function cleanupSqliteArtifacts(string $basePath): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            $path = $basePath . $suffix;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function cleanupLegacySlotFiles(string $basePath): void
    {
        $directory = dirname($basePath);
        $extension = pathinfo($basePath, PATHINFO_EXTENSION);
        $filename = pathinfo($basePath, PATHINFO_FILENAME);

        $slotA = $directory . DIRECTORY_SEPARATOR . $filename . '.a' . ($extension !== '' ? '.' . $extension : '');
        $slotB = $directory . DIRECTORY_SEPARATOR . $filename . '.b' . ($extension !== '' ? '.' . $extension : '');

        $this->cleanupSqliteArtifacts($slotA);
        $this->cleanupSqliteArtifacts($slotB);

        $pointerPath = $basePath . '.active';
        if (is_file($pointerPath)) {
            @unlink($pointerPath);
        }
    }

    /**
     * Keep only thumbnails referenced by the latest promoted SQLite index.
     */
    private function synchronizeThumbDirectory(string $sqlitePath, string $thumbDir): int
    {
        if (!is_dir($thumbDir)) {
            return 0;
        }

        $thumbRoot = $this->normalizePathForCompare((string) realpath($thumbDir) ?: $thumbDir);
        $referenced = [];

        if (is_file($sqlitePath)) {
            try {
                $index = new LibraryIndex($sqlitePath);
                try {
                    foreach ($index->getAllCoverPaths() as $coverPath) {
                        $resolvedCoverPath = (string) realpath($coverPath) ?: $coverPath;
                        $normalizedCoverPath = $this->normalizePathForCompare($resolvedCoverPath);
                        if (!$this->isPathInside($normalizedCoverPath, $thumbRoot)) {
                            continue;
                        }
                        $referenced[$normalizedCoverPath] = true;
                    }
                } finally {
                    $index->close();
                }
            } catch (\Throwable) {
                // Keep a conservative behavior if index cannot be read.
                return 0;
            }
        }

        $removedCount = 0;
        $directories = [$thumbDir];
        $files = [];

        while ($directories !== []) {
            $directory = array_pop($directories);
            $entries = @scandir($directory);
            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $entryPath = $directory . DIRECTORY_SEPARATOR . $entry;
                if (is_dir($entryPath)) {
                    $directories[] = $entryPath;
                    continue;
                }

                if (is_file($entryPath)) {
                    $files[] = $entryPath;
                }
            }
        }

        foreach ($files as $filePath) {
            $normalizedItemPath = $this->normalizePathForCompare((string) realpath($filePath) ?: $filePath);
            if (isset($referenced[$normalizedItemPath])) {
                continue;
            }

            if (@unlink($filePath)) {
                $removedCount++;
            }
        }

        return $removedCount;
    }

    private function normalizePathForCompare(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function normalizeBookPath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function shouldResumeScan(string $flagPath, string $temporarySqlitePath): bool
    {
        $flagExists = is_file($flagPath);
        $temporaryExists = is_file($temporarySqlitePath);

        // Broken resume state: flag exists but tmp db is gone. Reset and start clean.
        if ($flagExists && !$temporaryExists) {
            $this->removeScanResumeFlag($flagPath);
            $this->cleanupSqliteArtifacts($temporarySqlitePath);
            return false;
        }

        // tmp db left behind without flag -> treat as stale and start clean.
        if (!$flagExists && $temporaryExists) {
            $this->cleanupSqliteArtifacts($temporarySqlitePath);
            return false;
        }

        if (!$flagExists || !$temporaryExists) {
            return false;
        }

        $raw = @file_get_contents($flagPath);
        if (!is_string($raw) || trim($raw) === '') {
            $this->removeScanResumeFlag($flagPath);
            return false;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->removeScanResumeFlag($flagPath);
            return false;
        }

        $flagTemporaryPath = (string) ($decoded['temporary_sqlite_path'] ?? '');
        if ($flagTemporaryPath === '') {
            $this->removeScanResumeFlag($flagPath);
            return false;
        }

        $matches = $this->normalizePathForCompare($flagTemporaryPath) === $this->normalizePathForCompare($temporarySqlitePath);
        if (!$matches) {
            $this->removeScanResumeFlag($flagPath);
            $this->cleanupSqliteArtifacts($flagTemporaryPath);
            $this->cleanupSqliteArtifacts($temporarySqlitePath);
        }

        return $matches;
    }

    private function writeScanResumeFlag(string $flagPath, array $payload): void
    {
        $flagDirectory = dirname($flagPath);
        if (!is_dir($flagDirectory) && !mkdir($flagDirectory, 0755, true) && !is_dir($flagDirectory)) {
            throw new \RuntimeException("Cannot create scan resume flag directory: {$flagDirectory}");
        }

        if (!isset($payload['updated_at']) || !is_string($payload['updated_at']) || trim($payload['updated_at']) === '') {
            $payload['updated_at'] = date('c');
        }

        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encodedPayload === false) {
            throw new \RuntimeException('Failed to encode scan resume flag payload.');
        }

        if (@file_put_contents($flagPath, $encodedPayload . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write scan resume flag: {$flagPath}");
        }
    }

    public function appendScanLogLine(string $line, string $jobType = self::JOB_TYPE_SCAN): void
    {
        $message = rtrim($line, "\r\n");
        if ($message === '') {
            return;
        }

        $logFile = $this->getLogFile($jobType);
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        @file_put_contents($logFile, $message . PHP_EOL, FILE_APPEND | LOCK_EX);

        // Mirror scan log to container stdout when writable in this runtime.
        // Some images run as non-root and do not allow writing to /proc/1/fd/1.
        if (is_writable('/proc/1/fd/1')) {
            @file_put_contents('/proc/1/fd/1', $message . PHP_EOL, FILE_APPEND);
        }
    }

    private function normalizeJobTypeSuffix(string $jobType): string
    {
        $normalized = strtolower(trim($jobType));
        if ($normalized === '') {
            $normalized = self::JOB_TYPE_SCAN;
        }

        return preg_replace('/[^a-z0-9._-]+/', '_', $normalized) ?? self::JOB_TYPE_SCAN;
    }

    private function removeScanResumeFlag(string $flagPath): void
    {
        if (is_file($flagPath)) {
            @unlink($flagPath);
        }
    }

    private function cleanupScanTemporaryFiles(string $sqlitePath): void
    {
        $temporarySqlitePath = $this->buildTemporarySqlitePath($sqlitePath);
        $this->cleanupSqliteArtifacts($temporarySqlitePath);
        $this->cleanupSqliteArtifacts($sqlitePath . '.swap-backup');
    }

    private function prepareThumbDirectory(string $thumbDir, string $sqlitePath): void
    {
        $thumbParent = dirname($thumbDir);
        if (!is_dir($thumbParent) && !mkdir($thumbParent, 0755, true) && !is_dir($thumbParent)) {
            throw new \RuntimeException("Cannot create thumb parent directory: {$thumbParent}");
        }

        if (!is_dir($thumbDir) && !mkdir($thumbDir, 0755, true) && !is_dir($thumbDir)) {
            throw new \RuntimeException("Cannot create thumb directory: {$thumbDir}");
        }

        $legacyThumbDir = $this->appRoot . DIRECTORY_SEPARATOR . 'thumb';
        if (!is_dir($legacyThumbDir)) {
            return;
        }

        $normalizedLegacy = $this->normalizePathForCompare((string) realpath($legacyThumbDir) ?: $legacyThumbDir);
        $normalizedCurrent = $this->normalizePathForCompare((string) realpath($thumbDir) ?: $thumbDir);
        if ($normalizedLegacy === $normalizedCurrent) {
            return;
        }

        $this->migrateDirectoryContents($legacyThumbDir, $thumbDir);
        $this->removeEmptyDirectoryTree($legacyThumbDir);
        $this->remapLegacyCoverPaths($sqlitePath, $normalizedLegacy, $normalizedCurrent);
    }

    private function migrateDirectoryContents(string $sourceDir, string $targetDir): void
    {
        $entries = @scandir($sourceDir);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . $entry;
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($sourcePath)) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                    continue;
                }
                $this->migrateDirectoryContents($sourcePath, $targetPath);
                continue;
            }

            if (!is_file($sourcePath)) {
                continue;
            }

            if (@rename($sourcePath, $targetPath)) {
                continue;
            }

            if (@copy($sourcePath, $targetPath)) {
                @unlink($sourcePath);
            }
        }
    }

    private function removeEmptyDirectoryTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = @scandir($path);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $childPath = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($childPath)) {
                $this->removeEmptyDirectoryTree($childPath);
            }
        }

        $remaining = @scandir($path);
        if (is_array($remaining) && count($remaining) === 2) {
            @rmdir($path);
        }
    }

    private function remapLegacyCoverPaths(string $sqlitePath, string $legacyThumbDir, string $currentThumbDir): void
    {
        if (!is_file($sqlitePath)) {
            return;
        }

        try {
            $pdo = new \PDO("sqlite:{$sqlitePath}");
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $selectStmt = $pdo->prepare(
                'SELECT id, cover_path
                 FROM books
                 WHERE cover_path IS NOT NULL
                   AND TRIM(cover_path) != ""
                   AND cover_path LIKE :prefix'
            );
            $updateStmt = $pdo->prepare(
                'UPDATE books
                 SET cover_path = :cover_path
                 WHERE id = :id'
            );

            $selectStmt->execute([
                ':prefix' => $legacyThumbDir . '/%',
            ]);

            foreach ($selectStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $bookId = isset($row['id']) ? (int) $row['id'] : 0;
                $coverPath = str_replace('\\', '/', (string) ($row['cover_path'] ?? ''));
                if ($bookId <= 0 || $coverPath === '') {
                    continue;
                }

                if (!str_starts_with($coverPath, $legacyThumbDir . '/')) {
                    continue;
                }

                $newPath = $currentThumbDir . substr($coverPath, strlen($legacyThumbDir));
                $updateStmt->execute([
                    ':id' => $bookId,
                    ':cover_path' => $newPath,
                ]);
            }
        } catch (\Throwable) {
            // If remap fails, next scan can still regenerate covers.
        }
    }

    private function isNonCalibreBook(array $metadata): bool
    {
        $sourceType = strtolower(trim((string) ($metadata['source_type'] ?? '')));
        if ($sourceType !== '') {
            return $sourceType !== 'db';
        }

        if (array_key_exists('has_cover', $metadata)) {
            return false;
        }

        return true;
    }

    private function hasBookSource(Book $book): bool
    {
        $bookPath = trim($book->getPath());
        return $bookPath !== '' && is_file($bookPath);
    }

    private function isPathInside(string $path, string $basePath): bool
    {
        if ($path === $basePath) {
            return true;
        }

        return str_starts_with($path, $basePath . '/');
    }

    private function withScheduleStateLock(callable $callback)
    {
        $scheduleFile = $this->getScheduleStateFile();
        $scheduleDir = dirname($scheduleFile);

        if (!is_dir($scheduleDir) && !mkdir($scheduleDir, 0755, true) && !is_dir($scheduleDir)) {
            throw new \RuntimeException("Cannot create schedule directory: {$scheduleDir}");
        }

        $handle = fopen($scheduleFile, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open schedule state file: {$scheduleFile}");
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new \RuntimeException("Cannot lock schedule state file: {$scheduleFile}");
        }

        try {
            rewind($handle);
            $rawContents = stream_get_contents($handle);

            $state = [];
            if ($rawContents !== false) {
                $trimmedContents = trim($rawContents);
                if ($trimmedContents !== '') {
                    $decoded = json_decode($trimmedContents, true);
                    if (is_array($decoded)) {
                        $state = $decoded;
                    }
                }
            }

            return $callback($handle, $state);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function writeScheduleState($handle, ?string $lastStartedAt): void
    {
        $payload = [];
        if ($lastStartedAt !== null) {
            $payload['last_started_at'] = $lastStartedAt;
        }

        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($encodedPayload === false) {
            throw new \RuntimeException('Failed to encode scheduled run state.');
        }

        rewind($handle);
        if (!ftruncate($handle, 0)) {
            throw new \RuntimeException('Failed to truncate scheduled run state file.');
        }

        if (fwrite($handle, $encodedPayload . PHP_EOL) === false) {
            throw new \RuntimeException('Failed to write scheduled run state file.');
        }

        fflush($handle);
    }

    private function normalizeScheduledRunAt(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalizedValue = trim($value);
        if ($normalizedValue === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($normalizedValue))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    private function calculateNextRunAt(?string $lastStartedAt, int $intervalMinutes): ?string
    {
        if ($lastStartedAt === null) {
            return null;
        }

        return (new \DateTimeImmutable($lastStartedAt))
            ->modify(sprintf('+%d minutes', $intervalMinutes))
            ->format(DATE_ATOM);
    }
}
