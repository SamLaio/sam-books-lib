<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Calibre\Controllers\ReaderManifestController;
use Calibre\Controllers\AuthLoginController;

AuthLoginController::requireLogin(__DIR__, $_SERVER, true);

(new ReaderManifestController(__DIR__))->handle($_GET);
