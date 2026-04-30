<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Calibre\Controllers\ThemePreferenceController;
use Calibre\Services\AuthService;

$authService = new AuthService(__DIR__);
$authService->requireLogin($_SERVER, true);

(new ThemePreferenceController(__DIR__, $authService))->handle($_SERVER, $_POST);

