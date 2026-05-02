<?php

namespace Calibre\Controllers;

use Calibre\Http\HttpException;
use Calibre\Http\View;
use Calibre\Services\ReaderAccessService;
use Calibre\Support\Lang;

final class ReaderController
{
    private string $appRoot;
    private View $view;
    private ReaderAccessService $accessService;

    public function __construct(string $appRoot, ?ReaderAccessService $accessService = null)
    {
        $this->appRoot = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->view = new View($this->appRoot . DIRECTORY_SEPARATOR . 'views');
        $this->accessService = $accessService ?? new ReaderAccessService($this->appRoot);
    }

    public function handle(array $server, array $query): void
    {
        $backUrl = $this->resolveBackUrl($query['back'] ?? null);
        $bookId = filter_var($query['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($bookId === false || $bookId === null) {
            $this->respondError(400, Lang::t('error.invalid_book_id'));
        }

        try {
            $resolved = $this->accessService->resolveReadableByBookId((int) $bookId);
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            echo $this->view->renderPage('reader/index', [
                'pageTitle' => trim((string) ($resolved['title'] ?? '')) . ' - ' . Lang::t('common.read'),
                'siteTitle' => ScanTitleResolver::resolve($this->appRoot),
                'pageStyles' => ['assets/css/reader.css'],
                'pageScripts' => ['assets/vendor/pdfjs/pdf.min.js', 'assets/js/reader.js'],
                'readerBookId' => (int) $resolved['book_id'],
                'readerBookTitle' => trim((string) ($resolved['title'] ?? '')),
                'readerBookFormat' => (string) ($resolved['format'] ?? ''),
                'readerManifestUrl' => 'reader_manifest.php?id=' . (int) $resolved['book_id'],
                'readerInitialPageUrl' => 'reader_page.php?id=' . (int) $resolved['book_id'],
                'backUrl' => $backUrl,
                'themeUpdateAction' => 'theme.php',
            ]);
        } catch (HttpException $e) {
            $this->respondError($e->getStatusCode(), $e->getMessage(), $backUrl);
        } catch (\Throwable $e) {
            $this->respondError(500, $e->getMessage(), $backUrl);
        }
    }

    private function respondError(int $status, string $message, string $backUrl = 'index.php'): void
    {
        http_response_code($status);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $this->view->renderPage('reader/index', [
            'pageTitle' => Lang::t('common.read'),
            'siteTitle' => ScanTitleResolver::resolve($this->appRoot),
            'pageStyles' => ['assets/css/reader.css'],
            'pageScripts' => ['assets/vendor/pdfjs/pdf.min.js', 'assets/js/reader.js'],
            'readerError' => $message,
            'readerBookId' => 0,
            'readerBookTitle' => '',
            'readerBookFormat' => '',
            'readerManifestUrl' => '',
            'readerInitialPageUrl' => '',
            'backUrl' => $backUrl,
            'themeUpdateAction' => 'theme.php',
        ]);
        exit;
    }

    private function resolveBackUrl($back): string
    {
        if (!is_string($back)) {
            return 'index.php';
        }

        $back = trim($back);
        if ($back === '') {
            return 'index.php';
        }

        $parts = parse_url($back);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return 'index.php';
        }

        $path = ltrim((string) ($parts['path'] ?? ''), '/');
        $query = (string) ($parts['query'] ?? '');
        $fragment = (string) ($parts['fragment'] ?? '');

        if ($path === '') {
            $path = 'index.php';
        }

        if ($path !== 'index.php') {
            return 'index.php';
        }

        $url = $path;
        if ($query !== '') {
            $url .= '?' . $query;
        }
        if ($fragment !== '') {
            $url .= '#' . $fragment;
        }

        return $url;
    }
}
