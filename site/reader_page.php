<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Calibre\Controllers\ReaderPageController;
use Calibre\Controllers\AuthLoginController;

AuthLoginController::requireLogin(__DIR__, $_SERVER, false);

(new ReaderPageController(__DIR__))->handle($_GET);
