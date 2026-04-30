<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Calibre\Controllers\AuthLoginController;

(new AuthLoginController(__DIR__))->handle($_SERVER, $_GET, $_POST);
