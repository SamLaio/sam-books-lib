<?php

namespace Calibre\Controllers;

use Calibre\Http\View;
use Calibre\ScanLauncher;
use Calibre\Services\AuthService;
use Calibre\ScanService;
use Calibre\Support\Lang;

final class AuthSettingsController
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

    public function handle(array $server, array $post): void
    {
        if (!$this->authService->isEnabled()) {
            header('Location: index.php', true, 302);
            exit;
        }

        $this->authService->ensureSettingsDatabaseReady();

        $this->authService->requireLogin($server);
        $user = $this->authService->getCurrentUser();
        if (!is_array($user)) {
            header('Location: login.php', true, 302);
            exit;
        }

        $requestMethod = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $notice = isset($_GET['notice']) && is_string($_GET['notice']) ? trim((string) $_GET['notice']) : null;
        $error = null;
        $scanLauncher = new ScanLauncher($this->appRoot, $this->scanService);
        $scanRunning = false;

        if ($requestMethod === 'POST') {
            $action = trim((string) ($post['action'] ?? ''));

            try {
                if ($action === 'change_password') {
                    $currentPassword = (string) ($post['current_password'] ?? '');
                    $password = (string) ($post['password'] ?? '');
                    $confirmPassword = (string) ($post['confirm_password'] ?? '');

                    $this->authService->changeCurrentUserPassword(
                        $currentPassword,
                        $password,
                        $confirmPassword
                    );
                    $notice = Lang::t('message.password_updated');
                } elseif ($action === 'update_profile') {
                    $email = (string) ($post['email'] ?? '');
                    $this->authService->updateCurrentUserEmail($email);
                    $notice = Lang::t('message.profile_updated');
                } elseif ($action === 'update_locale') {
                    $locale = (string) ($post['locale'] ?? '');
                    $this->authService->updateCurrentUserLocale($locale);
                    $notice = Lang::t('message.locale_updated');
                } elseif ($action === 'rotate_token') {
                    $this->authService->rotateApiToken((int) $user['id']);
                    $notice = Lang::t('message.api_token_rotated');
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            $user = $this->authService->getCurrentUser() ?? $user;
        }

        if ($notice === null && $this->authService->isPasswordChangeRequired()) {
            $notice = Lang::t('message.default_password_notice');
        }

        try {
            $scanRunning = $scanLauncher->isRunning();
        } catch (\Throwable) {
            $scanRunning = false;
        }

        $siteBaseUrl = $this->scanService->getSiteBaseUrl() ?? '';
        $token = trim((string) ($user['api_token'] ?? ''));
        $opdsTokenUrl = '';
        if ($token !== '' && $siteBaseUrl !== '') {
            $opdsTokenUrl = rtrim($siteBaseUrl, '/') . '/opds/' . rawurlencode($token);
        }

        echo $this->view->renderPage('auth/settings', [
            'pageTitle' => Lang::t('layout.account_settings'),
            'siteTitle' => $this->scanService->getSiteTitle(),
            'notice' => $notice,
            'error' => $error,
            'user' => $user,
            'isAdmin' => $this->authService->isCurrentUserAdmin(),
            'availableLocales' => $this->authService->getAvailableLocales(),
            'currentLocale' => $this->authService->getPreferredLocale(),
            'opdsTokenUrl' => $opdsTokenUrl,
            'scanRunning' => $scanRunning,
        ]);
    }
}
