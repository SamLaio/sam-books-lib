<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Calibre\Controllers\AuthSettingsController;

(new AuthSettingsController(__DIR__))->handle($_SERVER, $_POST);
