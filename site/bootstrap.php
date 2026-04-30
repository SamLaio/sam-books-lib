<?php

declare(strict_types=1);

set_exception_handler(static function (\Throwable $e): void {
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? 'cli');
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? PHP_SAPI);
    $message = sprintf(
        '[bookslib][uncaught-exception] method=%s uri=%s type=%s message=%s file=%s line=%d trace=%s',
        $method,
        $requestUri,
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        preg_replace('/\s+/', ' ', $e->getTraceAsString()) ?: ''
    );
    error_log($message);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }

    echo "Internal Server Error\n";
    exit(1);
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!is_array($error)) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) {
        return;
    }

    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? 'cli');
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? PHP_SAPI);
    $message = sprintf(
        '[bookslib][fatal-error] method=%s uri=%s type=%d message=%s file=%s line=%d',
        $method,
        $requestUri,
        (int) ($error['type'] ?? 0),
        (string) ($error['message'] ?? ''),
        (string) ($error['file'] ?? ''),
        (int) ($error['line'] ?? 0)
    );
    error_log($message);
});

spl_autoload_register(static function (string $class): void {
    $prefix = 'Calibre\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    if ($relativeClass === false || $relativeClass === '') {
        return;
    }

    $filePath = __DIR__
        . DIRECTORY_SEPARATOR
        . 'src'
        . DIRECTORY_SEPARATOR
        . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass)
        . '.php';

    if (is_file($filePath)) {
        require_once $filePath;
    }
});

$bootLocale = null;
try {
    $bootAuthService = new \Calibre\Services\AuthService(__DIR__);
    $bootLocale = $bootAuthService->resolvePreferredLocaleForBoot();
} catch (\Throwable) {
    $bootLocale = null;
}

\Calibre\Support\Lang::boot(__DIR__, $bootLocale);
