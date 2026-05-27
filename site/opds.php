<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Calibre\Controllers\OpdsController;
use Calibre\Http\AccelRedirect;
use Calibre\Http\HttpException;
use Calibre\LibraryIndex;
use Calibre\ScanService;
use Calibre\Services\OpdsAssetService;
use Calibre\Services\AuthService;

/**
 * Emit OPDS-compatible XML error to avoid client-side "failed to parse feed"
 * when authentication fails.
 */
function emitOpdsAuthError(int $status, string $title, string $detail): void
{
    http_response_code($status);
    header('Content-Type: application/atom+xml;profile=opds-catalog;kind=navigation; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

    $feed = $dom->createElementNS('http://www.w3.org/2005/Atom', 'feed');
    $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:opds', 'http://opds-spec.org/2010/catalog');
    $dom->appendChild($feed);

    $id = $dom->createElementNS('http://www.w3.org/2005/Atom', 'id', 'urn:bookslib:opds:error');
    $feed->appendChild($id);
    $feed->appendChild($dom->createElementNS('http://www.w3.org/2005/Atom', 'title', $title));
    $feed->appendChild($dom->createElementNS('http://www.w3.org/2005/Atom', 'updated', gmdate(DATE_ATOM)));

    $entry = $dom->createElementNS('http://www.w3.org/2005/Atom', 'entry');
    $feed->appendChild($entry);
    $entry->appendChild($dom->createElementNS('http://www.w3.org/2005/Atom', 'id', 'urn:bookslib:opds:error:auth'));
    $entry->appendChild($dom->createElementNS('http://www.w3.org/2005/Atom', 'title', $title));
    $entry->appendChild($dom->createElementNS('http://www.w3.org/2005/Atom', 'updated', gmdate(DATE_ATOM)));
    $summary = $dom->createElementNS('http://www.w3.org/2005/Atom', 'summary', $detail);
    $summary->setAttribute('type', 'text');
    $entry->appendChild($summary);

    echo $dom->saveXML() ?: '';
    exit;
}

function logOpdsAuthEvent(string $message): void
{
    $logPath = __DIR__ . '/data/opds-auth.log';
    @file_put_contents($logPath, '[' . gmdate(DATE_ATOM) . '] ' . $message . PHP_EOL, FILE_APPEND);
}

function buildOpdsAttachmentDisposition(string $name): string
{
    $fallback = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
    if (!is_string($fallback)) {
        $fallback = '';
    }
    $fallback = trim(preg_replace('/\s+/', ' ', $fallback) ?? '');
    if ($fallback === '' || $fallback === '.' || $fallback === '..') {
        $fallback = 'download.bin';
    }

    return 'attachment; filename="' . addcslashes($fallback, "\"\\")
        . '"; filename*=UTF-8\'\'' . rawurlencode($name);
}

function streamOpdsAsset(array $asset, string $requestMethod, bool $attachment, ScanService $scanService): void
{
    header('Content-Type: ' . (string) ($asset['mime_type'] ?? 'application/octet-stream'));
    header('Content-Length: ' . (string) ((int) ($asset['size'] ?? 0)));
    header('X-Content-Type-Options: nosniff');

    if ($attachment) {
        header('Content-Disposition: ' . buildOpdsAttachmentDisposition((string) ($asset['name'] ?? 'download.bin')));
        header('Cache-Control: private, max-age=3600');
    } else {
        header('Cache-Control: public, max-age=3600');
    }

    if ($requestMethod === 'HEAD') {
        exit;
    }

    $internalUri = AccelRedirect::internalUriFor((string) ($asset['path'] ?? ''), [
        '/__bookslib_internal/books' => $scanService->getLibraryPath(),
        '/__bookslib_internal/thumb' => $scanService->getThumbDir(),
        '/__bookslib_internal/legacy-thumb' => __DIR__ . DIRECTORY_SEPARATOR . 'thumb',
    ]);
    if ($internalUri !== null) {
        AccelRedirect::send($internalUri);
    }

    $handle = fopen((string) ($asset['path'] ?? ''), 'rb');
    if ($handle === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Cannot open file.\n";
        exit;
    }

    while (!feof($handle)) {
        $chunk = fread($handle, 8192);
        if ($chunk === false) {
            fclose($handle);
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "Cannot read file.\n";
            exit;
        }

        echo $chunk;
    }

    fclose($handle);
    exit;
}

function handleOpdsAssetShortcut(array $server, array $query, AuthService $authService): void
{
    $requestMethod = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Method not allowed.\n";
        exit;
    }

    $bookId = filter_var($query['id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if ($bookId === false || $bookId === null) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Invalid book id.\n";
        exit;
    }

    $scanService = new ScanService(__DIR__);
    $index = new LibraryIndex($scanService->getSqlitePath());
    try {
        $user = null;
        $userId = filter_var($server['OPDS_AUTH_USER_ID'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($userId !== false && $userId !== null) {
            $user = $authService->getUserById((int) $userId);
        } else {
            $user = $authService->getCurrentUser();
        }

        if (!$index->isBookVisible(
            (int) $bookId,
            $authService->getUserHiddenAuthors($user),
            $authService->getUserHiddenTags($user)
        )) {
            throw new HttpException(404, 'Book not found.');
        }
    } finally {
        $index->close();
    }

    $assetService = new OpdsAssetService(__DIR__, $scanService);
    $feed = strtolower(trim((string) ($query['feed'] ?? 'index')));
    if ($feed === 'cover') {
        streamOpdsAsset($assetService->resolveCoverByBookId((int) $bookId), $requestMethod, false, $scanService);
    }

    if ($feed === 'download') {
        $format = trim((string) ($query['format'] ?? ''));
        streamOpdsAsset(
            $assetService->resolveDownloadByBookId((int) $bookId, $format === '' ? null : $format),
            $requestMethod,
            true,
            $scanService
        );
    }
}

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

if ($authService->isEnabled() && $token === null) {
    $queryToken = trim((string) ($query['token'] ?? ''));
    if ($queryToken !== '') {
        $token = $queryToken;
    }
}
unset($query['token']);

$isTokenAuthenticated = false;
if ($authService->isEnabled() && $token !== null) {
    $tokenUser = $authService->findUserByToken($token);
    if (is_array($tokenUser)) {
        $isTokenAuthenticated = true;
        $_SERVER['OPDS_AUTH_USER_ID'] = (string) ((int) ($tokenUser['id'] ?? 0));
        $_SERVER['OPDS_BASE_PATH'] = '/opds/' . rawurlencode($token);
        logOpdsAuthEvent('token-auth ok uri=' . ((string) ($_SERVER['REQUEST_URI'] ?? '')) . ' user=' . ((string) ($tokenUser['username'] ?? '')));
    }
}

if ($authService->isEnabled() && !$isTokenAuthenticated) {
    if ($token !== null) {
        logOpdsAuthEvent('token-auth fail uri=' . ((string) ($_SERVER['REQUEST_URI'] ?? '')) . ' token_prefix=' . substr($token, 0, 8));
        emitOpdsAuthError(401, 'Unauthorized', 'Unauthorized OPDS token.');
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
        logOpdsAuthEvent('basic-auth required uri=' . ((string) ($_SERVER['REQUEST_URI'] ?? '')));
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="myBoooksLib OPDS", charset="UTF-8"');
        emitOpdsAuthError(401, 'Authentication Required', 'Authentication required.');
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

$resolvedFeed = strtolower(trim((string) ($query['feed'] ?? 'index')));
if (in_array($resolvedFeed, ['cover', 'download'], true)) {
    try {
        handleOpdsAssetShortcut($_SERVER, $query, $authService);
    } catch (HttpException $e) {
        http_response_code($e->getStatusCode());
        header('Content-Type: text/plain; charset=UTF-8');
        echo $e->getMessage();
        exit;
    } catch (\Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $e->getMessage();
        exit;
    }
}

(new OpdsController(__DIR__))->handle($_SERVER, $query);
