<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Calibre\Services\LoginCaptchaService;

$service = new LoginCaptchaService();
$refresh = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1';
$service->outputImage($refresh);
