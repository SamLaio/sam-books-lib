<?php

namespace Calibre\Controllers;

use Calibre\Http\View;
use Calibre\LibraryIndex;
use Calibre\ScanLauncher;
use Calibre\ScanService;
use Calibre\Services\AuthService;
use Calibre\Services\ScanScheduleService;
use Calibre\Support\CatalogRequest;
use Calibre\Support\CatalogUrlGenerator;
use Calibre\Support\Lang;
use Calibre\Support\Pagination;

final class CatalogController
{
    private string $appRoot;
    private View $view;

    public function __construct(string $appRoot, ?View $view = null)
    {
        $this->appRoot = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->view = $view ?? new View($this->appRoot . DIRECTORY_SEPARATOR . 'views');
    }

    public function handle(array $server, array $get, array $post): void
    {
        $defaultSortField = 'added_at';
        $defaultSortDirection = 'desc';

        try {
            $defaultScanService = new ScanService($this->appRoot);
            $defaultSortField = $defaultScanService->getCatalogDefaultSortField();
            $defaultSortDirection = $defaultScanService->getCatalogDefaultSortDirection();
        } catch (\Throwable) {
        }

        try {
            $defaultAuthService = new AuthService($this->appRoot);
            $preferredSort = $defaultAuthService->getPreferredCatalogSort($defaultSortField, $defaultSortDirection);
            $defaultSortField = (string) ($preferredSort['field'] ?? $defaultSortField);
            $defaultSortDirection = (string) ($preferredSort['direction'] ?? $defaultSortDirection);
        } catch (\Throwable) {
        }

        $request = CatalogRequest::fromGlobals(
            $server,
            $get,
            $post,
            $defaultSortField,
            $defaultSortDirection
        );
        $urlGenerator = new CatalogUrlGenerator(
            $request->getQuery(),
            $request->getPerPage(),
            $request->getSortField(),
            $request->getSortDirection()
        );

        $viewData = [
            'pageTitle' => null,
            'siteTitle' => null,
            'query' => $request->getQuery(),
            'perPage' => $request->getPerPage(),
            'perPageOptions' => CatalogRequest::PER_PAGE_OPTIONS,
            'sortField' => $request->getSortField(),
            'sortDirection' => $request->getSortDirection(),
            'searchAction' => 'index.php',
            'rebuildAction' => 'index.php',
            'scanRequestAction' => 'scan_request.php',
            'readStatusAction' => 'read.php',
            'authEnabled' => false,
            'settingsUrl' => 'settings.php',
            'adminSettingsUrl' => 'admin_settings.php',
            'isAdmin' => false,
            'logoutUrl' => 'logout.php',
            'currentUsername' => null,
            'currentUserEmail' => '',
            'smtpEnabled' => false,
            'canSendBookByEmail' => false,
            'currentTheme' => 'light',
            'themeUpdateAction' => 'theme.php',
            'themeCookieName' => AuthService::THEME_COOKIE_NAME,
            'clearUrl' => $urlGenerator->clear(),
            'sortHeaders' => $this->buildSortHeaders(
                $urlGenerator,
                $request->getSortField(),
                $request->getSortDirection()
            ),
            'perPageLinks' => $this->buildPerPageLinks($urlGenerator),
            'rows' => [],
            'notice' => null,
            'error' => null,
            'lastRebuildAt' => null,
            'scanRunning' => false,
            'totalRows' => 0,
            'totalPages' => 1,
            'currentPage' => 1,
            'pageStart' => 0,
            'pageEnd' => 0,
            'pagination' => [
                'previousUrl' => null,
                'nextUrl' => null,
                'items' => [],
            ],
        ];

        try {
            $scanService = new ScanService($this->appRoot);
            $siteTitle = $scanService->getSiteTitle();
            $viewData['pageTitle'] = $siteTitle;
            $viewData['siteTitle'] = $siteTitle;
            $authService = new AuthService($this->appRoot, $scanService);
            $viewData['authEnabled'] = $authService->isEnabled();

            $currentUser = $authService->getCurrentUser();
            $hiddenAuthors = $authService->getUserHiddenAuthors($currentUser);
            $hiddenTags = $authService->getUserHiddenTags($currentUser);
            $viewData['currentUsername'] = $currentUser['username'] ?? null;
            $viewData['currentUserEmail'] = is_array($currentUser) ? trim((string) ($currentUser['email'] ?? '')) : '';
            $viewData['smtpEnabled'] = $authService->isSmtpConfigured();
            $viewData['canSendBookByEmail'] = $viewData['currentUserEmail'] !== ''
                && filter_var($viewData['currentUserEmail'], FILTER_VALIDATE_EMAIL) !== false
                && $viewData['smtpEnabled'];
            $viewData['currentTheme'] = $authService->getPreferredTheme();
            $viewData['isAdmin'] = $authService->isCurrentUserAdmin();
            $viewData['settingsUrl'] = 'settings.php';
            $viewData['adminSettingsUrl'] = 'admin_settings.php';
            if ($viewData['authEnabled']) {
                $authService->persistThemeCookie($viewData['currentTheme']);
            }
            $authService->persistCatalogSortPreference($request->getSortField(), $request->getSortDirection());
            $scanLauncher = new ScanLauncher($this->appRoot, $scanService);
            $notice = isset($get['scan_notice']) && is_string($get['scan_notice'])
                ? trim((string) $get['scan_notice'])
                : null;
            if (($notice === null || $notice === '') && $authService->isPasswordChangeRequired()) {
                $notice = Lang::t('message.default_password_notice_settings');
            }
            $scanRunning = false;

            if ($request->isRebuildRequested() || $request->isCoverRebuildRequested()) {
                $scheduleService = new ScanScheduleService($this->appRoot);
                if ($request->isCoverRebuildRequested()) {
                    $scheduled = $scheduleService->enqueueManualAfterAllJobs('rebuild_cover', 60);
                    $notice = Lang::t('message.cover_rebuild_queued', [
                        'time' => (string) ($scheduled['run_at'] ?? date('c')),
                    ]);
                } else {
                    $scheduled = $scheduleService->enqueueManual('rebuild');
                    $notice = Lang::t('message.rebuild_queued', [
                        'time' => (string) ($scheduled['run_at'] ?? date('c')),
                    ]);
                }

                $redirectQuery = [];
                if ($request->getQuery() !== '') {
                    $redirectQuery['q'] = $request->getQuery();
                }
                $redirectQuery['per_page'] = $request->getPerPage();
                $redirectQuery['sort'] = $request->getSortField();
                $redirectQuery['direction'] = $request->getSortDirection();
                $redirectQuery['scan_notice'] = $notice;

                $location = 'index.php';
                $queryString = http_build_query($redirectQuery, '', '&', PHP_QUERY_RFC3986);
                if ($queryString !== '') {
                    $location .= '?' . $queryString;
                }

                header('Location: ' . $location, true, 303);
                exit;
            }

            if (!$scanRunning) {
                $scanRunning = $scanLauncher->isRunning();
            }
            if (!$scanRunning) {
                $scheduleService = new ScanScheduleService($this->appRoot);
                $scanRunning = $scheduleService->hasBlockingPendingJob();
            }

            $index = new LibraryIndex($scanService->getSqlitePath());
            $totalRows = $index->countSearchResults($request->getQuery(), $hiddenAuthors, $hiddenTags);
            $totalPages = max(1, (int) ceil($totalRows / $request->getPerPage()));
            $currentPage = min($request->getRequestedPage(), $totalPages);
            $rows = $index->getBooksPage(
                $request->getQuery(),
                $request->getPerPage(),
                ($currentPage - 1) * $request->getPerPage(),
                $request->getSortField(),
                $request->getSortDirection(),
                $hiddenAuthors,
                $hiddenTags
            );
            $lastRebuildAt = $this->formatDateTimeDisplay($index->getLastRebuildAt());

            $pageStart = 0;
            $pageEnd = 0;
            if ($totalRows > 0) {
                $pageStart = (($currentPage - 1) * $request->getPerPage()) + 1;
                $pageEnd = $pageStart + count($rows) - 1;
            }

            if ($totalRows === 0 && $lastRebuildAt === null && $notice === null) {
                $notice = $scanRunning
                    ? Lang::t('message.index_rebuilding')
                    : Lang::t('message.index_missing');
            }

            $mailNotice = isset($get['mail_notice']) && is_string($get['mail_notice'])
                ? trim((string) $get['mail_notice'])
                : '';
            $mailError = isset($get['mail_error']) && is_string($get['mail_error'])
                ? trim((string) $get['mail_error'])
                : '';

            if ($mailNotice !== '') {
                $notice = $mailNotice;
            }
            if ($mailError !== '') {
                $viewData['error'] = $mailError;
            }

            $viewData['notice'] = $notice;
            $viewData['scanRunning'] = $scanRunning;
            $viewData['lastRebuildAt'] = $lastRebuildAt;
            $currentCatalogUrl = $this->resolveCurrentCatalogUrl($server);
            $viewData['rows'] = $this->buildRows(
                $rows,
                $urlGenerator,
                (bool) $viewData['canSendBookByEmail'],
                $currentCatalogUrl
            );
            $viewData['totalRows'] = $totalRows;
            $viewData['totalPages'] = $totalPages;
            $viewData['currentPage'] = $currentPage;
            $viewData['pageStart'] = $pageStart;
            $viewData['pageEnd'] = $pageEnd;
            $viewData['pagination'] = $this->buildPagination($urlGenerator, $currentPage, $totalPages);
        } catch (\Throwable $e) {
            $viewData['error'] = $e->getMessage();
        }

        echo $this->view->renderPage('catalog/index', $viewData);
    }

