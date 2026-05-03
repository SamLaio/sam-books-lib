<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Calibre\Controllers\ReadStatusController;
use Calibre\Controllers\AuthLoginController;

AuthLoginController::requireLogin(__DIR__, $_SERVER, true);

(new ReadStatusController(__DIR__))->handle($_SERVER, $_POST);
