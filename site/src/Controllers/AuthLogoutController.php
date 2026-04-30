<?php

namespace Calibre\Controllers;

use Calibre\Services\AuthService;

final class AuthLogoutController
{
    private AuthService $authService;

    public function __construct(string $appRoot, ?AuthService $authService = null)
    {
        $root = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->authService = $authService ?? new AuthService($root);
    }

    public function handle(): void
    {
        if ($this->authService->isEnabled()) {
            $this->authService->logout();
        }

        header('Location: login.php', true, 302);
        exit;
    }
}