    private function buildRows(
        array $rows,
        CatalogUrlGenerator $urlGenerator,
        bool $canSendBookByEmail,
        string $currentCatalogUrl
    ): array
    {
        $renderedRows = [];

        foreach ($rows as $row) {
            $coverPreviewUrl = null;
            if (isset($row['id']) && trim((string) ($row['cover_path'] ?? '')) !== '') {
                $coverPreviewUrl = 'opds.php?feed=cover&id=' . (int) $row['id'];
            }

            $baseRow = $row + [
                'download_url' => isset($row['id']) ? 'download.php?id=' . (int) $row['id'] : null,
                'send_url' => $canSendBookByEmail && isset($row['id']) ? 'send.php?id=' . (int) $row['id'] : null,
                'read_url' => (isset($row['id']) && $this->hasReadableBook($row))
                    ? 'reader.php?id=' . (int) $row['id'] . '&back=' . rawurlencode($currentCatalogUrl)
                    : null,
                'details_url' => isset($row['id'])
                    ? 'book.php?id=' . (int) $row['id'] . '&back=' . rawurlencode($currentCatalogUrl)
                    : null,
                'cover_preview_url' => $coverPreviewUrl,
                'author_links' => $this->buildAuthorLinks((string) ($row['author'] ?? ''), $urlGenerator),
                'series_link' => $this->buildSeriesLink((string) ($row['series'] ?? ''), $urlGenerator),
                'tag_links' => $this->buildTagLinks((string) ($row['tag'] ?? '')),
                'version_label' => $this->resolveVersionLabelFromPath((string) ($row['path'] ?? '')),
                'is_read' => !empty($row['is_read']),
                'added_at' => $this->formatAddedAt(
                    (string) ($row['library_timestamp'] ?? ''),
                    (string) ($row['created_at'] ?? '')
                ),
            ];

            $renderedRows[] = $baseRow;
        }

        return $renderedRows;
    }

