<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Calibre\Controllers\ReaderController;
use Calibre\Controllers\AuthLoginController;

AuthLoginController::requireLogin(__DIR__, $_SERVER, false);

(new ReaderController(__DIR__))->handle($_SERVER, $_GET);
