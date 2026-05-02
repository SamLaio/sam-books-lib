<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Calibre\Controllers\ReaderManifestController;
use Calibre\Services\AuthService;

$authService = new AuthService(__DIR__);
$authService->requireLogin($_SERVER, true);

(new ReaderManifestController(__DIR__))->handle($_GET);
