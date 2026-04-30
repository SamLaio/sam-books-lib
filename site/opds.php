<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Calibre\Controllers\OpdsController;
use Calibre\Services\AuthService;

$authService = new AuthService(__DIR__);

$query = $_GET;
$opdsPath = trim((string) ($query['opds_path'] ?? ''), '/');
unset($query['opds_path']);

$knownFeeds = [
    'index',
    'books',
    'new',
    'authors',
    'author',
    'tags',
    'tag',
    'series',
    'series_books',
    'read',
    'unread',
    'search',
    'osd',
    'cover',
    'download',
];

$segments = $opdsPath === '' ? [] : array_values(array_filter(explode('/', $opdsPath), static function (string $segment): bool {
    return $segment !== '';
}));

$token = null;
$feedSegments = $segments;
if ($authService->isEnabled() && $segments !== [] && !in_array(strtolower($segments[0]), $knownFeeds, true)) {
    $token = array_shift($feedSegments);
}

$isTokenAuthenticated = false;
if ($authService->isEnabled() && $token !== null) {
    $tokenUser = $authService->findUserByToken($token);
    if (is_array($tokenUser)) {
        $isTokenAuthenticated = true;
        $_SERVER['OPDS_BASE_PATH'] = '/opds/' . rawurlencode($token);
    }
}

if ($authService->isEnabled() && !$isTokenAuthenticated) {
    if ($token !== null) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Unauthorized OPDS token.';
        exit;
    }

    $isAuthenticated = $authService->isAuthenticated();

    if (!$isAuthenticated) {
        $basicUser = null;
        $basicPassword = null;

        if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
            $basicUser = (string) $_SERVER['PHP_AUTH_USER'];
            $basicPassword = (string) $_SERVER['PHP_AUTH_PW'];
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])
            && preg_match('/^\s*Basic\s+(.+)\s*$/i', (string) $_SERVER['HTTP_AUTHORIZATION'], $matches) === 1) {
            $decoded = base64_decode($matches[1], true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$basicUser, $basicPassword] = explode(':', $decoded, 2);
            }
        }

        if (is_string($basicUser) && is_string($basicPassword) && $authService->login($basicUser, $basicPassword)) {
            $isAuthenticated = true;
        }
    }

    if (!$isAuthenticated) {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="myBoooksLib OPDS", charset="UTF-8"');
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Authentication required.';
        exit;
    }

    $_SERVER['OPDS_BASE_PATH'] = '/opds';
} elseif ($token === null) {
    $_SERVER['OPDS_BASE_PATH'] = '/opds';
}

if ($feedSegments !== []) {
    $pathFeed = strtolower((string) array_shift($feedSegments));
    if (!isset($query['feed']) && $pathFeed !== '') {
        $query['feed'] = $pathFeed;
    }

    $resolvedFeed = strtolower((string) ($query['feed'] ?? 'index'));

    if (in_array($resolvedFeed, ['author', 'tag', 'series_books', 'search'], true)
        && !isset($query['value'])
        && !isset($query['query'])
        && $feedSegments !== []) {
        $rawValue = urldecode(implode('/', $feedSegments));
        if ($resolvedFeed === 'search') {
            $query['query'] = $rawValue;
        } else {
            $query['value'] = $rawValue;
        }
    }

    if (in_array($resolvedFeed, ['cover', 'download'], true) && !isset($query['id']) && isset($feedSegments[0])) {
        $query['id'] = (string) $feedSegments[0];
    }

    if ($resolvedFeed === 'download' && !isset($query['format']) && isset($feedSegments[1])) {
        $query['format'] = urldecode((string) $feedSegments[1]);
    }
}

(new OpdsController(__DIR__))->handle($_SERVER, $query);
