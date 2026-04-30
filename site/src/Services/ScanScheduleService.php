<?php

namespace Calibre\Services;

use Calibre\Database\MigrationRunner;
use Calibre\ScanService;

final class ScanScheduleService
{
    public const PENDING_EXPIRE_SECONDS = 600;

    private string $sqlitePath;
    private \PDO $pdo;
    /** @var resource|null */
    private $runtimeDbLockHandle = null;

    public function __construct(string $appRoot, ?string $sqlitePath = null)
    {
        $appRoot = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->sqlitePath = $sqlitePath ?? $this->resolveRuntimeDbPath($appRoot);

        $dir = dirname($this->sqlitePath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create job schedule directory: {$dir}");
        }

        $runner = new MigrationRunner($appRoot);
        $lockHandle = $this->acquireRuntimeDbLock();

        try {
            try {
                $this->pdo = new \PDO('sqlite:' . $this->sqlitePath);
                $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $runner->migrateAuth($this->pdo);
            } catch (\Throwable $e) {
                if (!MigrationRunner::isChecksumMismatchException($e)) {
                    throw $e;
                }

                unset($this->pdo);
                $backups = $runner->recoverVersionMismatch('auth');
                $this->logVersionMismatchRecovery($this->sqlitePath, $backups, $e);
                $this->pdo = new \PDO('sqlite:' . $this->sqlitePath);
                $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                (new MigrationRunner($appRoot))->migrateAuth($this->pdo);
            }

            $this->verifyScheduleSchema();
            $this->importLegacyScheduleDb($appRoot);
        } finally {
            $this->releaseRuntimeDbLock($lockHandle);
        }
    }

    private function resolveRuntimeDbPath(string $appRoot): string
    {
        $config = ScanService::loadConfig($appRoot);
        $settingsDb = ScanService::readSetting(
            $config,
            ['BOOKS_AUTH_SETTINGS_DB_PATH', 'AUTH_SETTINGS_DB_PATH'],
            'AUTH_SETTINGS_DB_PATH',
            'data/auth_settings.sqlite'
        );

        if ($settingsDb === null || trim($settingsDb) === '') {
            $settingsDb = 'data/auth_settings.sqlite';
        }

        return ScanService::resolvePath($appRoot, trim($settingsDb));
    }

    public function enqueueManual(string $action = 'rebuild', int $delaySeconds = 30, array $payload = []): array
    {
        $normalizedAction = trim(strtolower($action));
        if ($normalizedAction === '') {
            $normalizedAction = 'rebuild';
        }

        $delaySeconds = max(0, $delaySeconds);
        // Manual schedule is queued for near-term execution by cron, not triggered inline by HTTP request.
        $runAt = (new \DateTimeImmutable('now'))->modify('+' . $delaySeconds . ' seconds');

        if ($normalizedAction === 'rebuild' && $payload === []) {
            $existingAuto = $this->findPendingAutoBeforeOrAt($runAt);
            if (is_array($existingAuto) && isset($existingAuto['id'])) {
                $jobId = (int) $existingAuto['id'];
                if ($jobId > 0) {
                    $stmt = $this->pdo->prepare(
                        'UPDATE scan_jobs
                         SET source = :source, action = :action
                         WHERE id = :id AND status = :status'
                    );
                    $stmt->execute([
                        ':source' => 'manual',
                        ':action' => $normalizedAction,
                        ':id' => $jobId,
                        ':status' => 'pending',
                    ]);

                    $row = $this->getJobById($jobId);
                    if (is_array($row)) {
                        return $row;
                    }
                }
            }
        }

        return $this->insertPendingJob($normalizedAction, $runAt, 'manual', $payload);
    }

