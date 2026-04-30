<?php

namespace Calibre;

final class ScanLauncher
{
    private string $appRoot;
    private ScanService $scanService;

    public function __construct(string $appRoot, ?ScanService $scanService = null)
    {
        $this->appRoot = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->scanService = $scanService ?? new ScanService($this->appRoot);
    }

    public function isRunning(): bool
    {
        return $this->isJobRunning(ScanService::JOB_TYPE_SCAN, true);
    }

    public function isCoverRebuildRunning(): bool
    {
        return $this->isJobRunning(ScanService::JOB_TYPE_COVER_REBUILD, false);
    }

    private function isJobRunning(string $jobType, bool $reconcileResumeState): bool
    {
        if ($jobType === ScanService::JOB_TYPE_SCAN) {
            $this->terminateTimedOutScanProcesses();
        }

        if ($reconcileResumeState) {
            $this->scanService->reconcileResumeState();
        }

        $lockFile = $this->scanService->getLockFile($jobType);
        $lockDir = dirname($lockFile);

        if (!is_dir($lockDir) && !mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
            throw new \RuntimeException("Cannot create lock directory: {$lockDir}");
        }

        $lockHandle = fopen($lockFile, 'c+');
        if ($lockHandle === false) {
            throw new \RuntimeException("Cannot open lock file: {$lockFile}");
        }

        $locked = flock($lockHandle, LOCK_EX | LOCK_NB);
        if ($locked) {
            flock($lockHandle, LOCK_UN);
        }

        fclose($lockHandle);

        return !$locked;
    }

    public function start(?string $libraryOverride = null, ?string $sqliteOverride = null): array
    {
        $this->scanService->getLibraryPath($libraryOverride);
        $this->scanService->getSqlitePath($sqliteOverride);
        $watchdog = $this->terminateTimedOutScanProcesses($sqliteOverride);

        if ($this->isRunning()) {
            return [
                'started' => false,
                'already_running' => true,
                'pid' => null,
                'log_file' => $this->scanService->getLogFile(ScanService::JOB_TYPE_SCAN),
                'watchdog' => $watchdog,
            ];
        }

        if (!function_exists('exec')) {
            throw new \RuntimeException('exec() is not available, cannot start background scan.');
        }

        $logFile = $this->scanService->getLogFile(ScanService::JOB_TYPE_SCAN);
        $logDir = dirname($logFile);
        if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
            throw new \RuntimeException("Cannot create log directory: {$logDir}");
        }

        $command = $this->buildCommand('scan.php', $logFile, $libraryOverride, $sqliteOverride);

