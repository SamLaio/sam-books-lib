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
        $next = trim((string) ($get['next'] ?? $post['next'] ?? 'index.php'));
        if ($next === '') {
            $next = 'index.php';
        }

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
                $error = Lang::t('error.captcha_invalid');
            } elseif ($this->authService->login($username, $password)) {
                if ($this->authService->isPasswordChangeRequired()) {
                    header('Location: settings.php?notice=' . rawurlencode(Lang::t('message.default_password_notice')), true, 302);
                    exit;
                }

                header('Location: ' . $next, true, 302);
                exit;
            } else {
                $error = $this->authService->hasAnyUser()
                    ? Lang::t('error.login_invalid')
                    : Lang::t('error.login_missing_bootstrap');
            }
        }

        $captchaVersion = (string) time();

        echo $this->view->renderPage('auth/login', [
            'pageTitle' => Lang::t('login.page_title'),
            'next' => $next,
            'error' => $error,
            'authEnabled' => true,
            'captchaImageUrl' => 'captcha.php?v=' . rawurlencode($captchaVersion),
        ]);
    }
}
