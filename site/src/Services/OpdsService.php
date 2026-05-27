<?php

namespace Calibre\Services;

use Calibre\Http\HttpException;
use Calibre\LibraryIndex;
use Calibre\ScanService;
use Calibre\Support\OpdsUrlGenerator;

final class OpdsService
{
    private const ATOM_NS = 'http://www.w3.org/2005/Atom';
    private const XHTML_NS = 'http://www.w3.org/1999/xhtml';
    private const OPDS_NS = 'http://opds-spec.org/2010/catalog';
    private const OPENSEARCH_NS = 'http://a9.com/-/spec/opensearch/1.1/';
    private const THR_NS = 'http://purl.org/syndication/thread/1.0';
    private const DC_NS = 'http://purl.org/dc/elements/1.1/';
    private const DCTERMS_NS = 'http://purl.org/dc/terms/';
    private const ROOT_FEED_ID = 'urn:uuid:2853dacf-ed79-42f5-8e8a-a7bb3d1ae6a2';
    private const CATALOG_ICON_PATH = 'assets/opds/catalog.svg';

    private string $appRoot;
    private string $siteTitle;
    private ?string $siteBaseUrl;
    private int $pageSize;
    private LibraryIndex $index;
    private OpdsAssetService $assetService;

    public function __construct(
        string $appRoot,
        ?ScanService $scanService = null,
        ?LibraryIndex $index = null,
        ?OpdsAssetService $assetService = null
    ) {
        $this->appRoot = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $scanService = $scanService ?? new ScanService($this->appRoot);
        $this->siteTitle = $scanService->getSiteTitle();
        $this->siteBaseUrl = $scanService->getSiteBaseUrl();
        $this->pageSize = $scanService->getOpdsPageSize();
        $this->index = $index ?? new LibraryIndex($scanService->getSqlitePath());
        $this->assetService = $assetService ?? new OpdsAssetService($this->appRoot, $scanService);
    }

    public function renderCatalog(array $server, array $query, array $visibility = []): string
    {
        $feedName = strtolower(trim((string) ($query['feed'] ?? 'index')));
        $offset = $this->normalizeOffset($query['offset'] ?? 0);
        $urls = new OpdsUrlGenerator($server, $this->siteBaseUrl);
        $hiddenAuthors = $this->normalizeVisibilityList($visibility['hidden_authors'] ?? []);
        $hiddenTags = $this->normalizeVisibilityList($visibility['hidden_tags'] ?? []);

        return match ($feedName) {
            '', 'index' => $this->renderRootFeed($urls, $hiddenAuthors, $hiddenTags),
            'books' => $this->renderAlphabeticalBooksFeed($offset, $urls, $hiddenAuthors, $hiddenTags),
            'new' => $this->renderNewBooksFeed($offset, $urls, $hiddenAuthors, $hiddenTags),
            'authors' => $this->renderFacetNavigationFeed('Authors', 'author', 'author', $offset, $urls, $hiddenAuthors, $hiddenTags),
            'author' => $this->renderFacetBooksFeed('Author', 'author', $this->requireValue($query, 'value'), $offset, $urls, $hiddenAuthors, $hiddenTags),
            'tags' => $this->renderFacetNavigationFeed('Category list', 'tag', 'tag', $offset, $urls, $hiddenAuthors, $hiddenTags),
            'tag' => $this->renderFacetBooksFeed('Category', 'tag', $this->requireValue($query, 'value'), $offset, $urls, $hiddenAuthors, $hiddenTags),
            'series' => $this->renderFacetNavigationFeed('Series list', 'series', 'series_books', $offset, $urls, $hiddenAuthors, $hiddenTags),
            'series_books' => $this->renderFacetBooksFeed('Series', 'series', $this->requireValue($query, 'value'), $offset, $urls, $hiddenAuthors, $hiddenTags),
            'read' => $this->renderReadStatusFeed(true, $offset, $urls, $hiddenAuthors, $hiddenTags),
            'unread' => $this->renderReadStatusFeed(false, $offset, $urls, $hiddenAuthors, $hiddenTags),
            'search' => $this->renderSearchFeed(trim((string) ($query['query'] ?? '')), $offset, $urls, $hiddenAuthors, $hiddenTags),
            default => throw new HttpException(404, 'OPDS feed not found.'),
        };
    }