    private function resolveCurrentCatalogUrl(array $server): string
    {
        $requestUri = (string) ($server['REQUEST_URI'] ?? '');
        if ($requestUri === '') {
            return 'index.php';
        }

        $parts = parse_url($requestUri);
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $query = (string) ($parts['query'] ?? '');

        if ($path === '' || $path === 'index.php') {
            return $query !== '' ? 'index.php?' . $query : 'index.php';
        }

        return 'index.php';
    }

    private function hasReadableBook(array $row): bool
    {
        $readableFormats = ['epub', 'pdf', 'cbz'];
        $path = trim((string) ($row['path'] ?? ''));
        if (in_array(strtolower((string) pathinfo($path, PATHINFO_EXTENSION)), $readableFormats, true)) {
            return true;
        }

        $formatsJson = $row['formats_json'] ?? null;
        if (!is_string($formatsJson) || trim($formatsJson) === '') {
            return false;
        }

        $formats = json_decode($formatsJson, true);
        if (!is_array($formats)) {
            return false;
        }

        foreach ($formats as $format => $candidatePath) {
            if (in_array(strtolower(trim((string) $format)), $readableFormats, true)) {
                return true;
            }

            if (is_string($candidatePath) && in_array(strtolower((string) pathinfo($candidatePath, PATHINFO_EXTENSION)), $readableFormats, true)) {
                return true;
            }
        }

        return false;
    }

    private function resolveVersionLabelFromPath(string $path): string
    {
        $extension = strtolower(trim((string) pathinfo($path, PATHINFO_EXTENSION)));
        if ($extension === '') {
            return '';
        }

        return strtoupper($extension);
    }

