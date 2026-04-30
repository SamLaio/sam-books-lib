<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Calibre\Controllers\SendBookController;
use Calibre\Services\AuthService;

$authService = new AuthService(__DIR__);
$authService->requireLogin($_SERVER);

(new SendBookController(__DIR__))->handle($_SERVER, $_GET, $_POST);