    /**
     * Build all deterministic OPDS catalog query variants for static generation.
     *
     * @return array<int, array<string, scalar>>
     */
    public function buildStaticCatalogQueries(): array
    {
        $queries = [];
        $seen = [];

        $append = function (array $query) use (&$queries, &$seen): void {
            $normalized = $this->normalizeStaticQuery($query);
            $key = $this->buildStaticQueryKey($normalized);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $queries[] = $normalized;
        };

        $append([]);

        $this->appendPagedQueries($append, 'books', [], $this->index->countBooks());
        $this->appendPagedQueries($append, 'new', [], $this->index->countBooks());

        $authors = $this->buildFacetIndex('author');
        $tags = $this->buildFacetIndex('tag');
        $series = $this->buildFacetIndex('series');

        $this->appendPagedQueries($append, 'authors', [], count($authors));
        $this->appendPagedQueries($append, 'tags', [], count($tags));
        $this->appendPagedQueries($append, 'series', [], count($series));

        foreach ($authors as $item) {
            $value = (string) ($item['value'] ?? '');
            if ($value === '') {
                continue;
            }

            $count = (int) ($item['count'] ?? 0);
            $this->appendPagedQueries($append, 'author', ['value' => $value], $count);
        }

        foreach ($tags as $item) {
            $value = (string) ($item['value'] ?? '');
            if ($value === '') {
                continue;
            }

            $count = (int) ($item['count'] ?? 0);
            $this->appendPagedQueries($append, 'tag', ['value' => $value], $count);
        }

        foreach ($series as $item) {
            $value = (string) ($item['value'] ?? '');
            if ($value === '') {
                continue;
            }

            $count = (int) ($item['count'] ?? 0);
            $this->appendPagedQueries($append, 'series_books', ['value' => $value], $count);
        }

        return $queries;
    }

    public function renderStaticCatalog(array $query): string
    {
        $server = [
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_NAME' => '/opds.php',
            'REQUEST_URI' => '/opds',
            'OPDS_BASE_PATH' => '/opds',
            'HTTP_HOST' => 'localhost',
        ];

        return $this->renderCatalog($server, $query);
    }

    public function normalizeStaticQuery(array $query): array
    {
        $feed = strtolower(trim((string) ($query['feed'] ?? 'index')));
        if ($feed === '' || $feed === 'index') {
            $feed = 'index';
        }

        $normalized = [];

        if ($feed !== 'index') {
            $normalized['feed'] = $feed;
        }

        $offset = $this->normalizeOffset($query['offset'] ?? 0);
        if ($offset > 0) {
            $normalized['offset'] = $offset;
        }

        if (isset($query['value']) && is_scalar($query['value'])) {
            $value = trim((string) $query['value']);
            if ($value !== '') {
                $normalized['value'] = $value;
            }
        }

        if (isset($query['query']) && is_scalar($query['query'])) {
            $search = trim((string) $query['query']);
            if ($search !== '') {
                $normalized['query'] = $search;
            }
        }

        ksort($normalized);

        return $normalized;
    }

    public function buildStaticQueryKey(array $query): string
    {
        $normalized = $this->normalizeStaticQuery($query);
        if ($normalized === []) {
            return 'index';
        }

        return http_build_query($normalized, '', '&', PHP_QUERY_RFC3986);
    }

    public function responseContentType(string $feedName): string
    {
        $feed = strtolower(trim($feedName));
        $kind = match ($feed) {
            '', 'index', 'authors', 'tags', 'series' => 'navigation',
            'books', 'new', 'author', 'tag', 'series_books', 'read', 'unread', 'search' => 'acquisition',
            default => 'navigation',
        };

        return $this->feedType($kind) . '; charset=UTF-8';
    }

    public function isBookVisible(int $bookId, array $visibility = []): bool
    {
        $hiddenAuthors = $this->normalizeVisibilityList($visibility['hidden_authors'] ?? []);
        $hiddenTags = $this->normalizeVisibilityList($visibility['hidden_tags'] ?? []);

        return $this->index->isBookVisible($bookId, $hiddenAuthors, $hiddenTags);
    }

    public function getLibraryPath(): string
    {
        return $this->assetService->getLibraryPath();
    }

    public function getThumbDir(): string
    {
        return $this->assetService->getThumbDir();
    }

    public function renderSearchDescription(array $server): string
    {
        $urls = new OpdsUrlGenerator($server, $this->siteBaseUrl);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::OPENSEARCH_NS, 'OpenSearchDescription');
        $dom->appendChild($root);

