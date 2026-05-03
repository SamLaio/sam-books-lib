<?php

namespace Calibre\Controllers;

use Calibre\Http\View;
use Calibre\Services\AuthService;
use Calibre\Services\LoginCaptchaService;
use Calibre\Support\Lang;

final class AuthLoginController
{
    private View $view;
    private AuthService $authService;
    private LoginCaptchaService $captchaService;

    public function __construct(
        string $appRoot,
        ?View $view = null,
        ?AuthService $authService = null,
        ?LoginCaptchaService $captchaService = null
    )
    {
        $root = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->view = $view ?? new View($root . DIRECTORY_SEPARATOR . 'views');
        $this->authService = $authService ?? new AuthService($root);
        $this->captchaService = $captchaService ?? new LoginCaptchaService();
    }

    public function handle(array $server, array $get, array $post): void
    {
        if (!$this->authService->isEnabled()) {
            header('Location: index.php', true, 302);
            exit;
        }

        $this->authService->ensureSettingsDatabaseReady();

        $requestMethod = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $next = $this->sanitizeNextUrl((string) ($get['next'] ?? $post['next'] ?? 'index.php'));

        if ($this->authService->isAuthenticated()) {
            header('Location: ' . $next, true, 302);
            exit;
        }

        $error = null;
        $this->captchaService->ensureChallenge();
        if ($requestMethod === 'POST') {
            $username = trim((string) ($post['username'] ?? ''));
            $password = (string) ($post['password'] ?? '');
            $captcha = (string) ($post['captcha'] ?? '');

            if (!$this->captchaService->validateAndRotate($captcha)) {
                $this->authService->recordFailedLoginAttemptForUsername($username);
                $error = Lang::t('error.captcha_invalid');
                if ($this->authService->isUsernameDisabled($username)) {
                    $error = Lang::t('error.login_account_locked');
                }
            } elseif ($this->authService->login($username, $password)) {
                if ($this->authService->isPasswordChangeRequired()) {
                    header('Location: settings.php?notice=' . rawurlencode(Lang::t('message.default_password_notice')), true, 302);
                    exit;
                }

                header('Location: ' . $next, true, 302);
                exit;
            } else {
                if ($this->authService->isUsernameDisabled($username)) {
                    $error = Lang::t('error.login_account_locked');
                } else {
                    $error = $this->authService->hasAnyUser()
                        ? Lang::t('error.login_invalid')
                        : Lang::t('error.login_missing_bootstrap');
                }
            }
        }

        $captchaVersion = (string) time();

        echo $this->view->renderPage('auth/login', [
            'pageTitle' => Lang::t('login.page_title'),
            'next' => $next,
            'error' => $error,
            'authEnabled' => true,
            'magicLoginEnabled' => $this->authService->isMagicLoginEnabled(),
            'captchaImageUrl' => 'captcha.php?v=' . rawurlencode($captchaVersion),
        ]);
    }

    public function requireAuthenticated(array $server, bool $json = false): void
    {
        if (!$this->authService->isEnabled()) {
            return;
        }

        $this->authService->ensureSettingsDatabaseReady();

        if ($this->authService->isAuthenticated()) {
            $user = $this->authService->getCurrentUser();
            if (is_array($user) && ((int) ($user['is_enabled'] ?? 1) === 1)) {
                return;
            }

            $this->authService->logout();
        }

        if ($json) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => Lang::t('error.unauthorized')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $requestUri = $this->sanitizeNextUrl((string) ($server['REQUEST_URI'] ?? 'index.php'));
        header('Location: login.php?next=' . rawurlencode($requestUri), true, 302);
        exit;
    }

    public static function requireLogin(string $appRoot, array $server, bool $json = false): void
    {
        (new self($appRoot))->requireAuthenticated($server, $json);
    }

    private function sanitizeNextUrl(string $next): string
    {
        $next = trim($next);
        if ($next === '' || preg_match('/[\x00-\x1F\x7F]/', $next) === 1) {
            return 'index.php';
        }

        $normalized = str_replace('\\', '/', $next);
        if (str_starts_with($normalized, '//')) {
            return 'index.php';
        }

        $parts = parse_url($normalized);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return 'index.php';
        }

        return $normalized;
    }
}
