<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Calibre\Controllers\SendBookController;
use Calibre\Controllers\AuthLoginController;

AuthLoginController::requireLogin(__DIR__, $_SERVER);

(new SendBookController(__DIR__))->handle($_SERVER, $_GET, $_POST);
