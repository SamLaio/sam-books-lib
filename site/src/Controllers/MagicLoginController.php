<?php

namespace Calibre\Controllers;

use Calibre\Http\View;
use Calibre\Services\AuthService;
use Calibre\Services\LoginCaptchaService;
use Calibre\Services\MagicLoginService;
use Calibre\Support\Lang;

final class MagicLoginController
{
    private View $view;
    private AuthService $authService;
    private LoginCaptchaService $captchaService;
    private MagicLoginService $magicLoginService;

    public function __construct(
        private string $appRoot,
        ?View $view = null,
        ?AuthService $authService = null,
        ?LoginCaptchaService $captchaService = null,
        ?MagicLoginService $magicLoginService = null
    )
    {
        $this->appRoot = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->view = $view ?? new View($this->appRoot . DIRECTORY_SEPARATOR . 'views');
        $this->authService = $authService ?? new AuthService($this->appRoot);
        $this->captchaService = $captchaService ?? new LoginCaptchaService();
        $this->magicLoginService = $magicLoginService ?? new MagicLoginService($this->appRoot, $this->authService);
    }

    public function handle(array $server, array $get, array $post): void
    {
        if (!$this->authService->isEnabled()) {
            $this->respondJson(['error' => Lang::t('error.auth_disabled')], 403);
            return;
        }
        if (!$this->authService->isMagicLoginEnabled()) {
            http_response_code(404);
            return;
        }

        $action = strtolower(trim((string) ($get['action'] ?? $post['action'] ?? '')));
        if ($action === 'create') {
            $this->handleCreate($server);
            return;
        }
        if ($action === 'status') {
            $this->handleStatus((string) ($get['token'] ?? ''));
            return;
        }
        if ($action === 'qr') {
            $this->handleQr((string) ($get['token'] ?? ''), $server);
            return;
        }
        if ($action === 'authorize') {
            $this->handleAuthorize($server, $post);
            return;
        }

        $this->renderAuthorizePage($server, (string) ($get['token'] ?? ''), null);
    }

    private function handleCreate(array $server): void
    {
        if (strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->respondJson(['error' => Lang::t('error.method_not_allowed')], 405);
            return;
        }
        if (!$this->isSameOriginPost($server)) {
            $this->respondJson(['error' => Lang::t('error.csrf_invalid')], 403);
            return;
        }

        try {
            $this->respondJson($this->magicLoginService->createOrReuse($server));
        } catch (\RuntimeException $e) {
            $this->respondJson(['error' => $e->getMessage()], 429);
        }
    }

    private function handleStatus(string $token): void
    {
        $this->respondJson($this->magicLoginService->pollStatus($token));
    }

    private function handleQr(string $token, array $server): void
    {
        try {
            $png = $this->magicLoginService->renderQrPng($token, $server);
        } catch (\Throwable $e) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo $e->getMessage();
            return;
        }

        header('Content-Type: image/png');
        header('Cache-Control: no-store');
        echo $png;
    }

    private function handleAuthorize(array $server, array $post): void
    {
        if (strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->renderAuthorizePage($server, (string) ($post['token'] ?? ''), Lang::t('error.method_not_allowed'));
            return;
        }

        $token = (string) ($post['token'] ?? '');
        if (!$this->isSameOriginPost($server) || !$this->validateCsrfToken((string) ($post['csrf_token'] ?? ''))) {
            $this->renderAuthorizePage($server, $token, Lang::t('error.csrf_invalid'));
            return;
        }

        $state = $this->magicLoginService->getAuthorizationState($token);
        if (($state['status'] ?? '') !== 'pending') {
            $this->renderAuthorizePage($server, $token, $this->messageForStatus((string) ($state['status'] ?? 'invalid')));
            return;
        }

        if (!$this->authService->isAuthenticated()) {
            $username = trim((string) ($post['username'] ?? ''));
            $password = (string) ($post['password'] ?? '');
            $captcha = (string) ($post['captcha'] ?? '');
            if (!$this->captchaService->validateAndRotate($captcha)) {
                $this->authService->recordFailedLoginAttemptForUsername($username);
                $error = $this->authService->isUsernameDisabled($username)
                    ? Lang::t('error.login_account_locked')
                    : Lang::t('error.captcha_invalid');
                $this->renderAuthorizePage($server, $token, $error);
                return;
            }
            if (!$this->authService->login($username, $password)) {
                $error = $this->authService->isUsernameDisabled($username)
                    ? Lang::t('error.login_account_locked')
                    : Lang::t('error.login_invalid');
                $this->renderAuthorizePage($server, $token, $error);
                return;
            }
            if ($this->authService->isPasswordChangeRequired()) {
                $this->renderAuthorizePage($server, $token, Lang::t('message.default_password_notice'));
                return;
            }
        }

        if ($this->authService->isPasswordChangeRequired()) {
            $this->renderAuthorizePage($server, $token, Lang::t('message.default_password_notice'));
            return;
        }

        $user = $this->authService->getCurrentUser();
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        $status = $this->magicLoginService->authorize($token, $userId, $server);
        $this->renderAuthorizePage($server, $token, null, $status);
    }

    private function renderAuthorizePage(array $server, string $token, ?string $error, ?string $authorizationStatus = null): void
    {
        $this->captchaService->ensureChallenge();
        $state = $this->magicLoginService->getAuthorizationState($token);
        $status = $authorizationStatus ?? (string) ($state['status'] ?? 'invalid');
        $user = $this->authService->getCurrentUser();

        echo $this->view->renderPage('auth/magic_login', [
            'pageTitle' => Lang::t('magic_login.page_title'),
            'token' => $token,
            'status' => $status,
            'statusMessage' => $this->messageForStatus($status),
            'error' => $error,
            'currentUser' => $user,
            'csrfToken' => $this->csrfToken(),
            'captchaImageUrl' => 'captcha.php?v=' . rawurlencode((string) time()),
            'authEnabled' => true,
        ]);
    }

    private function messageForStatus(string $status): string
    {
        return match ($status) {
            'pending' => Lang::t('magic_login.authorize_pending'),
            'authenticated' => Lang::t('magic_login.authorize_done'),
            'expired' => Lang::t('magic_login.expired'),
            'consumed' => Lang::t('magic_login.consumed'),
            default => Lang::t('magic_login.invalid'),
        };
    }

    private function respondJson(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function csrfToken(): string
    {
        $this->ensureSessionStarted();
        $token = (string) ($_SESSION['magic_login_csrf_token'] ?? '');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION['magic_login_csrf_token'] = $token;
        }

        return $token;
    }

    private function validateCsrfToken(string $token): bool
    {
        $this->ensureSessionStarted();
        $expected = (string) ($_SESSION['magic_login_csrf_token'] ?? '');

        return $expected !== '' && hash_equals($expected, $token);
    }

    private function isSameOriginPost(array $server): bool
    {
        $origin = trim((string) ($server['HTTP_ORIGIN'] ?? ''));
        if ($origin === '') {
            $origin = trim((string) ($server['HTTP_REFERER'] ?? ''));
        }
        if ($origin === '') {
            return true;
        }

        $originHost = strtolower((string) (parse_url($origin, PHP_URL_HOST) ?? ''));
        $currentHost = strtolower((string) ($server['HTTP_HOST'] ?? ''));
        $currentHost = preg_replace('/:\d+$/', '', $currentHost) ?? $currentHost;

        return $originHost !== '' && $currentHost !== '' && hash_equals($currentHost, $originHost);
    }

    private function ensureSessionStarted(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_start();
    }
}