        $this->appendTextElement($dom, $root, self::OPENSEARCH_NS, 'ShortName', $this->siteTitle);
        $this->appendTextElement($dom, $root, self::OPENSEARCH_NS, 'Description', $this->siteTitle . ' OPDS catalog');
        $this->appendTextElement($dom, $root, self::OPENSEARCH_NS, 'InputEncoding', 'UTF-8');
        $this->appendTextElement($dom, $root, self::OPENSEARCH_NS, 'OutputEncoding', 'UTF-8');

        $url = $dom->createElementNS(self::OPENSEARCH_NS, 'Url');
        $url->setAttribute('type', $this->feedType('acquisition'));
        $url->setAttribute('template', $urls->searchTemplate());
        $root->appendChild($url);

        return $dom->saveXML() ?: '';
    }

    private function renderRootFeed(OpdsUrlGenerator $urls, array $hiddenAuthors = [], array $hiddenTags = []): string
    {
        $dom = $this->createFeedDocument(
            $this->siteTitle,
            self::ROOT_FEED_ID,
            $urls->index(),
            'navigation',
            $urls,
            false
        );
        $feed = $dom->documentElement;
        if (!$feed instanceof \DOMElement) {
            throw new \RuntimeException('Failed to create OPDS root feed.');
        }

        $bookCount = $this->index->countBooks($hiddenAuthors, $hiddenTags);
        $authorCount = count($this->buildFacetIndex('author', $hiddenAuthors, $hiddenTags));
        $tagCount = count($this->buildFacetIndex('tag', $hiddenAuthors, $hiddenTags));
        $seriesCount = count($this->buildFacetIndex('series', $hiddenAuthors, $hiddenTags));
        $readCount = $this->index->countReadStatus(true, $hiddenAuthors, $hiddenTags);
        $unreadCount = max(0, $bookCount - $readCount);

        $entries = [
            [
                'title' => 'Authors',
                'summary' => sprintf('Alphabetical index of the %d authors', $authorCount),
                'href' => $urls->feed('authors'),
                'kind' => 'navigation',
                'rel' => 'subsection',
                'count' => $authorCount,
            ],
            [
                'title' => 'Series',
                'summary' => sprintf('Alphabetical index of the %d series', $seriesCount),
                'href' => $urls->feed('series'),
                'kind' => 'navigation',
                'rel' => 'subsection',
                'count' => $seriesCount,
            ],
            [
                'title' => 'Tags',
                'summary' => sprintf('Alphabetical index of the %d tags', $tagCount),
                'href' => $urls->feed('tags'),
                'kind' => 'navigation',
                'rel' => 'subsection',
                'count' => $tagCount,
            ],
            [
                'title' => 'All books',
                'summary' => sprintf('Alphabetical index of the %d books', $bookCount),
                'href' => $urls->feed('books'),
                'kind' => 'navigation',
                'rel' => 'subsection',
                'count' => $bookCount,
            ],
            [
                'title' => 'Recent additions',
                'summary' => sprintf('%d most recent books', $bookCount),
                'href' => $urls->feed('new'),
                'kind' => 'acquisition',
                'rel' => 'http://opds-spec.org/sort/new',
                'count' => $bookCount,
            ],
            [
                'title' => 'Read Books',
                'summary' => sprintf('%d books marked as read', $readCount),
                'href' => $urls->feed('read'),
                'kind' => 'navigation',
                'rel' => 'subsection',
                'count' => $readCount,
            ],
            [
                'title' => 'Unread Books',
                'summary' => sprintf('%d books not marked as read', $unreadCount),
                'href' => $urls->feed('unread'),
                'kind' => 'navigation',
                'rel' => 'subsection',
                'count' => $unreadCount,
            ],
        ];

        $updatedAt = $this->currentTimestamp();
        foreach ($entries as $entry) {
            $this->appendNavigationEntry(
                $dom,
                $feed,
                $entry['title'],
                $entry['href'],
                $entry['href'],
                $updatedAt,
                $entry['summary'],
                $entry['kind'],
                $entry['rel'],
                $entry['count'],
                $urls->asset(self::CATALOG_ICON_PATH)
            );
        }

        return $dom->saveXML() ?: '';
    }

    private function renderAlphabeticalBooksFeed(int $offset, OpdsUrlGenerator $urls, array $hiddenAuthors = [], array $hiddenTags = []): string
    {
        $rows = $this->index->getBooksPage('', $this->pageSize, $offset, 'title', 'asc', $hiddenAuthors, $hiddenTags);
        $books = $this->hydrateBooks($rows);

        return $this->renderBookFeed(
            'Alphabetical Books',
            'books',
            [],
            $books,
            $this->index->countBooks($hiddenAuthors, $hiddenTags),
            $offset,
            $urls
        );
    }

    private function renderNewBooksFeed(int $offset, OpdsUrlGenerator $urls, array $hiddenAuthors = [], array $hiddenTags = []): string
    {
        $rows = $this->index->getRecentBooksPage($this->pageSize, $offset, $hiddenAuthors, $hiddenTags);
        $books = $this->hydrateBooks($rows);

        return $this->renderBookFeed(
            'New Books',
            'new',
            [],
            $books,
            $this->index->countBooks($hiddenAuthors, $hiddenTags),
            $offset,
            $urls
        );
    }

    private function renderFacetNavigationFeed(
        string $title,
        string $facet,
        string $targetFeed,
        int $offset,
        OpdsUrlGenerator $urls,
        array $hiddenAuthors = [],
        array $hiddenTags = []
    ): string {
        $values = $this->buildFacetIndex($facet, $hiddenAuthors, $hiddenTags);
        $slice = array_slice($values, $offset, $this->pageSize);
        $feedName = match ($facet) {
            'author' => 'authors',
            'tag' => 'tags',
            'series' => 'series',
            default => throw new HttpException(404, 'Unsupported OPDS facet.'),
        };

        $dom = $this->createFeedDocument(
            $title,
            $urls->feed($feedName, $this->offsetParams($offset)),
            $urls->feed($feedName, $this->offsetParams($offset)),
            'navigation',
            $urls,
            true
        );
        $feed = $dom->documentElement;
        if (!$feed instanceof \DOMElement) {
            throw new \RuntimeException('Failed to create OPDS navigation feed.');
        }

        $this->appendPageMetadata($dom, $feed, count($values), $offset);
        $this->appendPaginationLinks($dom, $feed, 'navigation', $feedName, [], $offset, count($values), $urls);

        $updatedAt = $this->currentTimestamp();
        foreach ($slice as $item) {
            $summary = sprintf('%d book(s)', $item['count']);
            $this->appendNavigationEntry(
                $dom,
                $feed,
                $item['value'],
                $urls->feed($targetFeed, ['value' => $item['value']]),
                $urls->feed($targetFeed, ['value' => $item['value']]),
                $updatedAt,
                $summary,
                'acquisition',
                'subsection',
                $item['count'],
                $urls->asset(self::CATALOG_ICON_PATH)
            );
        }

        return $dom->saveXML() ?: '';
    }

    private function renderFacetBooksFeed(
        string $titlePrefix,
        string $facet,
        string $value,
        int $offset,
        OpdsUrlGenerator $urls,
        array $hiddenAuthors = [],
        array $hiddenTags = []
    ): string {
        $rows = $this->index->getFacetBooksPage($facet, $value, $this->pageSize, $offset, $hiddenAuthors, $hiddenTags);
        $books = $this->hydrateBooks($rows);

        $feedName = match ($facet) {
            'author' => 'author',
            'tag' => 'tag',
            'series' => 'series_books',
            default => throw new HttpException(404, 'Unsupported OPDS facet feed.'),
        };

        return $this->renderBookFeed(
            $titlePrefix . ': ' . $value,
            $feedName,
            ['value' => $value],
            $books,
            $this->index->countFacetBooks($facet, $value, $hiddenAuthors, $hiddenTags),
            $offset,
            $urls
        );
    }

    private function renderReadStatusFeed(bool $isRead, int $offset, OpdsUrlGenerator $urls, array $hiddenAuthors = [], array $hiddenTags = []): string
    {
        $rows = $this->index->getReadStatusBooksPage($isRead, $this->pageSize, $offset, $hiddenAuthors, $hiddenTags);
        $books = $this->hydrateBooks($rows);
        $feedName = $isRead ? 'read' : 'unread';

        return $this->renderBookFeed(
            $isRead ? 'Read Books' : 'Unread Books',
            $feedName,
            [],
            $books,
            $this->index->countReadStatus($isRead, $hiddenAuthors, $hiddenTags),
            $offset,
            $urls
        );
    }

    private function renderSearchFeed(string $query, int $offset, OpdsUrlGenerator $urls, array $hiddenAuthors = [], array $hiddenTags = []): string
    {
        if ($query === '') {
            return $this->renderBookFeed('Search', 'search', ['query' => ''], [], 0, 0, $urls);
        }

        $rows = $this->index->getBooksPage($query, $this->pageSize, $offset, 'title', 'asc', $hiddenAuthors, $hiddenTags);
        $books = $this->hydrateBooks($rows);

        return $this->renderBookFeed(
            'Search: ' . $query,
            'search',
            ['query' => $query],
            $books,
            $this->index->countSearchResults($query, $hiddenAuthors, $hiddenTags),
            $offset,
            $urls
        );
    }

    private function renderBookFeed(
        string $title,
        string $feedName,
        array $baseParams,
        array $books,
        int $totalCount,
        int $offset,
        OpdsUrlGenerator $urls
    ): string {
        $selfParams = $baseParams + $this->offsetParams($offset);
        $selfUrl = $urls->feed($feedName, $selfParams);

        $dom = $this->createFeedDocument(
            $title,
            $selfUrl,
            $selfUrl,
            'acquisition',
            $urls,
            true
        );
        $feed = $dom->documentElement;
        if (!$feed instanceof \DOMElement) {
            throw new \RuntimeException('Failed to create OPDS acquisition feed.');
        }

        $this->appendPageMetadata($dom, $feed, $totalCount, $offset);
        $this->appendPaginationLinks($dom, $feed, 'acquisition', $feedName, $baseParams, $offset, $totalCount, $urls);

        foreach ($books as $book) {
            $this->appendBookEntry($dom, $feed, $book, $urls);
        }

        return $dom->saveXML() ?: '';
    }

    private function createFeedDocument(
        string $title,
        string $feedId,
        string $selfUrl,
        string $kind,
        OpdsUrlGenerator $urls,
        bool $includeUpLink
    ): \DOMDocument {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $feed = $dom->createElementNS(self::ATOM_NS, 'feed');
        $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xhtml', self::XHTML_NS);
        $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:opds', self::OPDS_NS);
        $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:opensearch', self::OPENSEARCH_NS);
        $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:thr', self::THR_NS);
        $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dc', self::DC_NS);
        $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dcterms', self::DCTERMS_NS);
        $dom->appendChild($feed);

        $this->appendTextElement($dom, $feed, self::ATOM_NS, 'id', $feedId);
        $this->appendTextElement($dom, $feed, self::ATOM_NS, 'title', $title);
        $this->appendTextElement($dom, $feed, self::ATOM_NS, 'updated', $this->currentTimestamp());
        $this->appendTextElement($dom, $feed, self::ATOM_NS, 'icon', $urls->asset(self::CATALOG_ICON_PATH));

        $author = $dom->createElementNS(self::ATOM_NS, 'author');
        $feed->appendChild($author);
        $this->appendTextElement($dom, $author, self::ATOM_NS, 'name', $this->siteTitle);
        $this->appendTextElement($dom, $author, self::ATOM_NS, 'uri', $urls->index());

        $this->appendLink($dom, $feed, 'self', $selfUrl, $this->feedType($kind));
        $this->appendLink($dom, $feed, 'start', $urls->index(), $this->feedType('navigation'));

        if ($includeUpLink) {
            $this->appendLink($dom, $feed, 'up', $urls->index(), $this->feedType('navigation'));
        }

        $this->appendLink($dom, $feed, 'search', $urls->searchDescription(), 'application/opensearchdescription+xml');

        return $dom;
    }

    private function appendPaginationLinks(
        \DOMDocument $dom,
        \DOMElement $feed,
        string $kind,
        string $feedName,
        array $baseParams,
        int $offset,
        int $totalCount,
        OpdsUrlGenerator $urls
    ): void {
        if ($totalCount <= $this->pageSize) {
            return;
        }

        $lastOffset = $this->normalizePageOffset($totalCount);

        if ($offset > 0) {
            $this->appendLink(
                $dom,
                $feed,
                'first',
                $urls->feed($feedName, $baseParams),
                $this->feedType($kind)
            );

            $previousOffset = max(0, $offset - $this->pageSize);
            $this->appendLink(
                $dom,
                $feed,
                'previous',
                $urls->feed($feedName, $baseParams + $this->offsetParams($previousOffset)),
                $this->feedType($kind)
            );
        }

        $nextOffset = $offset + $this->pageSize;
        if ($nextOffset < $totalCount) {
            $this->appendLink(
                $dom,
                $feed,
                'next',
                $urls->feed($feedName, $baseParams + $this->offsetParams($nextOffset)),
                $this->feedType($kind)
            );

            $this->appendLink(
                $dom,
                $feed,
                'last',
                $urls->feed($feedName, $baseParams + $this->offsetParams($lastOffset)),
                $this->feedType($kind)
            );
        }
    }

    private function appendPageMetadata(
        \DOMDocument $dom,
        \DOMElement $feed,
        int $totalCount,
        int $offset
    ): void {
        $startIndex = $totalCount === 0 ? 0 : $offset + 1;
        $itemsPerPage = $totalCount === 0 ? 0 : min($this->pageSize, max(0, $totalCount - $offset));

        $this->appendTextElement($dom, $feed, self::OPENSEARCH_NS, 'opensearch:totalResults', (string) $totalCount);
        $this->appendTextElement($dom, $feed, self::OPENSEARCH_NS, 'opensearch:itemsPerPage', (string) $itemsPerPage);
        $this->appendTextElement($dom, $feed, self::OPENSEARCH_NS, 'opensearch:startIndex', (string) $startIndex);
    }

    private function appendNavigationEntry(
        \DOMDocument $dom,
        \DOMElement $feed,
        string $title,
        string $href,
        string $id,
        string $updatedAt,
        ?string $summary,
        string $kind,
        ?string $rel = 'subsection',
        ?int $count = null,
        ?string $thumbnailHref = null
    ): void {
        $entry = $dom->createElementNS(self::ATOM_NS, 'entry');
        $feed->appendChild($entry);

        $this->appendTextElement($dom, $entry, self::ATOM_NS, 'title', $title);
        $this->appendTextElement($dom, $entry, self::ATOM_NS, 'id', $id);
        $this->appendTextElement($dom, $entry, self::ATOM_NS, 'updated', $updatedAt);
        $link = $this->appendLink($dom, $entry, $rel, $href, $this->feedType($kind));

        if ($count !== null && $count >= 0) {
            $link->setAttributeNS(self::THR_NS, 'thr:count', (string) $count);
        }

        if ($summary !== null && $summary !== '') {
            $content = $dom->createElementNS(self::ATOM_NS, 'content');
            $content->setAttribute('type', 'text');
            $content->appendChild($dom->createTextNode($summary));
            $entry->appendChild($content);
        }

        if ($thumbnailHref !== null && $thumbnailHref !== '') {
            $this->appendLink(
                $dom,
                $entry,
                'http://opds-spec.org/image/thumbnail',
                $thumbnailHref,
                'image/svg+xml'
            );
        }
    }

    private function appendBookEntry(\DOMDocument $dom, \DOMElement $feed, array $book, OpdsUrlGenerator $urls): void
    {
        $entry = $dom->createElementNS(self::ATOM_NS, 'entry');
        $feed->appendChild($entry);

        $metadata = $this->decodeMetadata($book);
        $bookId = (int) ($book['id'] ?? 0);
        $bookUuid = trim((string) ($book['uuid'] ?? ''));
        $entryId = $bookUuid !== '' ? 'urn:uuid:' . $bookUuid : 'urn:calibre:book:' . $bookId;
        $updatedAt = $this->bookUpdatedAt($book);
        $publishedAt = $this->bookPublishedAt($book);

        $this->appendTextElement($dom, $entry, self::ATOM_NS, 'title', trim((string) ($book['title'] ?? 'Untitled')));
        $this->appendTextElement($dom, $entry, self::ATOM_NS, 'id', $entryId);
        $this->appendTextElement($dom, $entry, self::ATOM_NS, 'updated', $updatedAt);
        $this->appendTextElement($dom, $entry, self::ATOM_NS, 'published', $publishedAt);

        foreach ($this->splitCsv((string) ($book['author'] ?? 'Unknown')) as $authorName) {
            $author = $dom->createElementNS(self::ATOM_NS, 'author');
            $entry->appendChild($author);
            $this->appendTextElement($dom, $author, self::ATOM_NS, 'name', $authorName);
            $this->appendTextElement($dom, $author, self::ATOM_NS, 'uri', $urls->feed('author', ['value' => $authorName]));
        }

        $summary = $this->extractDescription($book, $metadata);
        if ($summary !== null) {
            $summaryNode = $dom->createElementNS(self::ATOM_NS, 'summary');
            $summaryNode->setAttribute('type', 'text');
            $summaryNode->appendChild($dom->createTextNode($summary));
            $entry->appendChild($summaryNode);
        }

        $publisher = trim((string) ($book['publisher'] ?? ($metadata['publisher'] ?? '')));
        if ($publisher !== '') {
            $this->appendTextElement($dom, $entry, self::DC_NS, 'dc:publisher', $publisher);
        }

        $language = trim((string) ($book['language'] ?? ($metadata['language'] ?? '')));
        if ($language !== '') {
            $this->appendTextElement($dom, $entry, self::DC_NS, 'dc:language', $language);
        }

        $isbn = trim((string) ($book['isbn'] ?? ($metadata['isbn'] ?? '')));
        if ($isbn !== '') {
            $this->appendTextElement($dom, $entry, self::DC_NS, 'dc:identifier', $isbn);
        }

        if ($publishedAt !== '') {
            $this->appendTextElement($dom, $entry, self::DCTERMS_NS, 'dcterms:issued', $publishedAt);
        }

        foreach ($this->splitCsv((string) ($book['tag'] ?? ($metadata['tag'] ?? ''))) as $tag) {
            $category = $dom->createElementNS(self::ATOM_NS, 'category');
            $category->setAttribute('term', $tag);
            $category->setAttribute('label', $tag);
            $entry->appendChild($category);
        }

        $series = trim((string) ($book['series'] ?? ($metadata['series'] ?? '')));
        if ($series !== '') {
            $category = $dom->createElementNS(self::ATOM_NS, 'category');
            $category->setAttribute('term', $series);
            $category->setAttribute('label', $series);
            $category->setAttribute('scheme', 'series');
            $entry->appendChild($category);
        }

        $cover = $this->assetService->resolveExistingCoverForBook($book);
        if ($cover !== null || $this->assetService->canLazyResolveCoverForBook($book)) {
            $coverUrl = $urls->feed('cover', ['id' => $bookId]);
            $coverType = (string) ($cover['mime_type'] ?? 'image/jpeg');

            $thumbnailRel = 'http://opds-spec.org/image/thumbnail';
            $imageRel = 'http://opds-spec.org/image';

            $this->appendLink($dom, $entry, $thumbnailRel, $coverUrl, $coverType);
            $this->appendLink($dom, $entry, $imageRel, $coverUrl, $coverType);
        }

        foreach ($this->assetService->listDownloadablesForBook($book) as $download) {
            $link = $dom->createElementNS(self::ATOM_NS, 'link');
            $link->setAttribute('rel', 'http://opds-spec.org/acquisition');
            $link->setAttribute('href', $urls->download(
                $bookId,
                (string) ($download['format'] ?? ''),
                (string) ($download['name'] ?? '')
            ));
            $link->setAttribute('type', (string) ($download['mime_type'] ?? 'application/octet-stream'));
            $link->setAttribute('title', strtoupper((string) ($download['format'] ?? 'FILE')));
            $link->setAttribute('length', (string) ((int) ($download['size'] ?? 0)));

            $modifiedAt = trim((string) ($download['modified_at'] ?? ''));
            if ($modifiedAt !== '') {
                $link->setAttribute('mtime', $modifiedAt);
            }

            $entry->appendChild($link);
        }
    }

    private function buildFacetIndex(string $facet, array $hiddenAuthors = [], array $hiddenTags = []): array
    {
        $values = [];

        foreach ($this->index->iterateFacetValues($facet, $hiddenAuthors, $hiddenTags) as $rawValue) {
            $parts = match ($facet) {
                'author' => $this->splitCsv($rawValue),
                'tag' => $this->splitCsv($rawValue),
                'series' => $this->splitSingleValue($rawValue),
                default => throw new HttpException(404, 'Unsupported OPDS facet index.'),
            };

            foreach ($parts as $part) {
                $key = $this->normalizeKey($part);
                if (!isset($values[$key])) {
                    $values[$key] = [
                        'value' => $part,
                        'count' => 0,
                    ];
                }

                $values[$key]['count']++;
            }
        }

        uasort($values, static function (array $left, array $right): int {
            return strnatcasecmp((string) $left['value'], (string) $right['value']);
        });

        return array_values($values);
    }

    private function normalizeVisibilityList($values): array
    {
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(static function ($value): string {
            return is_scalar($value) ? trim((string) $value) : '';
        }, $values), static function (string $value): bool {
            return $value !== '';
        })));
    }

    private function hydrateBooks(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $bookId = (int) ($row['id'] ?? 0);
            if ($bookId < 1) {
                continue;
            }
            $ids[] = $bookId;
        }

        return $this->index->getBooksByIds($ids);
    }

    private function decodeMetadata(array $book): array
    {
        $metadataJson = $book['metadata_json'] ?? null;
        if (!is_string($metadataJson) || trim($metadataJson) === '') {
            return [];
        }

        $decoded = json_decode($metadataJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function extractDescription(array $book, array $metadata): ?string
    {
        $candidates = [
            $book['description'] ?? null,
            $metadata['description'] ?? null,
            $metadata['comments'] ?? null,
            $metadata['summary'] ?? null,
            $metadata['synopsis'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $normalized = trim(html_entity_decode(strip_tags($candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($normalized !== '') {
                return preg_replace('/\s+/u', ' ', $normalized) ?: $normalized;
            }
        }

        return null;
    }

    private function splitCsv(string $value): array
    {
        $parts = preg_split('/\s*(?:,|，)\s*/u', trim($value)) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $parts), static function (string $part): bool {
            return $part !== '';
        })));
    }

    private function splitSingleValue(string $value): array
    {
        $normalized = trim($value);

        return $normalized === '' ? [] : [$normalized];
    }

    private function appendTextElement(
        \DOMDocument $dom,
        \DOMElement $parent,
        string $namespace,
        string $qualifiedName,
        string $value
    ): void {
        $element = $dom->createElementNS($namespace, $qualifiedName);
        $element->appendChild($dom->createTextNode($value));
        $parent->appendChild($element);
    }

    private function appendLink(
        \DOMDocument $dom,
        \DOMElement $parent,
        ?string $rel,
        string $href,
        string $type,
        ?string $title = null
    ): \DOMElement {
        $link = $dom->createElementNS(self::ATOM_NS, 'link');
        if ($rel !== null && $rel !== '') {
            $link->setAttribute('rel', $rel);
        }
        $link->setAttribute('href', $href);
        $link->setAttribute('type', $type);

        if ($title !== null && $title !== '') {
            $link->setAttribute('title', $title);
        }

        $parent->appendChild($link);

        return $link;
    }

    private function normalizeOffset($value): int
    {
        if (!is_scalar($value)) {
            return 0;
        }

        $normalized = filter_var((string) $value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);

        return $normalized === false ? 0 : (int) $normalized;
    }

    private function requireValue(array $query, string $key): string
    {
        $value = trim((string) ($query[$key] ?? ''));
        if ($value === '') {
            throw new HttpException(400, 'Missing OPDS value parameter.');
        }

        return $value;
    }

    private function offsetParams(int $offset): array
    {
        return $offset > 0 ? ['offset' => $offset] : [];
    }

    private function normalizePageOffset(int $totalCount): int
    {
        if ($totalCount <= 0) {
            return 0;
        }

        return (int) (floor(($totalCount - 1) / $this->pageSize) * $this->pageSize);
    }

    private function currentTimestamp(): string
    {
        return gmdate(DATE_ATOM);
    }

    private function bookUpdatedAt(array $book): string
    {
        foreach (['library_last_modified', 'library_timestamp', 'created_at'] as $key) {
            $value = $this->normalizeDateValue($book[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return $this->currentTimestamp();
    }

    private function bookPublishedAt(array $book): string
    {
        foreach (['published_at', 'library_timestamp', 'created_at'] as $key) {
            $value = $this->normalizeDateValue($book[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return $this->currentTimestamp();
    }

    private function normalizeDateValue($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable(trim($value)))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    private function feedType(string $kind): string
    {
        return 'application/atom+xml;profile=opds-catalog;kind=' . $kind;
    }

    private function normalizeKey(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    private function appendPagedQueries(callable $append, string $feed, array $baseParams, int $totalCount): void
    {
        $baseQuery = ['feed' => $feed] + $baseParams;
        $append($baseQuery);

        if ($totalCount <= $this->pageSize) {
            return;
        }

        for ($offset = $this->pageSize; $offset < $totalCount; $offset += $this->pageSize) {
            $append($baseQuery + ['offset' => $offset]);
        }
    }
}