    public function enqueueManualAfterAllJobs(string $action, int $delaySeconds = 60, array $payload = []): array
    {
        $normalizedAction = trim(strtolower($action));
        if ($normalizedAction === '') {
            $normalizedAction = 'rebuild';
        }

        $delaySeconds = max(0, $delaySeconds);
        $runAt = (new \DateTimeImmutable('now'))->modify('+' . $delaySeconds . ' seconds');
        $lastScheduledAt = $this->getLatestScheduledRunAt();
        if ($lastScheduledAt instanceof \DateTimeImmutable && $lastScheduledAt >= $runAt) {
            $runAt = $lastScheduledAt->modify('+' . max(60, $delaySeconds) . ' seconds');
        }

        return $this->insertPendingJob($normalizedAction, $runAt, 'manual', $payload);
    }

    public function ensureNextAutoSchedule(int $intervalMinutes): ?array
    {
        if ($intervalMinutes <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, action, run_at, source, status, payload
             FROM scan_jobs
             WHERE source = :source AND status = :status
             ORDER BY run_at ASC, id ASC
             LIMIT 1'
        );
        $stmt->execute([
            ':source' => 'auto',
            ':status' => 'pending',
        ]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (is_array($existing)) {
            return $existing;
        }

        $runAt = (new \DateTimeImmutable('now'))->modify('+' . $intervalMinutes . ' minutes');
        return $this->insertPendingJob('rebuild', $runAt, 'auto');
    }

    public function ensureNextAutoScheduleFromRunAt(string $currentRunAt, int $intervalMinutes): ?array
    {
        if ($intervalMinutes <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, action, run_at, source, status, payload
             FROM scan_jobs
             WHERE source = :source AND status = :status
             ORDER BY run_at ASC, id ASC
             LIMIT 1'
        );
        $stmt->execute([
            ':source' => 'auto',
            ':status' => 'pending',
        ]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (is_array($existing)) {
            return $existing;
        }

        $baseTime = strtotime($currentRunAt);
        $base = $baseTime === false
            ? new \DateTimeImmutable('now')
            : (new \DateTimeImmutable())->setTimestamp($baseTime);

        $runAt = $base->modify('+' . $intervalMinutes . ' minutes');
        $minimum = (new \DateTimeImmutable('now'))->modify('+5 seconds');
        if ($runAt < $minimum) {
            $runAt = $minimum;
        }

        return $this->insertPendingJob('rebuild', $runAt, 'auto');
    }

    public function reserveDuePendingJob(): ?array
    {
        return $this->reserveDuePendingJobByAction('rebuild');
    }

    public function reserveDuePendingScanJob(): ?array
    {
        return $this->reserveDuePendingJobByAction('rebuild');
    }

    public function reserveDuePendingSendJob(): ?array
    {
        return $this->reserveDuePendingJobByAction('send_book');
    }

    public function reserveDuePendingCoverRebuildJob(): ?array
    {
        return $this->reserveDuePendingJobByAction('rebuild_cover');
    }

    public function markAllRunningScanDone(?string $finishedAt = null): int
    {
        return $this->markAllRunningDoneByActions(['rebuild', 'rebuild_cover'], $finishedAt);
    }

    public function markAllRunningActionDone(string $action, ?string $finishedAt = null): int
    {
        $normalizedAction = trim(strtolower($action));
        if ($normalizedAction === '') {
            return 0;
        }

        return $this->markAllRunningDoneByActions([$normalizedAction], $finishedAt);
    }

    public function hasRunningScanJob(): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM scan_jobs
             WHERE status = :status
               AND action = :action
             LIMIT 1'
        );
        $stmt->execute([
            ':status' => 'running',
            ':action' => 'rebuild',
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function hasRunningAction(string $action): bool
    {
        $normalizedAction = trim(strtolower($action));
        if ($normalizedAction === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM scan_jobs
             WHERE status = :status
               AND action = :action
             LIMIT 1'
        );
        $stmt->execute([
            ':status' => 'running',
            ':action' => $normalizedAction,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function hasPendingOrRunningAction(string $action): bool
    {
        $normalizedAction = trim(strtolower($action));
        if ($normalizedAction === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM scan_jobs
             WHERE action = :action
               AND status IN ("pending", "running")
             LIMIT 1'
        );
        $stmt->execute([
            ':action' => $normalizedAction,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function failExpiredPendingScanJobs(int $olderThanSeconds = self::PENDING_EXPIRE_SECONDS): array
    {
        return $this->failExpiredPendingJobs($olderThanSeconds, 'rebuild');
    }

    private function reserveDuePendingJobByAction(string $action): ?array
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $thresholdTs = time() - self::PENDING_EXPIRE_SECONDS;
            $nowTs = time();
            $finishedAt = date('c');

            $expireStmt = $this->pdo->prepare(
                'UPDATE scan_jobs
                 SET status = :failed_status, finished_at = :finished_at
                 WHERE status = :pending_status
                   AND action = :action
                   AND CAST(COALESCE(strftime(\'%s\', run_at), \'0\') AS INTEGER) < :threshold_ts'
            );
            $expireStmt->execute([
                ':failed_status' => 'failed',
                ':finished_at' => $finishedAt,
                ':pending_status' => 'pending',
                ':action' => $action,
                ':threshold_ts' => $thresholdTs,
            ]);

            $stmt = $this->pdo->prepare(
                'SELECT id, action, run_at, source, status, payload
                 FROM scan_jobs
                 WHERE status = :status
                   AND CAST(COALESCE(strftime(\'%s\', run_at), \'0\') AS INTEGER) <= :now_ts
                   AND CAST(COALESCE(strftime(\'%s\', run_at), \'0\') AS INTEGER) >= :threshold_ts
                   AND action = :action
                 ORDER BY CASE WHEN source = "manual" THEN 0 ELSE 1 END ASC, run_at ASC, id ASC
                 LIMIT 1'
            );
            $stmt->execute([
                ':status' => 'pending',
                ':now_ts' => $nowTs,
                ':threshold_ts' => $thresholdTs,
                ':action' => $action,
            ]);
            $job = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($job)) {
                $this->pdo->exec('COMMIT');
                return null;
            }

            $update = $this->pdo->prepare(
                'UPDATE scan_jobs
                 SET status = :status, started_at = :started_at
                 WHERE id = :id'
            );
            $update->execute([
                ':status' => 'running',
                ':started_at' => date('c'),
                ':id' => (int) $job['id'],
            ]);

            $this->pdo->exec('COMMIT');
            $job['status'] = 'running';
            $job['started_at'] = date('c');
            $job['payload'] = $this->decodePayload((string) ($job['payload'] ?? ''));
            return $job;
        } catch (\Throwable $e) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    public function markDone(int $jobId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE scan_jobs
             SET status = :status, finished_at = :finished_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => 'done',
            ':finished_at' => date('c'),
            ':id' => $jobId,
        ]);
    }

    public function markAllRunningDone(?string $finishedAt = null): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE scan_jobs
             SET status = :done_status, finished_at = :finished_at
             WHERE status = :running_status'
        );
        $stmt->execute([
            ':done_status' => 'done',
            ':finished_at' => $finishedAt ?? date('c'),
            ':running_status' => 'running',
        ]);

        return $stmt->rowCount();
    }

    public function hasRunningJob(): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM scan_jobs
             WHERE status = :status
             LIMIT 1'
        );
        $stmt->execute([
            ':status' => 'running',
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function markFailed(int $jobId, ?string $finishedAt = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE scan_jobs
             SET status = :status, finished_at = :finished_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => 'failed',
            ':finished_at' => $finishedAt ?? date('c'),
            ':id' => $jobId,
        ]);
    }

    public function failExpiredPendingJobs(int $olderThanSeconds = 60, ?string $action = null): array
    {
        $olderThanSeconds = max(1, $olderThanSeconds);
        $thresholdTs = time() - $olderThanSeconds;
        $threshold = date(DATE_ATOM, $thresholdTs);
        $finishedAt = date('c');

        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if ($action !== null && trim($action) !== '') {
                $selectStmt = $this->pdo->prepare(
                    'SELECT id, source
                     FROM scan_jobs
                     WHERE status = :status
                       AND CAST(COALESCE(strftime(\'%s\', run_at), \'0\') AS INTEGER) < :threshold_ts
                       AND action = :action'
                );
                $selectStmt->execute([
                    ':status' => 'pending',
                    ':threshold_ts' => $thresholdTs,
                    ':action' => trim($action),
                ]);
            } else {
                $selectStmt = $this->pdo->prepare(
                    'SELECT id, source
                     FROM scan_jobs
                     WHERE status = :status
                       AND CAST(COALESCE(strftime(\'%s\', run_at), \'0\') AS INTEGER) < :threshold_ts'
                );
                $selectStmt->execute([
                    ':status' => 'pending',
                    ':threshold_ts' => $thresholdTs,
                ]);
            }
            $rows = $selectStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            if ($rows === []) {
                $this->pdo->exec('COMMIT');
                return [
                    'failed_total' => 0,
                    'failed_manual' => 0,
                    'failed_auto' => 0,
                    'threshold' => $threshold,
                ];
            }

            $updateStmt = $this->pdo->prepare(
                'UPDATE scan_jobs
                 SET status = :failed_status, finished_at = :finished_at
                 WHERE id = :id'
            );

            $failedManual = 0;
            $failedAuto = 0;
            foreach ($rows as $row) {
                $jobId = (int) ($row['id'] ?? 0);
                if ($jobId <= 0) {
                    continue;
                }

                $updateStmt->execute([
                    ':failed_status' => 'failed',
                    ':finished_at' => $finishedAt,
                    ':id' => $jobId,
                ]);

                $source = strtolower(trim((string) ($row['source'] ?? '')));
                if ($source === 'auto') {
                    $failedAuto++;
                } else {
                    $failedManual++;
                }
            }

            $this->pdo->exec('COMMIT');

            return [
                'failed_total' => $failedManual + $failedAuto,
                'failed_manual' => $failedManual,
                'failed_auto' => $failedAuto,
                'threshold' => $threshold,
            ];
        } catch (\Throwable $e) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\Throwable) {
            }

            throw $e;
        }
    }

    public function deleteJob(int $jobId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM scan_jobs WHERE id = :id');
        $stmt->execute([':id' => $jobId]);
    }

    public function requeueSoon(int $jobId, int $afterSeconds = 60): void
    {
        $afterSeconds = max(10, $afterSeconds);
        $runAt = (new \DateTimeImmutable('now'))->modify('+' . $afterSeconds . ' seconds');
        $stmt = $this->pdo->prepare(
            'UPDATE scan_jobs
             SET status = :status, run_at = :run_at, started_at = NULL, finished_at = NULL
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => 'pending',
            ':run_at' => $runAt->format(DATE_ATOM),
            ':id' => $jobId,
        ]);
    }

    public function getNextPendingJob(): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, action, run_at, source, status, payload
             FROM scan_jobs
             WHERE status = :status
             ORDER BY run_at ASC, id ASC
             LIMIT 1'
        );
        $stmt->execute([
            ':status' => 'pending',
        ]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function hasBlockingPendingJob(): bool
    {
        $nowTs = time();
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM scan_jobs
             WHERE status = :status
               AND action IN ("rebuild", "rebuild_cover")
               AND (
                    source = :manual_source
                    OR CAST(strftime(\'%s\', run_at) AS INTEGER) <= :now_ts
               )
             LIMIT 1'
        );
        $stmt->execute([
            ':status' => 'pending',
            ':manual_source' => 'manual',
            ':now_ts' => $nowTs,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function listJobs(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, action, run_at, source, status, created_at, started_at, finished_at, payload
             FROM scan_jobs
             ORDER BY id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $row['payload'] = $this->decodePayload((string) ($row['payload'] ?? ''));
            $result[] = $row;
        }

        return $result;
    }

    public function countJobs(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM scan_jobs')->fetchColumn();
    }

    public function listJobsPage(int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);

        $stmt = $this->pdo->prepare(
            'SELECT id, action, run_at, source, status, created_at, started_at, finished_at, payload
             FROM scan_jobs
             ORDER BY id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $row['payload'] = $this->decodePayload((string) ($row['payload'] ?? ''));
            $result[] = $row;
        }

        return $result;
    }

    public function clearJobLogs(int $count = 0): int
    {
        // count <= 0 means clear all.
        if ($count <= 0) {
            return $this->pdo->exec('DELETE FROM scan_jobs');
        }

        $count = max(1, min(100000, $count));
        $stmt = $this->pdo->prepare(
            'DELETE FROM scan_jobs
             WHERE id IN (
               SELECT id FROM scan_jobs
               ORDER BY id ASC
               LIMIT :limit
             )'
        );
        $stmt->bindValue(':limit', $count, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    private function getNextPendingJobAfter(\DateTimeImmutable $after): ?array
    {
        $afterTs = $after->getTimestamp();
        $stmt = $this->pdo->prepare(
            'SELECT id, action, run_at, source, status, payload
             FROM scan_jobs
             WHERE status = :status
               AND CAST(strftime(\'%s\', run_at) AS INTEGER) > :after_ts
             ORDER BY run_at ASC, id ASC
             LIMIT 1'
        );
        $stmt->execute([
            ':status' => 'pending',
            ':after_ts' => $afterTs,
        ]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function findPendingAutoBeforeOrAt(\DateTimeImmutable $runAt): ?array
    {
        $runAtTs = $runAt->getTimestamp();
        $stmt = $this->pdo->prepare(
            'SELECT id, action, run_at, source, status, created_at, payload
             FROM scan_jobs
             WHERE status = :status
               AND source = :source
               AND CAST(strftime(\'%s\', run_at) AS INTEGER) <= :run_at_ts
             ORDER BY run_at ASC, id ASC
             LIMIT 1'
        );
        $stmt->execute([
            ':status' => 'pending',
            ':source' => 'auto',
            ':run_at_ts' => $runAtTs,
        ]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function getJobById(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, action, run_at, source, status, created_at, started_at, finished_at, payload
             FROM scan_jobs
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([
            ':id' => $jobId,
        ]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function insertPendingJob(string $action, \DateTimeImmutable $runAt, string $source, array $payload = []): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO scan_jobs(action, run_at, source, status, created_at, payload)
             VALUES(:action, :run_at, :source, :status, :created_at, :payload)'
        );

        $createdAt = date('c');
        $payloadJson = $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            $payloadJson = null;
        }
        $stmt->execute([
            ':action' => $action,
            ':run_at' => $runAt->format(DATE_ATOM),
            ':source' => $source,
            ':status' => 'pending',
            ':created_at' => $createdAt,
            ':payload' => $payloadJson,
        ]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'action' => $action,
            'run_at' => $runAt->format(DATE_ATOM),
            'source' => $source,
            'status' => 'pending',
            'created_at' => $createdAt,
            'payload' => $payload,
        ];
    }

    private function verifyScheduleSchema(): void
    {
        $stmt = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'scan_jobs' LIMIT 1"
        );
        if ($stmt->fetchColumn() === false) {
            throw new \RuntimeException('Required auth table missing after migration: scan_jobs');
        }
    }

    private function importLegacyScheduleDb(string $appRoot): void
    {
        $legacyPath = $appRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'job_schedule.sqlite';
        if (!is_file($legacyPath)) {
            return;
        }

        if (realpath($legacyPath) === realpath($this->sqlitePath)) {
            return;
        }

        $currentCount = (int) $this->pdo->query('SELECT COUNT(*) FROM scan_jobs')->fetchColumn();
        if ($currentCount > 0) {
            $this->cleanupLegacyScheduleArtifacts($legacyPath);
            return;
        }

        try {
            $legacy = new \PDO('sqlite:' . $legacyPath);
            $legacy->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $tableCheck = $legacy->query(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'scan_jobs' LIMIT 1"
            )->fetchColumn();
            if ($tableCheck === false) {
                $this->cleanupLegacyScheduleArtifacts($legacyPath);
                return;
            }

            $rows = $legacy->query(
                'SELECT action, run_at, source, status, created_at, started_at, finished_at, payload
                 FROM scan_jobs
                 ORDER BY id ASC'
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            if ($rows !== []) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO scan_jobs(action, run_at, source, status, created_at, started_at, finished_at, payload)
                     VALUES(:action, :run_at, :source, :status, :created_at, :started_at, :finished_at, :payload)'
                );

                foreach ($rows as $row) {
                    $insert->execute([
                        ':action' => (string) ($row['action'] ?? 'rebuild'),
                        ':run_at' => (string) ($row['run_at'] ?? date('c')),
                        ':source' => (string) ($row['source'] ?? 'auto'),
                        ':status' => (string) ($row['status'] ?? 'pending'),
                        ':created_at' => (string) ($row['created_at'] ?? date('c')),
                        ':started_at' => isset($row['started_at']) ? (string) $row['started_at'] : null,
                        ':finished_at' => isset($row['finished_at']) ? (string) $row['finished_at'] : null,
                        ':payload' => isset($row['payload']) && is_string($row['payload']) ? $row['payload'] : null,
                    ]);
                }
            }
        } catch (\Throwable) {
            // Ignore migration errors; keep service functional with current DB.
            return;
        }

        $this->cleanupLegacyScheduleArtifacts($legacyPath);
    }

    /**
     * @param array{target_backup:?string} $backups
     */
    private function logVersionMismatchRecovery(string $dbPath, array $backups, \Throwable $e): void
    {
        error_log(sprintf(
            '[bookslib][migration-recovery] checksum mismatch detected. target=auth source=%s backup=%s error=%s',
            $dbPath,
            (string) ($backups['target_backup'] ?? ''),
            $e->getMessage()
        ));
    }

    /**
     * @return resource
     */
    private function acquireRuntimeDbLock()
    {
        $lockPath = dirname($this->sqlitePath) . DIRECTORY_SEPARATOR . 'auth_settings.init.lock';
        $handle = fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open auth runtime lock file: ' . $lockPath);
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new \RuntimeException('Cannot lock auth runtime DB initialization: ' . $lockPath);
        }

        $this->runtimeDbLockHandle = $handle;

        return $handle;
    }

    /**
     * @param resource $handle
     */
    private function releaseRuntimeDbLock($handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
        $this->runtimeDbLockHandle = null;
    }

    private function cleanupLegacyScheduleArtifacts(string $legacyPath): void
    {
        foreach ([$legacyPath, $legacyPath . '-wal', $legacyPath . '-shm'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function decodePayload(string $payloadJson): array
    {
        $payloadJson = trim($payloadJson);
        if ($payloadJson === '') {
            return [];
        }

        $decoded = json_decode($payloadJson, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function markAllRunningDoneByActions(array $actions, ?string $finishedAt = null): int
    {
        $normalizedActions = [];
        foreach ($actions as $action) {
            $normalized = trim(strtolower((string) $action));
            if ($normalized === '') {
                continue;
            }
            $normalizedActions[$normalized] = $normalized;
        }

        if ($normalizedActions === []) {
            return 0;
        }

        $placeholders = [];
        $params = [
            ':done_status' => 'done',
            ':finished_at' => $finishedAt ?? date('c'),
            ':running_status' => 'running',
        ];

        $index = 0;
        foreach (array_values($normalizedActions) as $action) {
            $placeholder = ':action_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $action;
            $index++;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE scan_jobs
             SET status = :done_status, finished_at = :finished_at
             WHERE status = :running_status
               AND action IN (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    private function getLatestScheduledRunAt(): ?\DateTimeImmutable
    {
        $stmt = $this->pdo->query(
            'SELECT run_at
             FROM scan_jobs
             WHERE status IN ("pending", "running")
             ORDER BY CAST(COALESCE(strftime(\'%s\', run_at), \'0\') AS INTEGER) DESC, id DESC
             LIMIT 1'
        );
        $runAt = $stmt === false ? false : $stmt->fetchColumn();
        if (!is_string($runAt) || trim($runAt) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($runAt);
        } catch (\Throwable) {
            return null;
        }
    }
}