    private function buildAuthorLinks(string $authorValue, CatalogUrlGenerator $urlGenerator): array
    {
        $parts = preg_split('/\s*(?:,|，)\s*/u', trim($authorValue)) ?: [];
        $parts = array_values(array_unique(array_filter(array_map('trim', $parts), static function (string $value): bool {
            return $value !== '';
        })));

        if ($parts === [] && trim($authorValue) !== '') {
            $parts = [trim($authorValue)];
        }

        return array_map(static function (string $authorName) use ($urlGenerator): array {
            return [
                'label' => $authorName,
                'url' => $urlGenerator->search($authorName),
            ];
        }, $parts);
    }

    private function buildSeriesLink(string $seriesValue, CatalogUrlGenerator $urlGenerator): ?array
    {
        $seriesValue = trim($seriesValue);
        if ($seriesValue === '') {
            return null;
        }

        return [
            'label' => $seriesValue,
            'url' => $urlGenerator->search($seriesValue),
        ];
    }

    private function buildTagLinks(string $tagValue): array
    {
        $parts = preg_split('/\s*(?:,|，)\s*/u', trim($tagValue)) ?: [];
        $parts = array_values(array_unique(array_filter(array_map('trim', $parts), static function (string $value): bool {
            return $value !== '';
        })));

        return array_map(static function (string $tagName): array {
            return [
                'label' => $tagName,
            ];
        }, $parts);
    }

    private function buildPerPageLinks(CatalogUrlGenerator $urlGenerator): array
    {
        $links = [];

        foreach (CatalogRequest::PER_PAGE_OPTIONS as $option) {
            $links[] = [
                'label' => (string) $option,
                'value' => $option,
                'url' => $urlGenerator->perPage($option),
            ];
        }

        return $links;
    }

    private function buildSortHeaders(
        CatalogUrlGenerator $urlGenerator,
        string $currentSortField,
        string $currentSortDirection
    ): array {
        return [
            'is_read' => [
                'label' => Lang::t('catalog.read'),
                'url' => $urlGenerator->sort('is_read'),
                'indicator' => $this->sortIndicator($currentSortField, $currentSortDirection, 'is_read'),
            ],
            'title' => [
                'label' => Lang::t('catalog.title'),
                'url' => $urlGenerator->sort('title'),
                'indicator' => $this->sortIndicator($currentSortField, $currentSortDirection, 'title'),
            ],
            'author' => [
                'label' => Lang::t('catalog.author'),
                'url' => $urlGenerator->sort('author'),
                'indicator' => $this->sortIndicator($currentSortField, $currentSortDirection, 'author'),
            ],
            'series' => [
                'label' => Lang::t('catalog.series'),
                'url' => $urlGenerator->sort('series'),
                'indicator' => $this->sortIndicator($currentSortField, $currentSortDirection, 'series'),
            ],
            'added_at' => [
                'label' => Lang::t('catalog.added_at'),
                'url' => $urlGenerator->sort('added_at'),
                'indicator' => $this->sortIndicator($currentSortField, $currentSortDirection, 'added_at'),
            ],
        ];
    }

    private function formatAddedAt(string $libraryTimestamp, string $createdAt): string
    {
        $candidate = trim($libraryTimestamp) !== '' ? trim($libraryTimestamp) : trim($createdAt);
        if ($candidate === '') {
            return '';
        }

        $time = strtotime($candidate);
        if ($time === false) {
            return $candidate;
        }

        return date('Y-m-d', $time);
    }

    private function formatDateTimeDisplay(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $time = strtotime($raw);
        if ($time === false) {
            return $raw;
        }

        return date('Y-m-d H:i:s', $time);
    }

    private function sortIndicator(string $currentSortField, string $currentSortDirection, string $targetField): string
    {
        if ($currentSortField !== $targetField) {
            return '';
        }

        return $currentSortDirection === 'desc' ? ' ▼' : ' ▲';
    }

    private function buildPagination(CatalogUrlGenerator $urlGenerator, int $currentPage, int $totalPages): array
    {
        $items = [];
        $previousPage = null;

        foreach (Pagination::pages($currentPage, $totalPages) as $pageNumber) {
            if ($previousPage !== null && $pageNumber - $previousPage > 1) {
                $items[] = ['type' => 'ellipsis'];
            }

            $items[] = [
                'type' => 'page',
                'number' => $pageNumber,
                'current' => $pageNumber === $currentPage,
                'url' => $pageNumber === $currentPage ? null : $urlGenerator->page($pageNumber),
            ];

            $previousPage = $pageNumber;
        }

        return [
            'previousUrl' => $currentPage > 1 ? $urlGenerator->page($currentPage - 1) : null,
            'nextUrl' => $currentPage < $totalPages ? $urlGenerator->page($currentPage + 1) : null,
            'items' => $items,
        ];
    }
}
