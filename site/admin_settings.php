<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Calibre\Controllers\AdminSettingsController;

(new AdminSettingsController(__DIR__))->handle($_SERVER, $_GET, $_POST);
