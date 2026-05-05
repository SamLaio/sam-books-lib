<?php

namespace Calibre\Controllers;

use Calibre\Http\View;
use Calibre\ScanLauncher;
use Calibre\Services\AuthService;
use Calibre\Services\OpdsCacheService;
use Calibre\Services\SmtpMailer;
use Calibre\Services\ScanScheduleService;
use Calibre\ScanService;
use Calibre\Support\Lang;
use Calibre\Support\Pagination;

final class AdminSettingsController
{
    private string $appRoot;
    private View $view;
    private AuthService $authService;
    private ScanService $scanService;

    public function __construct(
        string $appRoot,
        ?View $view = null,
        ?AuthService $authService = null,
        ?ScanService $scanService = null
    ) {
        $this->appRoot = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->view = $view ?? new View($this->appRoot . DIRECTORY_SEPARATOR . 'views');
        $this->scanService = $scanService ?? new ScanService($this->appRoot);
        $this->authService = $authService ?? new AuthService($this->appRoot, $this->scanService);
    }

    public function handle(array $server, array $get, array $post): void
    {
        $authEnabled = $this->authService->isEnabled();
        $user = null;
        if ($authEnabled) {
            $this->authService->ensureSettingsDatabaseReady();
            $this->requireAuthenticated($server);
            $user = $this->authService->getCurrentUser();
            if (!is_array($user)) {
                header('Location: index.php', true, 302);
                exit;
            }

            if (!$this->authService->isCurrentUserAdmin()) {
                header('Location: index.php', true, 302);
                exit;
            }
            $this->authService->clearRecoveredPasswordFileForCurrentAdmin();
        } else {
            $user = [
                'id' => 0,
                'username' => 'system',
            ];
        }

        $requestMethod = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $notice = null;
        $error = null;
        $scanLauncher = new ScanLauncher($this->appRoot, $this->scanService);
        $scheduleService = new ScanScheduleService($this->appRoot);
        $scanRunning = false;
        $coverRebuildBusy = false;
        $showUserManagement = $authEnabled;
        $allowedTabs = $showUserManagement
            ? ['users', 'smtp', 'maintenance', 'jobs']
            : ['smtp', 'maintenance', 'jobs'];
        $defaultTab = $showUserManagement ? 'users' : 'smtp';
        $activeTab = trim((string) ($get['tab'] ?? $defaultTab));
        if ($activeTab === '' && isset($post['active_tab'])) {
            $activeTab = trim((string) $post['active_tab']);
        }
        if (!in_array($activeTab, $allowedTabs, true)) {
            $activeTab = $defaultTab;
        }

        if ($requestMethod === 'POST') {
            $action = trim((string) ($post['action'] ?? ''));

            try {
                if ($action === 'rebuild_cover') {
                    $scheduled = $scheduleService->enqueueManualAfterAllJobs('rebuild_cover', 60);
                    $notice = Lang::t('message.cover_rebuild_queued', [
                        'time' => (string) ($scheduled['run_at'] ?? date('c')),
                    ]);
                    $activeTab = 'maintenance';
                } elseif ($action === 'admin_rebuild_index') {
                    $scheduled = $scheduleService->enqueueManual('rebuild');
                    $notice = Lang::t('message.rebuild_queued', [
                        'time' => (string) ($scheduled['run_at'] ?? date('c')),
                    ]);
                    $activeTab = 'maintenance';
                } elseif ($action === 'admin_clear_opds_cache') {
                    $deleted = (new OpdsCacheService($this->appRoot))->clearAll();
                    $notice = Lang::t('message.opds_cache_cleared', [
                        'count' => (string) $deleted,
                    ]);
                    $activeTab = 'maintenance';
                } elseif ($action === 'admin_create_user') {
                    if (!$showUserManagement) {
                        throw new \RuntimeException(Lang::t('error.user_management_disabled'));
                    }
                    $this->authService->createUser(
                        (string) ($post['username'] ?? ''),
                        (string) ($post['password'] ?? ''),
                        (string) ($post['email'] ?? ''),
                        AuthService::ROLE_USER,
                        ((string) ($post['enabled'] ?? '1')) === '1'
                    );
                    $notice = Lang::t('message.user_created');
                } elseif ($action === 'admin_update_login_attempts') {
                    if (!$showUserManagement) {
                        throw new \RuntimeException(Lang::t('error.user_management_disabled'));
                    }
                    $this->authService->updateLoginMaxAttempts((int) ($post['login_max_attempts'] ?? 5));
                    $notice = Lang::t('message.login_attempts_updated');
                } elseif ($action === 'admin_update_user') {
                    if (!$showUserManagement) {
                        throw new \RuntimeException(Lang::t('error.user_management_disabled'));
                    }
                    $this->authService->adminUpdateUser(
                        (int) ($user['id'] ?? 0),
                        (int) ($post['target_user_id'] ?? 0),
                        (string) ($post['target_username'] ?? ''),
                        (string) ($post['target_email'] ?? ''),
                        (string) ($post['target_password'] ?? ''),
                        ((string) ($post['target_enabled'] ?? '0')) === '1'
                    );
                    $notice = Lang::t('message.user_updated');
                } elseif ($action === 'admin_update_user_library') {
                    if (!$showUserManagement) {
                        throw new \RuntimeException(Lang::t('error.user_management_disabled'));
                    }
                    $this->authService->adminUpdateUserLibrarySettings(
                        (int) ($user['id'] ?? 0),
                        (int) ($post['target_user_id'] ?? 0),
                        $this->parseDelimitedValues((string) ($post['hidden_authors'] ?? '')),
                        $this->parseDelimitedValues((string) ($post['hidden_tags'] ?? ''))
                    );
                    $notice = Lang::t('message.user_library_updated');
                } elseif ($action === 'admin_delete_user') {
                    if (!$showUserManagement) {
                        throw new \RuntimeException(Lang::t('error.user_management_disabled'));
                    }
                    $this->authService->adminDeleteUser(
                        (int) ($user['id'] ?? 0),
                        (int) ($post['target_user_id'] ?? 0)
                    );
                    $notice = Lang::t('message.user_deleted');
                } elseif ($action === 'admin_update_smtp') {
                    $this->authService->updateAppSettings([
                        'smtp_host' => (string) ($post['smtp_host'] ?? ''),
                        'smtp_port' => (string) ($post['smtp_port'] ?? ''),
                        'smtp_encryption' => (string) ($post['smtp_encryption'] ?? 'none'),
                        'smtp_username' => (string) ($post['smtp_username'] ?? ''),
                        'smtp_password' => (string) ($post['smtp_password'] ?? ''),
                    ]);
                    $notice = Lang::t('message.smtp_updated');
                } elseif ($action === 'admin_update_default_locale') {
                    $this->authService->updateDefaultLocale((string) ($post['default_locale'] ?? ''));
                    $notice = Lang::t('message.default_locale_updated');
                } elseif ($action === 'admin_update_magic_login') {
                    if (!$showUserManagement) {
                        throw new \RuntimeException(Lang::t('error.user_management_disabled'));
                    }
                    $this->authService->updateMagicLoginEnabled(((string) ($post['magic_login_enabled'] ?? '0')) === '1');
                    $notice = Lang::t('message.magic_login_settings_updated');
                    $activeTab = 'maintenance';
                } elseif ($action === 'admin_send_test_email') {
                    $appSettings = $this->authService->getAppSettings();
                    if (!$this->authService->isSmtpConfigured($appSettings)) {
                        throw new \RuntimeException(Lang::t('error.smtp_not_configured_for_test'));
                    }
                    $siteTitle = $this->scanService->getSiteTitle();
                    $recipientEmail = trim((string) ($post['test_email'] ?? ''));
                    if ($recipientEmail === '') {
                        throw new \RuntimeException(Lang::t('error.test_email_required'));
                    }
                    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                        throw new \RuntimeException(Lang::t('error.test_email_invalid'));
                    }

                    $smtpPortRaw = trim((string) ($appSettings['smtp_port'] ?? ''));
                    $smtpEncryption = strtolower(trim((string) ($appSettings['smtp_encryption'] ?? 'none')));
                    $smtpPort = $smtpPortRaw !== '' ? (int) $smtpPortRaw : ($smtpEncryption === 'ssl' ? 465 : ($smtpEncryption === 'tls' ? 587 : 25));

                    $smtpUsername = trim((string) ($appSettings['smtp_username'] ?? ''));
                    $fromEmail = filter_var($smtpUsername, FILTER_VALIDATE_EMAIL) ? $smtpUsername : 'noreply@localhost';

                    $mailer = new SmtpMailer(
                        (string) ($appSettings['smtp_host'] ?? ''),
                        $smtpPort,
                        $smtpEncryption,
                        $smtpUsername,
                        (string) ($appSettings['smtp_password'] ?? '')
                    );

                    $subject = Lang::t('admin.test_subject', ['siteTitle' => $siteTitle]);
                    $body = Lang::t('admin.test_body', ['siteTitle' => $siteTitle]);

                    $mailer->send(
                        $fromEmail,
                        $siteTitle,
                        $recipientEmail,
                        $subject,
                        $body
                    );

                    $notice = Lang::t('message.test_email_sent', ['email' => $recipientEmail]);
                } elseif ($action === 'admin_clear_jobs') {
                    $clearCountRaw = strtolower(trim((string) ($post['clear_count'] ?? '')));
                    $clearCount = 0;
                    if ($clearCountRaw !== 'all') {
                        if (!ctype_digit($clearCountRaw)) {
                            throw new \RuntimeException(Lang::t('error.clear_count_invalid'));
                        }
                        $clearCount = (int) $clearCountRaw;
                        if (!in_array($clearCount, [50, 100, 200, 500], true)) {
                            throw new \RuntimeException(Lang::t('error.clear_count_unsupported'));
                        }
                    }
                    $deleted = $scheduleService->clearJobLogs($clearCount);
                    $notice = $clearCountRaw === 'all'
                        ? Lang::t('message.clear_jobs_all', ['count' => (string) $deleted])
                        : Lang::t('message.clear_jobs_partial', ['count' => (string) $deleted]);
                    $activeTab = 'jobs';
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            $user = $this->authService->getCurrentUser() ?? $user;
        }

        try {
            $scanRunning = $scanLauncher->isRunning();
            if (!$scanRunning) {
                $scheduleService->markAllRunningScanDone();
            }
        } catch (\Throwable) {
            $scanRunning = false;
        }
        $coverRebuildBusy = $scheduleService->hasPendingOrRunningAction('rebuild_cover');

        $jobPerPage = (int) ($get['jobs_per_page'] ?? $post['jobs_per_page'] ?? 20);
        if (!in_array($jobPerPage, [20, 50, 100, 500], true)) {
            $jobPerPage = 20;
        }
        $requestedJobPage = (int) ($get['jobs_page'] ?? $post['jobs_page'] ?? 1);
        $requestedJobPage = max(1, $requestedJobPage);
        $jobTotalRows = $scheduleService->countJobs();
        $jobTotalPages = max(1, (int) ceil($jobTotalRows / $jobPerPage));
        $jobCurrentPage = min($requestedJobPage, $jobTotalPages);
        $jobOffset = ($jobCurrentPage - 1) * $jobPerPage;
        $jobs = $scheduleService->listJobsPage($jobPerPage, $jobOffset);
        foreach ($jobs as &$job) {
            if (!is_array($job)) {
                continue;
            }

            foreach (['run_at', 'started_at', 'finished_at', 'created_at'] as $dateField) {
                if (!array_key_exists($dateField, $job)) {
                    continue;
                }
                $job[$dateField] = $this->formatDateTimeDisplay($job[$dateField]);
            }
        }
        unset($job);

        $jobPagination = $this->buildJobPagination($jobCurrentPage, $jobTotalPages, $jobPerPage);
        $jobPerPageLinks = $this->buildJobPerPageLinks($jobPerPage, $jobCurrentPage);
        $managedUsers = $showUserManagement ? $this->authService->listUsers() : [];
        foreach ($managedUsers as &$managedUser) {
            if (!is_array($managedUser)) {
                continue;
            }

            $managedUser['hidden_authors_list'] = $this->authService->getUserHiddenAuthors($managedUser);
            $managedUser['hidden_tags_list'] = $this->authService->getUserHiddenTags($managedUser);
        }
        unset($managedUser);

        echo $this->view->renderPage('auth/admin_settings', [
            'pageTitle' => Lang::t('admin.heading'),
            'siteTitle' => $this->scanService->getSiteTitle(),
            'notice' => $notice,
            'error' => $error,
            'user' => $user,
            'managedUsers' => $managedUsers,
            'appSettings' => $this->authService->getAppSettings(),
            'availableLocales' => $this->authService->getAvailableLocales(),
            'jobs' => $jobs,
            'jobsTotalRows' => $jobTotalRows,
            'jobsTotalPages' => $jobTotalPages,
            'jobsCurrentPage' => $jobCurrentPage,
            'jobsPerPage' => $jobPerPage,
            'jobsPagination' => $jobPagination,
            'jobsPerPageLinks' => $jobPerPageLinks,
            'activeTab' => $activeTab,
            'showUserManagement' => $showUserManagement,
            'scanRunning' => $scanRunning,
            'coverRebuildBusy' => $coverRebuildBusy,
            'versionSignature' => $this->buildVersionSignature(),
            'smtpConfigured' => $this->authService->isSmtpConfigured(),
        ]);
    }

    private function requireAuthenticated(array $server): void
    {
        (new AuthLoginController($this->appRoot, $this->view, $this->authService))->requireAuthenticated($server);
    }

    private function parseDelimitedValues(string $raw): array
    {
        $parts = preg_split('/\s*;\s*/u', trim($raw)) ?: [];
        $values = [];
        foreach ($parts as $part) {
            $candidate = trim((string) $part);
            if ($candidate === '' || in_array($candidate, $values, true)) {
                continue;
            }

            $values[] = $candidate;
        }

        return $values;
    }

    private function buildJobUrl(int $page, int $perPage): string
    {
        $params = [
            'tab' => 'jobs',
            'jobs_page' => max(1, $page),
            'jobs_per_page' => $perPage,
        ];

        return 'admin_settings.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function buildJobPagination(int $currentPage, int $totalPages, int $perPage): array
    {
        $items = [];
        $previousPage = null;
        foreach (Pagination::pages($currentPage, $totalPages) as $pageNumber) {
            if ($previousPage !== null && $pageNumber - $previousPage > 1) {
                $items[] = ['type' => 'ellipsis'];
            }

            $items[] = [
                'type' => 'page',
                'number' => $pageNumber,
                'current' => $pageNumber === $currentPage,
                'url' => $pageNumber === $currentPage ? null : $this->buildJobUrl($pageNumber, $perPage),
            ];
            $previousPage = $pageNumber;
        }

        return [
            'previousUrl' => $currentPage > 1 ? $this->buildJobUrl($currentPage - 1, $perPage) : null,
            'nextUrl' => $currentPage < $totalPages ? $this->buildJobUrl($currentPage + 1, $perPage) : null,
            'items' => $items,
        ];
    }

    private function buildJobPerPageLinks(int $currentPerPage, int $currentPage): array
    {
        $links = [];
        foreach ([20, 50, 100, 500] as $option) {
            $links[] = [
                'label' => (string) $option,
                'value' => $option,
                'url' => $this->buildJobUrl($currentPage, $option),
                'current' => $option === $currentPerPage,
            ];
        }

        return $links;
    }

    private function formatDateTimeDisplay(mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        $ts = strtotime($raw);
        if ($ts === false) {
            return $raw;
        }

        return date('Y-m-d H:i:s', $ts);
    }

    private function buildVersionSignature(): string
    {
        $files = [
            $this->appRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'AdminSettingsController.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'admin_settings.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'login.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'layout.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'index.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'app.css',
            $this->appRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'catalog.js',
            $this->appRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'reader.css',
            $this->appRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'reader.js',
            $this->appRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'View.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'LibraryIndex.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'MigrationRunner.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'bootstrap.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'Lang.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'Lang' . DIRECTORY_SEPARATOR . 'zhTW.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'Lang' . DIRECTORY_SEPARATOR . 'en.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'AuthService.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'CatalogRequest.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'ScanService.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'ScanLauncher.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'ScanScheduleService.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'ReaderAccessService.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'ReaderEpubService.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'ReaderController.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'ReaderManifestController.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'ReaderPageController.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'ReaderAssetController.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'reader' . DIRECTORY_SEPARATOR . 'index.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'reader.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'reader_manifest.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'reader_page.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'reader_asset.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'migrate.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'job.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'scan.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'cover_rebuild.php',
        ];

        foreach ([
            $this->appRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . '*.php',
            $this->appRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . '*.php',
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $migrationFile) {
                $files[] = $migrationFile;
            }
        }

        $latestMtime = 0;
        $fingerprint = '';
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $mtime = (int) @filemtime($file);
            if ($mtime > $latestMtime) {
                $latestMtime = $mtime;
            }

            $fingerprint .= $file . '|' . $mtime . '|';
        }

        if ($fingerprint === '') {
            return Lang::t('admin.version_unknown');
        }

        return Lang::t('admin.version_signature', [
            'time' => date('Y-m-d H:i:s', $latestMtime > 0 ? $latestMtime : time()),
            'hash' => substr(sha1($fingerprint), 0, 12),
        ]);
    }
}