        if (DIRECTORY_SEPARATOR === '\\') {
            pclose(popen($command, 'r'));

            return [
                'started' => true,
                'already_running' => false,
                'pid' => null,
                'log_file' => $logFile,
            ];
        }

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException('Failed to start background scan job.');
        }

        $pid = null;
        if (isset($output[0])) {
            $rawPid = trim((string) $output[0]);
            if ($rawPid !== '' && ctype_digit($rawPid)) {
                $pid = (int) $rawPid;
            }
        }

        return [
            'started' => true,
            'already_running' => false,
            'pid' => $pid,
            'log_file' => $logFile,
            'watchdog' => $watchdog,
        ];
    }

    public function startCoverRebuild(?string $libraryOverride = null, ?string $sqliteOverride = null): array
    {
        $this->scanService->getLibraryPath($libraryOverride);
        $this->scanService->getSqlitePath($sqliteOverride);
        if ($this->isCoverRebuildRunning()) {
            return [
                'started' => false,
                'already_running' => true,
                'pid' => null,
                'log_file' => $this->scanService->getLogFile(ScanService::JOB_TYPE_COVER_REBUILD),
            ];
        }

        if (!function_exists('exec')) {
            throw new \RuntimeException('exec() is not available, cannot start background cover rebuild job.');
        }

        $logFile = $this->scanService->getLogFile(ScanService::JOB_TYPE_COVER_REBUILD);
        $logDir = dirname($logFile);
        if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
            throw new \RuntimeException("Cannot create log directory: {$logDir}");
        }

        $command = $this->buildCommand('cover_rebuild.php', $logFile, $libraryOverride, $sqliteOverride);

        if (DIRECTORY_SEPARATOR === '\\') {
            pclose(popen($command, 'r'));

            return [
                'started' => true,
                'already_running' => false,
                'pid' => null,
                'log_file' => $logFile,
            ];
        }

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException('Failed to start background cover rebuild job.');
        }

        $pid = null;
        if (isset($output[0])) {
            $rawPid = trim((string) $output[0]);
            if ($rawPid !== '' && ctype_digit($rawPid)) {
                $pid = (int) $rawPid;
            }
        }

        return [
            'started' => true,
            'already_running' => false,
            'pid' => $pid,
            'log_file' => $logFile,
        ];
    }

    public function terminateTimedOutScanProcesses(?string $sqliteOverride = null): array
    {
        $timeoutSeconds = $this->scanService->getScanWatchdogTimeoutSeconds();
        if ($timeoutSeconds <= 0) {
            return [
                'enabled' => false,
                'timeout_seconds' => $timeoutSeconds,
                'candidates' => [],
                'terminated_pids' => [],
                'still_running_pids' => [],
            ];
        }

        $processes = $this->listRunningScanProcesses();
        $candidates = [];
        foreach ($processes as $process) {
            if (($process['elapsed_seconds'] ?? 0) < $timeoutSeconds) {
                continue;
            }

            if ($this->isScanProgressStale($timeoutSeconds)) {
                $candidates[] = $process;
            }
        }

        if ($candidates === []) {
            return [
                'enabled' => true,
                'timeout_seconds' => $timeoutSeconds,
                'candidates' => [],
                'terminated_pids' => [],
                'still_running_pids' => [],
            ];
        }

        $terminatedPids = [];
        foreach ($candidates as $candidate) {
            $pid = (int) ($candidate['pid'] ?? 0);
            if ($pid <= 0) {
                continue;
            }

            if ($this->terminateProcess($pid)) {
                $terminatedPids[] = $pid;
            }
        }

        if ($terminatedPids !== []) {
            usleep(500000);
        }

        $remainingProcesses = $this->listRunningScanProcesses();
        $remainingByPid = [];
        foreach ($remainingProcesses as $process) {
            $remainingByPid[(int) $process['pid']] = true;
        }

        $stillRunningPids = [];
        foreach ($candidates as $candidate) {
            $pid = (int) ($candidate['pid'] ?? 0);
            if ($pid > 0 && isset($remainingByPid[$pid])) {
                $stillRunningPids[] = $pid;
            }
        }

        if ($terminatedPids !== []) {
            $this->appendScanLog(
                sprintf(
                    '[%s] Watchdog terminated timed-out scan process(es). timeout=%ss, pid=%s',
                    date('c'),
                    $timeoutSeconds,
                    implode(',', $terminatedPids)
                )
            );
        }

        if ($stillRunningPids !== []) {
            $this->appendScanLog(
                sprintf(
                    '[%s] Watchdog detected scan process still running after terminate attempt. pid=%s',
                    date('c'),
                    implode(',', $stillRunningPids)
                )
            );
        }

        return [
            'enabled' => true,
            'timeout_seconds' => $timeoutSeconds,
            'candidates' => $candidates,
            'terminated_pids' => $terminatedPids,
            'still_running_pids' => $stillRunningPids,
        ];
    }

    private function buildCommand(string $scriptName, string $logFile, ?string $libraryOverride, ?string $sqliteOverride): string
    {
        $phpBinary = $this->detectPhpCliBinary();
        $scanScript = $this->appRoot . DIRECTORY_SEPARATOR . $scriptName;

        $arguments = [
            escapeshellarg($phpBinary),
            escapeshellarg($scanScript),
        ];

        if ($libraryOverride !== null && trim($libraryOverride) !== '') {
            $arguments[] = escapeshellarg(trim($libraryOverride));
        }

        if ($sqliteOverride !== null && trim($sqliteOverride) !== '') {
            if ($libraryOverride === null || trim($libraryOverride) === '') {
                $arguments[] = escapeshellarg('');
            }
            $arguments[] = escapeshellarg(trim($sqliteOverride));
        }

        $command = implode(' ', $arguments);

        if (DIRECTORY_SEPARATOR === '\\') {
            return 'start /B "" ' . $command . ' >> ' . escapeshellarg($logFile) . ' 2>&1';
        }

        // Use nohup + background to guarantee HTTP request won't block waiting for scan lifecycle.
        $detached = 'nohup ' . $command . ' >> ' . escapeshellarg($logFile) . ' 2>&1 < /dev/null & echo $!';

        return 'cd '
            . escapeshellarg($this->appRoot)
            . ' && sh -c '
            . escapeshellarg($detached);
    }

    private function detectPhpCliBinary(): string
    {
        $candidates = [
            PHP_BINDIR . DIRECTORY_SEPARATOR . 'php',
            dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php',
            PHP_BINARY,
            'php',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $basename = strtolower(basename($candidate));
            if ($basename === 'php-fpm' || $basename === 'php-fpm.exe') {
                continue;
            }

            if ($candidate === 'php' || is_file($candidate) || is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }

    private function listRunningScanProcesses(): array
    {
        if (!function_exists('exec')) {
            return [];
        }

        $output = [];
        $exitCode = 0;
        exec('ps -eo pid=,etimes=,args=', $output, $exitCode);
        if ($exitCode !== 0 || $output === []) {
            return [];
        }

        $scanScriptPath = $this->appRoot . DIRECTORY_SEPARATOR . 'scan.php';
        $normalizedScanScriptPath = str_replace('\\', '/', $scanScriptPath);
        $processes = [];

        foreach ($output as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if (!preg_match('/^(\d+)\s+(\d+)\s+(.+)$/', $line, $matches)) {
                continue;
            }

            $pid = (int) $matches[1];
            $elapsed = (int) $matches[2];
            $command = (string) $matches[3];
            $normalizedCommand = str_replace('\\', '/', $command);
            if (
                strpos($normalizedCommand, '/scan.php') === false
                && strpos($normalizedCommand, $normalizedScanScriptPath) === false
            ) {
                continue;
            }

            $processes[] = [
                'pid' => $pid,
                'elapsed_seconds' => $elapsed,
                'command' => $command,
            ];
        }

        return $processes;
    }

    private function terminateProcess(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            @posix_kill($pid, 9);
        } else {
            $output = [];
            $exitCode = 0;
            exec('kill -9 ' . $pid, $output, $exitCode);
            if ($exitCode !== 0) {
                return false;
            }
        }

        usleep(200000);

        return !$this->isProcessAlive($pid);
    }

    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        $output = [];
        $exitCode = 0;
        exec('ps -p ' . $pid . ' -o pid=', $output, $exitCode);

        return $exitCode === 0 && $output !== [];
    }

    private function appendScanLog(string $line): void
    {
        $this->scanService->appendScanLogLine($line);
    }

    private function isScanProgressStale(int $timeoutSeconds): bool
    {
        $timeoutSeconds = max(1, $timeoutSeconds);
        $timestamps = [];

        $resumeFlagPath = $this->scanService->getScanResumeFlagFile();
        if (is_file($resumeFlagPath)) {
            $flagMtime = @filemtime($resumeFlagPath);
            if (is_int($flagMtime) && $flagMtime > 0) {
                $timestamps[] = $flagMtime;
            }

            $raw = @file_get_contents($resumeFlagPath);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                foreach (['last_progress_at', 'updated_at'] as $key) {
                    $value = trim((string) ($decoded[$key] ?? ''));
                    if ($value === '') {
                        continue;
                    }

                    $ts = strtotime($value);
                    if ($ts !== false && $ts > 0) {
                        $timestamps[] = $ts;
                    }
                }
            }
        }

        $scanLogPath = $this->scanService->getLogFile();
        if (is_file($scanLogPath)) {
            $logMtime = @filemtime($scanLogPath);
            if (is_int($logMtime) && $logMtime > 0) {
                $timestamps[] = $logMtime;
            }
        }

        if ($timestamps === []) {
            return true;
        }

        $latestActivityAt = max($timestamps);
        return (time() - $latestActivityAt) >= $timeoutSeconds;
    }
}
