<?php

namespace Calibre\Controllers;

use Calibre\Services\AuthService;
use Calibre\Services\ScanScheduleService;
use Calibre\ScanService;
use Calibre\Support\Lang;

final class SendBookController
{
    private string $appRoot;
    private AuthService $authService;
    private ScanService $scanService;
    private ScanScheduleService $scheduleService;

    public function __construct(
        string $appRoot,
        ?AuthService $authService = null,
        ?ScanService $scanService = null,
        ?ScanScheduleService $scheduleService = null
    ) {
        $this->appRoot = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->scanService = $scanService ?? new ScanService($this->appRoot);
        $this->authService = $authService ?? new AuthService($this->appRoot, $this->scanService);
        $this->scheduleService = $scheduleService ?? new ScanScheduleService($this->appRoot);
    }

    public function handle(array $server, array $query, array $post): void
    {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'POST'], true)) {
            $this->redirectBack('mail_error', Lang::t('error.method_not_allowed'));
        }

        $bookId = filter_var(
            $query['id'] ?? $post['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($bookId === false || $bookId === null) {
            $this->redirectBack('mail_error', Lang::t('error.invalid_book_id'));
        }

        try {
            $user = $this->authService->getCurrentUser();
            if (!is_array($user)) {
                throw new \RuntimeException(Lang::t('error.not_logged_in'));
            }

            $appSettings = $this->authService->getAppSettings();
            if (!$this->authService->isSmtpConfigured($appSettings)) {
                throw new \RuntimeException(Lang::t('error.smtp_not_configured_send_disabled'));
            }

            $recipientEmail = trim((string) ($user['email'] ?? ''));
            if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException(Lang::t('error.current_user_email_invalid'));
            }

            $scheduled = $this->scheduleService->enqueueManual(
                'send_book',
                60,
                [
                    'book_id' => (int) $bookId,
                    'user_id' => (int) ($user['id'] ?? 0),
                    'recipient_email' => $recipientEmail,
                ]
            );

            $this->redirectBack(
                'mail_notice',
                Lang::t('message.send_book_queued', [
                    'email' => $recipientEmail,
                    'time' => (string) ($scheduled['run_at'] ?? date('c')),
                ])
            );
        } catch (\Throwable $e) {
            $this->redirectBack('mail_error', $e->getMessage());
        }
    }

    private function redirectBack(string $key, string $message): void
    {
        $target = 'index.php';
        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));

        if ($referer !== '') {
            $parsed = parse_url($referer);
            $path = (string) ($parsed['path'] ?? '');
            if ($path !== '') {
                $baseName = basename($path);
                if ($baseName !== '') {
                    $target = $baseName;
                }
            }

            $params = [];
            parse_str((string) ($parsed['query'] ?? ''), $params);
            unset($params['mail_notice'], $params['mail_error']);
            $params[$key] = $message;
            $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            if ($queryString !== '') {
                $target .= '?' . $queryString;
            }
        } else {
            $target .= '?' . http_build_query([$key => $message], '', '&', PHP_QUERY_RFC3986);
        }

        header('Location: ' . $target, true, 303);
        exit;
    }
}
