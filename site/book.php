<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Calibre\Controllers\BookDetailsController;
use Calibre\Controllers\AuthLoginController;

AuthLoginController::requireLogin(__DIR__, $_SERVER, true);

(new BookDetailsController(__DIR__))->handle($_SERVER, $_GET);
