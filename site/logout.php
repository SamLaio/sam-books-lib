<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Calibre\Controllers\AuthLogoutController;

(new AuthLogoutController(__DIR__))->handle();
