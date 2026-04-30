<?php

namespace Calibre\Controllers;

use Calibre\Services\AuthService;
use Calibre\Support\Lang;

final class ThemePreferenceController
{
    private AuthService $authService;

    public function __construct(string $appRoot, ?AuthService $authService = null)
    {
        $this->authService = $authService ?? new AuthService($appRoot);
    }

    public function handle(array $server, array $post): void
    {
        $requestMethod = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if ($requestMethod !== 'POST') {
            $this->respondJson(['error' => Lang::t('error.method_not_allowed')], 405);
        }

        $theme = strtolower(trim((string) ($post['theme'] ?? '')));
        if (!in_array($theme, ['light', 'dark'], true)) {
            $this->respondJson(['error' => Lang::t('error.invalid_theme')], 400);
        }

        try {
            if ($this->authService->isEnabled()) {
                $appliedTheme = $this->authService->updateCurrentUserTheme($theme);
            } else {
                $appliedTheme = $theme;
            }

            $this->authService->persistThemeCookie($appliedTheme);
            $this->respondJson(['ok' => true, 'theme' => $appliedTheme]);
        } catch (\Throwable $e) {
            $this->respondJson(['error' => $e->getMessage()], 500);
        }
    }

    private function respondJson(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
