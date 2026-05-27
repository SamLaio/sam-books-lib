<?php

namespace Calibre\Http;

final class AccelRedirect
{
    /**
     * @param array<string,string> $internalRoots
     */
    public static function internalUriFor(string $filePath, array $internalRoots): ?string
    {
        if (!self::isEnabled()) {
            return null;
        }

        $fileRealPath = realpath($filePath);
        if ($fileRealPath === false || !is_file($fileRealPath)) {
            return null;
        }

        foreach ($internalRoots as $uriPrefix => $rootPath) {
            $rootRealPath = realpath($rootPath);
            if ($rootRealPath === false || !is_dir($rootRealPath)) {
                continue;
            }

            if ($fileRealPath !== $rootRealPath
                && strpos($fileRealPath, $rootRealPath . DIRECTORY_SEPARATOR) !== 0) {
                continue;
            }

            $relativePath = ltrim(substr($fileRealPath, strlen($rootRealPath)), DIRECTORY_SEPARATOR);
            if ($relativePath === '') {
                return null;
            }

            return rtrim($uriPrefix, '/') . '/' . self::encodePath($relativePath);
        }

        return null;
    }

    private static function isEnabled(): bool
    {
        $value = strtolower(trim((string) getenv('BOOKSLIB_X_ACCEL_REDIRECT')));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public static function send(string $internalUri): void
    {
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        header('X-Accel-Redirect: ' . $internalUri);
        exit;
    }

    private static function encodePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $segments = explode('/', $normalized);

        return implode('/', array_map('rawurlencode', $segments));
    }
}
