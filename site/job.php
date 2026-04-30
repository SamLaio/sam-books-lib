<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Calibre\ScanLauncher;
use Calibre\ScanService;
use Calibre\Services\AuthService;
use Calibre\Services\DownloadService;
use Calibre\Services\ScanScheduleService;
use Calibre\Services\SmtpMailer;
use Calibre\LibraryIndex;
use Calibre\Support\Lang;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "job.php is CLI-only.\n";
    exit(1);
}

try {
    $cronMode = false;
    $positionals = [];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--cron') {
            $cronMode = true;
            continue;
        }

        $positionals[] = $argument;
    }

    $libraryOverride = $positionals[0] ?? null;
    $sqliteOverride = $positionals[1] ?? null;

    $scanService = new ScanService(__DIR__);
    $launcher = new ScanLauncher(__DIR__, $scanService);
    $scheduleService = new ScanScheduleService(__DIR__);
    $watchdog = $launcher->terminateTimedOutScanProcesses($sqliteOverride);
    if (!empty($watchdog['candidates'])) {
        fwrite(
            STDOUT,
            sprintf(
                "Watchdog checked timed-out scan(s). timeout=%ss, candidates=%d, terminated=%d, still_running=%d\n",
                (int) ($watchdog['timeout_seconds'] ?? 0),
                count((array) ($watchdog['candidates'] ?? [])),
                count((array) ($watchdog['terminated_pids'] ?? [])),
                count((array) ($watchdog['still_running_pids'] ?? []))
            )
        );
    }

    if ($cronMode) {
        $intervalMinutes = $scanService->getScanIntervalMinutes();
        $dueJob = null;

        // Every cron tick: fail all overdue pending jobs first (both scan/send_book),
        // so overdue tasks are never picked up in later reserve steps.
        $expiredPending = $scheduleService->failExpiredPendingJobs(
            \Calibre\Services\ScanScheduleService::PENDING_EXPIRE_SECONDS,
            null
        );
        if (($expiredPending['failed_total'] ?? 0) > 0) {
            fwrite(
                STDOUT,
                sprintf(
                    "Marked overdue pending schedules as failed. total=%d, manual=%d, auto=%d, threshold=%s\n",
                    (int) ($expiredPending['failed_total'] ?? 0),
                    (int) ($expiredPending['failed_manual'] ?? 0),
                    (int) ($expiredPending['failed_auto'] ?? 0),
                    (string) ($expiredPending['threshold'] ?? 'n/a')
                )
            );
        }

        $scanBusy = $launcher->isRunning();
        $coverBusy = $launcher->isCoverRebuildRunning();
        if (!$scanBusy) {
            $doneCount = $scheduleService->markAllRunningActionDone('rebuild');
            if ($doneCount > 0) {
                fwrite(STDOUT, "Marked finished running rebuild schedules as done. count={$doneCount}\n");
            }
        }
        if (!$coverBusy) {
            $doneCount = $scheduleService->markAllRunningActionDone('rebuild_cover');
            if ($doneCount > 0) {
                fwrite(STDOUT, "Marked finished running rebuild_cover schedules as done. count={$doneCount}\n");
            }
        }

        $scheduleService->ensureNextAutoSchedule($intervalMinutes);

        // 1) Send jobs.
        $sendJob = $scheduleService->reserveDuePendingSendJob();
        if (is_array($sendJob) && isset($sendJob['id'])) {
            try {
                $payload = is_array($sendJob['payload'] ?? null) ? $sendJob['payload'] : [];
                $bookId = isset($payload['book_id']) ? (int) $payload['book_id'] : 0;
                $recipientEmail = trim((string) ($payload['recipient_email'] ?? ''));
                if ($bookId < 1) {
                    throw new RuntimeException(Lang::t('error.invalid_send_book_payload_book_id'));
                }
                if ($recipientEmail === '' || filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
                    throw new RuntimeException(Lang::t('error.invalid_send_book_payload_recipient_email'));
                }

                $downloadService = new DownloadService(__DIR__, $scanService);
                $download = $downloadService->resolveByBookId($bookId);
                $book = (new LibraryIndex($scanService->getSqlitePath()))->getBookById($bookId);
                if (!is_array($book)) {
                    throw new RuntimeException(Lang::t('error.book_not_found_for_send_book'));
                }

                $authService = new AuthService(__DIR__, $scanService);
                $smtp = $authService->getAppSettings();
                if (!$authService->isSmtpConfigured($smtp)) {
                    throw new RuntimeException(Lang::t('error.smtp_not_configured'));
                }
                $smtpHost = trim((string) ($smtp['smtp_host'] ?? ''));
                if ($smtpHost === '') {
                    throw new RuntimeException(Lang::t('error.smtp_host_empty'));
                }
                $smtpEncryption = strtolower(trim((string) ($smtp['smtp_encryption'] ?? 'none')));
                $smtpPortRaw = trim((string) ($smtp['smtp_port'] ?? ''));
                $smtpPort = $smtpPortRaw !== '' ? (int) $smtpPortRaw : ($smtpEncryption === 'ssl' ? 465 : ($smtpEncryption === 'tls' ? 587 : 25));
                $smtpUsername = trim((string) ($smtp['smtp_username'] ?? ''));
                $fromEmail = filter_var($smtpUsername, FILTER_VALIDATE_EMAIL) ? $smtpUsername : 'noreply@localhost';

                $siteTitle = $scanService->getSiteTitle();
                $title = trim((string) ($book['title'] ?? Lang::t('job.book_untitled')));
                $author = trim((string) ($book['author'] ?? Lang::t('job.book_unknown_author')));
                $extension = strtolower((string) pathinfo((string) ($download['path'] ?? ''), PATHINFO_EXTENSION));
                $bodyLine = $title . ' - ' . $author . ($extension !== '' ? '.' . $extension : '');

                $mailer = new SmtpMailer(
                    $smtpHost,
                    $smtpPort,
                    $smtpEncryption,
                    $smtpUsername,
                    (string) ($smtp['smtp_password'] ?? '')
                );
                $mailer->sendWithAttachments(
                    $fromEmail,
                    $siteTitle,
                    $recipientEmail,
                    Lang::t('job.send_subject', [
                        'siteTitle' => $siteTitle,
                        'title' => $title,
                        'author' => $author,
                    ]),
                    $bodyLine,
                    [[
                        'name' => (string) ($download['name'] ?? basename((string) ($download['path'] ?? 'book.bin'))),
                        'path' => (string) ($download['path'] ?? ''),
                        'mime_type' => (string) ($download['mime_type'] ?? 'application/octet-stream'),
                    ]]
                );
                $scheduleService->markDone((int) $sendJob['id']);
                fwrite(STDOUT, "Send job done. id=" . (int) $sendJob['id'] . ", book_id={$bookId}\n");
            } catch (Throwable $sendError) {
                $scheduleService->markFailed((int) $sendJob['id']);
                fwrite(STDERR, "Send job failed. id=" . (int) ($sendJob['id'] ?? 0) . ', error=' . $sendError->getMessage() . PHP_EOL);
            }
        }

        // 2) Cover rebuild jobs: same background/cron pattern as send, but staggered from other schedules.
        if ($scanBusy || $coverBusy) {
            fwrite(
                STDOUT,
                sprintf(
                    "Skipping cover rebuild dispatch because worker is busy. scan_busy=%s, cover_busy=%s\n",
                    $scanBusy ? 'yes' : 'no',
                    $coverBusy ? 'yes' : 'no'
                )
            );
        } else {
            $dueJob = $scheduleService->reserveDuePendingCoverRebuildJob();
            if (is_array($dueJob) && isset($dueJob['id'])) {
                $result = $launcher->startCoverRebuild($libraryOverride, $sqliteOverride);
                if ($result['already_running']) {
                    $scheduleService->requeueSoon((int) $dueJob['id'], 60);
                    fwrite(STDOUT, "Cover rebuild job is already running.\n");
                    fwrite(STDOUT, "Log: {$result['log_file']}\n");
                    exit(0);
                }

                fwrite(STDOUT, "Cover rebuild job started.\n");
                fwrite(STDOUT, "Triggered by cron.\n");
                fwrite(
                    STDOUT,
                    sprintf(
                        "Schedule: id=%d, source=%s, run_at=%s.\n",
                        (int) ($dueJob['id'] ?? 0),
                        (string) ($dueJob['source'] ?? 'unknown'),
                        (string) ($dueJob['run_at'] ?? 'unknown')
                    )
                );
                if ($result['pid'] !== null) {
                    fwrite(STDOUT, "PID: {$result['pid']}\n");
                }
                fwrite(STDOUT, "Log: {$result['log_file']}\n");
                exit(0);
            }
        }

        // 3) Scan index jobs.
        if ($scanBusy || $coverBusy) {
            fwrite(
                STDOUT,
                sprintf(
                    "Skipping scan dispatch because worker is busy. scan_busy=%s, cover_busy=%s\n",
                    $scanBusy ? 'yes' : 'no',
                    $coverBusy ? 'yes' : 'no'
                )
            );
            exit(0);
        }

        $dueJob = $scheduleService->reserveDuePendingScanJob();
        if (!is_array($dueJob)) {
            fwrite(STDOUT, "Skipping scan job. No due scan schedule.\n");
            exit(0);
        }

        if (strtolower(trim((string) ($dueJob['source'] ?? ''))) === 'auto') {
            $scheduleService->ensureNextAutoScheduleFromRunAt((string) ($dueJob['run_at'] ?? date('c')), $intervalMinutes);
        }
    }

    $result = $launcher->start($libraryOverride, $sqliteOverride);

    if ($result['already_running']) {
        if ($cronMode && isset($dueJob['id'])) {
            $scheduleService->requeueSoon((int) $dueJob['id'], 60);
        }

        fwrite(STDOUT, "Scan job is already running.\n");
        fwrite(STDOUT, "Log: {$result['log_file']}\n");
        exit(0);
    }

    fwrite(STDOUT, "Scan job started.\n");
    if ($cronMode && isset($dueJob['id'])) {
        fwrite(STDOUT, "Triggered by cron.\n");
        fwrite(
            STDOUT,
            sprintf(
                "Schedule: id=%d, source=%s, run_at=%s.\n",
                (int) ($dueJob['id'] ?? 0),
                (string) ($dueJob['source'] ?? 'unknown'),
                (string) ($dueJob['run_at'] ?? 'unknown')
            )
        );
    }
    if ($result['pid'] !== null) {
        fwrite(STDOUT, "PID: {$result['pid']}\n");
    }
    fwrite(STDOUT, "Log: {$result['log_file']}\n");
    exit(0);
} catch (Throwable $e) {
    if (
        isset($cronMode, $dueJob, $scheduleService)
        && $cronMode
        && is_array($dueJob)
        && isset($dueJob['id'])
    ) {
        $scheduleService->requeueSoon((int) $dueJob['id'], 60);
    }

    fwrite(STDERR, "Job failed: " . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    exit(1);
}
