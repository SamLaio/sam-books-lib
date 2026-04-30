<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Calibre\Database\MigrationRunner;
use Calibre\ScanService;
use Calibre\Services\AuthService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "migrate.php is CLI-only.\n";
    exit(1);
}

$usage = <<<TXT
Usage:
  php site/migrate.php status [auth|library]
  php site/migrate.php migrate [auth|library|all]
  php site/migrate.php failures [auth|library] [limit]

Examples:
  php site/migrate.php status
  php site/migrate.php status auth
  php site/migrate.php migrate all
  php site/migrate.php failures library 20
TXT;

try {
    $command = strtolower(trim((string) ($argv[1] ?? 'status')));
    $target = strtolower(trim((string) ($argv[2] ?? 'all')));
    $thirdArg = (string) ($argv[3] ?? '');

    $appRoot = __DIR__;
    $scanService = new ScanService($appRoot);
    $authService = new AuthService($appRoot, $scanService);
    $runner = new MigrationRunner($appRoot);

    $targetPdos = static function () use ($authService, $scanService): array {
        $authPdo = new PDO('sqlite:' . $authService->getSettingsDbPath());
        $authPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $libraryPdo = new PDO('sqlite:' . $scanService->getSqlitePath());
        $libraryPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return [
            'auth' => $authPdo,
            'library' => $libraryPdo,
        ];
    };

    if (!in_array($command, ['status', 'migrate', 'failures'], true)) {
        fwrite(STDERR, $usage . PHP_EOL);
        exit(1);
    }

    if ($command === 'migrate') {
        $pdos = $targetPdos();
        $targets = $target === 'all' ? ['auth', 'library'] : [$target];
        foreach ($targets as $migrationTarget) {
            if (!isset($pdos[$migrationTarget])) {
                throw new RuntimeException('Unsupported migration target: ' . $migrationTarget);
            }

            $before = $runner->getStatusRows($migrationTarget);
            $beforeApplied = count(array_filter($before, static fn(array $row): bool => $row['status'] === 'applied'));

            try {
                $runner->runTarget($migrationTarget, $pdos[$migrationTarget]);
            } catch (Throwable $e) {
                if (!MigrationRunner::isChecksumMismatchException($e)) {
                    throw $e;
                }

                $targetDbPath = $migrationTarget === 'auth'
                    ? $authService->getSettingsDbPath()
                    : $scanService->getSqlitePath();

                unset($pdos[$migrationTarget]);
                $backups = $runner->recoverVersionMismatch($targetDbPath);
                fwrite(
                    STDOUT,
                    sprintf(
                        "[%s] checksum mismatch recovered. backup=%s migration_backup=%s\n",
                        $migrationTarget,
                        (string) ($backups['target_backup'] ?? ''),
                        (string) ($backups['migration_backup'] ?? '')
                    )
                );

                $pdos = $targetPdos();
                $runner = new MigrationRunner($appRoot);
                $runner->runTarget($migrationTarget, $pdos[$migrationTarget]);
            }

            $after = $runner->getStatusRows($migrationTarget);
            $afterApplied = count(array_filter($after, static fn(array $row): bool => $row['status'] === 'applied'));
            $delta = $afterApplied - $beforeApplied;

            fwrite(
                STDOUT,
                sprintf(
                    "[%s] migrated. applied_now=%d total_applied=%d migration_db=%s\n",
                    $migrationTarget,
                    max(0, $delta),
                    $afterApplied,
                    $runner->getMigrationDbPath()
                )
            );
        }

        exit(0);
    }

    if ($command === 'status') {
        $rows = $runner->getStatusRows($target === 'all' ? null : $target);
        fwrite(STDOUT, "migration_db=" . $runner->getMigrationDbPath() . PHP_EOL);
        foreach ($rows as $row) {
            fwrite(
                STDOUT,
                implode("\t", [
                    $row['target_db'],
                    $row['migration'],
                    $row['status'],
                    (string) $row['batch'],
                    $row['applied_at'],
                ]) . PHP_EOL
            );
        }
        exit(0);
    }

    $limit = ctype_digit($thirdArg) ? (int) $thirdArg : 100;
    $rows = $runner->getFailureRows($target === 'all' ? null : $target, $limit);
    fwrite(STDOUT, "migration_db=" . $runner->getMigrationDbPath() . PHP_EOL);
    if ($rows === []) {
        fwrite(STDOUT, "no_failures\n");
        exit(0);
    }

    foreach ($rows as $row) {
        fwrite(
            STDOUT,
            implode("\t", [
                (string) ($row['target_db'] ?? ''),
                (string) ($row['migration'] ?? ''),
                (string) ($row['failed_at'] ?? ''),
                preg_replace('/\s+/', ' ', (string) ($row['error_message'] ?? '')) ?: '',
            ]) . PHP_EOL
        );
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration command failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
