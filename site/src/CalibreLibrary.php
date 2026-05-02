<?php

namespace Calibre;

class CalibreLibrary
{
    private string $rootPath;
    private string $thumbDir;
    private array $previousBookCache = [];
    private array $previousDirectoryCache = [];
    private array $databaseKnownBookPaths = [];

    public function __construct(string $rootPath, ?string $thumbDir = null)
    {
        $this->rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR);
        $defaultThumbDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'thumb';
        $this->thumbDir = rtrim($thumbDir ?? $defaultThumbDir, DIRECTORY_SEPARATOR);
    }

    /**
     * Scan the Calibre library root and return a list of books.
     *
     * @return Book[]
     */
    public function scanLibrary(): array
    {
        return iterator_to_array($this->iterateLibrary(), false);
    }

    /**
     * Stream scanned books from Calibre library.
     *
     * @return \Generator<int, Book>
     */
    public function iterateLibrary(): \Generator
    {
        if (!is_dir($this->rootPath)) {
            throw new \InvalidArgumentException("Calibre library path does not exist: {$this->rootPath}");
        }

        $metadataDbPath = $this->rootPath . DIRECTORY_SEPARATOR . 'metadata.db';
        $this->databaseKnownBookPaths = [];
        $hasMetadataDb = false;
        if (is_file($metadataDbPath)) {
            $hasMetadataDb = true;
            yield from $this->iterateFromDatabase($metadataDbPath);
        }

        // Even with calibre metadata available, loose books may exist anywhere under the
        // library tree, not only directly under /books. Keep the filesystem pass, but
        // short-circuit it entirely when every filesystem path is already covered.
        if ($hasMetadataDb && $this->databaseKnownBookPaths !== []) {
            if (!$this->hasFilesystemBookOutsideDatabase()) {
                return;
            }
        }

        yield from $this->iterateFromFilesystem();
    }

    public function ensureBookCover(Book $book): ?string
    {
        $currentCover = $book->getCoverPath();
        if ($currentCover !== null && file_exists($currentCover)) {
            return $currentCover;
        }

        $bookPath = $book->getPath();
        if (is_file($bookPath)) {
            $bookPath = dirname($bookPath);
        }
        $coverFiles = ['cover.jpg', 'cover.jpeg', 'cover.png'];
        foreach ($coverFiles as $coverFile) {
            $coverPath = $bookPath . DIRECTORY_SEPARATOR . $coverFile;
            if (file_exists($coverPath)) {
                return $coverPath;
            }
        }

        return $this->extractCoverFromFormats($book->getFormats());
    }

    public function setPreviousBookCache(array $snapshotsByPath): void
    {
        $cache = [];
        $directoryCache = [];
        foreach ($snapshotsByPath as $path => $snapshot) {
            if (!is_string($path) || trim($path) === '' || !is_array($snapshot)) {
                continue;
            }

            $normalizedPath = $this->normalizePath($path);
            $cache[$normalizedPath] = $snapshot;
            $directory = $this->normalizePath(dirname($normalizedPath));
            if ($directory === '') {
                continue;
            }

            $directoryCache[$directory][$normalizedPath] = true;
        }

        $this->previousBookCache = $cache;
        $this->previousDirectoryCache = $directoryCache;
    }

    private function resolvePreferredSource(string $dbPath): string
    {
        if (!is_file($dbPath)) {
            return 'fs';
        }

        try {
            $dbCount = $this->countBooksFromDatabase($dbPath);
        } catch (\RuntimeException $e) {
            if (strpos($e->getMessage(), 'PDO SQLite extension is not loaded') !== false) {
                return 'fs';
            }

            throw $e;
        }

        if ($dbCount < 1) {
            return 'fs';
        }

        $filesystemCount = $this->countBooksFromFilesystem();

        // Prefer richer result set when metadata.db is stale/incomplete.
        return $filesystemCount > $dbCount ? 'fs' : 'db';
    }

    private function createMetadataPdo(string $dbPath): \PDO
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new \RuntimeException("PDO SQLite extension is not loaded. Cannot read metadata.db");
        }

        try {
            $pdo = new \PDO("sqlite:{$dbPath}");
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            return $pdo;
        } catch (\PDOException $e) {
            throw new \RuntimeException("Failed to open metadata.db: " . $e->getMessage());
        }
    }

    private function countBooksFromDatabase(string $dbPath): int
    {
        $pdo = $this->createMetadataPdo($dbPath);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM books')->fetchColumn();
        unset($pdo);

        return max(0, $count);
    }

    /**
     * @return \Generator<int, Book>
     */
    private function iterateFromDatabase(string $dbPath): \Generator
    {
        $pdo = $this->createMetadataPdo($dbPath);
        $stmt = $pdo->query("
            SELECT
                b.id,
                b.title,
                b.path,
                b.timestamp,
                b.pubdate,
                b.series_index,
                b.author_sort,
                b.uuid,
                b.last_modified,
                b.has_cover,
                GROUP_CONCAT(DISTINCT a.name) AS authors,
                GROUP_CONCAT(DISTINCT t.name) AS tags,
                s.name AS series,
                MAX(c.text) AS description,
                GROUP_CONCAT(DISTINCT p.name) AS publishers,
                GROUP_CONCAT(DISTINCT l.lang_code) AS languages,
                (
                    SELECT i.val
                    FROM identifiers i
                    WHERE i.book = b.id AND LOWER(i.type) = 'isbn'
                    LIMIT 1
                ) AS isbn
            FROM books b
            LEFT JOIN books_authors_link bal ON b.id = bal.book
            LEFT JOIN authors a ON bal.author = a.id
            LEFT JOIN books_tags_link btl ON b.id = btl.book
            LEFT JOIN tags t ON btl.tag = t.id
            LEFT JOIN books_series_link bsl ON b.id = bsl.book
            LEFT JOIN series s ON bsl.series = s.id
            LEFT JOIN books_publishers_link bpl ON b.id = bpl.book
            LEFT JOIN publishers p ON bpl.publisher = p.id
            LEFT JOIN books_languages_link bll ON b.id = bll.book
            LEFT JOIN languages l ON bll.lang_code = l.id
            LEFT JOIN comments c ON b.id = c.book
            GROUP BY b.id
            ORDER BY b.title
        ");

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $relativeBookPath = str_replace('\\', '/', (string) ($row['path'] ?? ''));
            if ($this->isExcludedLibraryPath($relativeBookPath)) {
                continue;
            }

            $bookPath = $this->rootPath . DIRECTORY_SEPARATOR . $row['path'];
            $formats = $this->findFormats($bookPath);
            foreach ($formats as $formatPath) {
                if (is_string($formatPath) && $formatPath !== '') {
                    $this->rememberDatabaseBookPath($formatPath);
                }
            }
            $primaryPath = $this->pickPrimaryFormatPath($formats);
            if ($primaryPath === '') {
                continue;
            }
            $this->rememberDatabaseBookPath($primaryPath);
            $sourceMtime = $this->resolveSourceMtime($bookPath);
            $publishedAt = $this->normalizeMetadataDate($row['pubdate'] ?? null);
            $metadata = [
                'title' => $row['title'],
                'author' => $row['authors'] ?: 'Unknown',
                'source_type' => 'db',
                'tag' => $this->normalizeCsvField($row['tags'] ?? ''),
                'series' => $row['series'] ?? '',
                'isbn' => $row['isbn'] ?? '',
                'publisher' => $this->normalizeCsvField($row['publishers'] ?? ''),
                'language' => $this->normalizeCsvField($row['languages'] ?? ''),
                'description' => $this->normalizeDescription($row['description'] ?? null),
                'pubdate' => $publishedAt,
                'published_at' => $publishedAt,
                'series_index' => $this->normalizeSeriesIndex($row['series_index'] ?? null),
                'uuid' => $this->toNullableString($row['uuid'] ?? null),
                'author_sort' => $this->toNullableString($row['author_sort'] ?? null),
                'library_timestamp' => $this->normalizeMetadataDate($row['timestamp'] ?? null),
                'library_last_modified' => $this->normalizeMetadataDate($row['last_modified'] ?? null),
                'has_cover' => (bool) $row['has_cover'],
                'source_mtime' => $sourceMtime,
            ];

            // Scan stage avoids archive extraction; extraction is deferred to save stage when truly needed.
            $coverPath = $this->findExistingCover($bookPath);

            yield new Book(
                $row['title'],
                $metadata['author'],
                $primaryPath,
                $formats,
                $metadata,
                $coverPath
            );
        }

        unset($stmt, $pdo);
    }

    private function scanFromFolders(): array
    {
        return iterator_to_array($this->iterateFromFilesystem(), false);
    }

    /**
     * @return \Generator<int, Book>
     */
    private function iterateFromFilesystem(bool $rootOnly = false): \Generator
    {
        $ebookFormats = $this->getEbookFormats();

        foreach ($this->iterateFilesystemDirectories($rootOnly) as $directory) {
            if ($this->isExcludedLibraryPath($directory)) {
                continue;
            }

            $groups = $this->collectBookGroupsInDirectory($directory, $ebookFormats);
            if ($groups === []) {
                continue;
            }

            $directoryMtime = $this->resolveSourceMtime($directory);
            $cachedBooks = $this->collectCachedFilesystemBooksForDirectory($directory, $groups, $directoryMtime);
            if ($cachedBooks !== null) {
                foreach ($cachedBooks as $cachedBook) {
                    yield $cachedBook;
                }
                continue;
            }

            foreach ($groups as $group) {
                $book = $this->buildBookFromFilesystemGroup($group, $directoryMtime);
                if ($book !== null) {
                    yield $book;
                }
            }
        }
    }

    private function countBooksFromFilesystem(): int
    {
        $ebookFormats = $this->getEbookFormats();
        $count = 0;
        foreach ($this->iterateFilesystemDirectories(false) as $directory) {
            foreach ($this->collectBookGroupsInDirectory($directory, $ebookFormats) as $_group) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return \Generator<int, string>
     */
    private function iterateFilesystemDirectories(bool $rootOnly = false): \Generator
    {
        yield $this->rootPath;

        if ($rootOnly) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->rootPath,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isDir()) {
                continue;
            }

            yield $fileInfo->getPathname();
        }
    }

    private function hasFilesystemBookOutsideDatabase(): bool
    {
        $ebookFormats = $this->getEbookFormats();
        foreach ($this->iterateFilesystemDirectories(false) as $directory) {
            foreach ($this->collectBookGroupsInDirectory($directory, $ebookFormats) as $group) {
                $formats = $group['formats'] ?? [];
                if (!is_array($formats) || $formats === []) {
                    continue;
                }

                $primaryPath = $this->pickPrimaryFormatPath($formats);
                if ($primaryPath === '') {
                    continue;
                }

                if (!$this->isBookPathFromDatabase($primaryPath)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function buildBookFromFilesystemGroup(array $group, ?int $sourceMtime = null): ?Book
    {
        $formats = $group['formats'] ?? [];
        if (!is_array($formats) || $formats === []) {
            return null;
        }

        $bookDir = (string) ($group['dir'] ?? '');
        $stem = (string) ($group['stem'] ?? '');
        if ($bookDir === '' || $stem === '') {
            return null;
        }

        $primaryPath = $this->pickPrimaryFormatPath($formats);
        if ($primaryPath === '') {
            return null;
        }
        if ($this->isBookPathFromDatabase($primaryPath)) {
            return null;
        }

        $sourceMtime ??= $this->resolveSourceMtime($bookDir);
        $cachedBook = $this->buildBookFromCache($primaryPath, $sourceMtime);
        if ($cachedBook !== null) {
            return $cachedBook;
        }

        $metadata = $this->readMetadataOpf($bookDir);
        if (isset($formats['epub'])) {
            $metadata = array_merge($this->readMetadataFromEpub((string) $formats['epub']), $metadata);
        }
        if (!isset($metadata['source_type']) || trim((string) $metadata['source_type']) === '') {
            $metadata['source_type'] = 'fs';
        }

        $defaultTitle = $this->normalizeTitle($stem);
        $title = isset($metadata['title']) && $metadata['title'] !== '' ? $metadata['title'] : $defaultTitle;
        $author = isset($metadata['author']) && $metadata['author'] !== '' ? $metadata['author'] : 'Unknown';

        // Scan stage avoids archive extraction; extraction is deferred to save stage when truly needed.
        $coverPath = $this->findExistingCover($bookDir);
        $metadata['source_mtime'] = $sourceMtime;

        return new Book(
            $title,
            $author,
            $primaryPath,
            $formats,
            $metadata,
            $coverPath
        );
    }

    /**
     * @param array<int, array{dir:string, stem:string, formats:array}> $groups
     * @return array<int, Book>|null
     */
    private function collectCachedFilesystemBooksForDirectory(string $directory, array $groups, ?int $directoryMtime): ?array
    {
        if ($directoryMtime === null || $groups === []) {
            return null;
        }

        $normalizedDirectory = $this->normalizePath($directory);
        $knownPaths = $this->previousDirectoryCache[$normalizedDirectory] ?? null;
        if (!is_array($knownPaths) || $knownPaths === []) {
            return null;
        }

        $cachedBooks = [];
        $relevantGroupCount = 0;
        foreach ($groups as $group) {
            $formats = $group['formats'] ?? [];
            if (!is_array($formats) || $formats === []) {
                continue;
            }

            $primaryPath = $this->pickPrimaryFormatPath($formats);
            if ($primaryPath === '' || $this->isBookPathFromDatabase($primaryPath)) {
                continue;
            }

            $relevantGroupCount++;
            if (!isset($knownPaths[$this->normalizePath($primaryPath)])) {
                return null;
            }

            $cachedBook = $this->buildBookFromCache($primaryPath, $directoryMtime);
            if ($cachedBook === null) {
                return null;
            }

            $cachedBooks[] = $cachedBook;
        }

        if ($relevantGroupCount === 0) {
            return [];
        }

        return count($cachedBooks) === $relevantGroupCount ? $cachedBooks : null;
    }

    private function rememberDatabaseBookPath(string $bookPath): void
    {
        $normalized = $this->normalizePath($bookPath);
        if ($normalized === '') {
            return;
        }

        $this->databaseKnownBookPaths[$normalized] = true;
    }

    private function isBookPathFromDatabase(string $bookPath): bool
    {
        if ($this->databaseKnownBookPaths === []) {
            return false;
        }

        $normalized = $this->normalizePath($bookPath);
        if ($normalized === '') {
            return false;
        }

        return isset($this->databaseKnownBookPaths[$normalized]);
    }

    private function buildBookFromCache(string $primaryPath, ?int $sourceMtime): ?Book
    {
        $normalizedPath = $this->normalizePath($primaryPath);
        $snapshot = $this->previousBookCache[$normalizedPath] ?? null;
        if (!is_array($snapshot)) {
            return null;
        }

        $cachedSourceMtime = isset($snapshot['source_mtime']) && is_numeric($snapshot['source_mtime'])
            ? (int) $snapshot['source_mtime']
            : null;

        if ($sourceMtime === null || $cachedSourceMtime === null || $sourceMtime !== $cachedSourceMtime) {
            return null;
        }

        $formats = [];
        if (isset($snapshot['formats']) && is_array($snapshot['formats'])) {
            foreach ($snapshot['formats'] as $format => $formatPath) {
                if (!is_string($formatPath)) {
                    continue;
                }
                $normalizedFormatPath = $this->normalizePath($formatPath);
                if (is_file($normalizedFormatPath)) {
                    $formats[$format] = $normalizedFormatPath;
                }
            }
        }

        if ($formats === []) {
            return null;
        }

        $metadata = isset($snapshot['metadata']) && is_array($snapshot['metadata'])
            ? $snapshot['metadata']
            : [];
        $metadata['source_mtime'] = $sourceMtime;

        $title = trim((string) ($snapshot['title'] ?? ''));
        if ($title === '') {
            $title = $this->normalizeTitle(pathinfo($primaryPath, PATHINFO_FILENAME));
        }

        $author = trim((string) ($snapshot['author'] ?? ''));
        if ($author === '') {
            $author = 'Unknown';
        }

        $coverPath = null;
        if (isset($snapshot['cover_path']) && is_string($snapshot['cover_path']) && trim($snapshot['cover_path']) !== '') {
            $normalizedCoverPath = $this->normalizePath($snapshot['cover_path']);
            if (is_file($normalizedCoverPath)) {
                $coverPath = $normalizedCoverPath;
            }
        }

        return new Book(
            $title,
            $author,
            $primaryPath,
            $formats,
            $metadata,
            $coverPath
        );
    }

    private function resolveSourceMtime(string $bookPath): ?int
    {
        $directory = is_dir($bookPath) ? $bookPath : dirname($bookPath);
        $mtime = @filemtime($directory);

        return $mtime === false ? null : (int) $mtime;
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function collectBookGroupsInDirectory(string $directory, array $ebookFormats): array
    {
        if ($this->isExcludedLibraryPath($directory)) {
            return [];
        }

        $files = @scandir($directory, SCANDIR_SORT_ASCENDING);
        if ($files === false) {
            return [];
        }

        $groups = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path)) {
                continue;
            }

            $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($extension, $ebookFormats, true)) {
                continue;
            }

            $stem = (string) pathinfo($file, PATHINFO_FILENAME);
            $groupKey = strtolower($stem);
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'dir' => $directory,
                    'stem' => $stem,
                    'formats' => [],
                ];
            }

            $groups[$groupKey]['formats'][$extension] = $path;
        }

        return array_values($groups);
    }

    private function getEbookFormats(): array
    {
        return ['epub', 'mobi', 'azw3', 'pdf', 'cbz', 'azw', 'txt', 'html', 'rtf'];
    }

    private function isExcludedLibraryPath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return preg_match('~(?:^|/)\.caltrash(?:/|$)~', $normalized) === 1;
    }

    private function ensureCover(string $bookPath, bool $hasCover, array $formats): ?string
    {
        if (is_file($bookPath)) {
            $bookPath = dirname($bookPath);
        }

        $existingCover = $this->findExistingCover($bookPath);
        if ($existingCover !== null) {
            return $existingCover;
        }

        // If has_cover is true in DB or epub exists, try to extract
        if ($hasCover || isset($formats['epub'])) {
            $extractedCover = $this->extractCoverFromFormats($formats);
            if ($extractedCover) {
                return $extractedCover;
            }
        }

        return null;
    }

    private function extractCoverFromFormats(array $formats): ?string
    {
        // Prefer epub for cover extraction
        if (isset($formats['epub'])) {
            return $this->extractCoverFromEpub($formats['epub']);
        }

        if (isset($formats['cbz'])) {
            return $this->extractCoverFromCbz($formats['cbz']);
        }

        // Could add support for other formats like mobi, but epub is easiest
        return null;
    }

    private function extractCoverFromEpub(string $epubPath): ?string
    {
        if (!extension_loaded('zip')) {
            return null; // Cannot extract cover without zip extension
        }

        if (!is_dir($this->thumbDir)) {
            mkdir($this->thumbDir, 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($epubPath) !== true) {
            return null;
        }

        // Read container.xml
        $containerXml = $zip->getFromName('META-INF/container.xml');
        if (!$containerXml) {
            $zip->close();
            return null;
        }

        $container = simplexml_load_string($containerXml);
        if (!$container) {
            $zip->close();
            return null;
        }

        $rootfile = $container->rootfiles->rootfile;
        $contentOpfPath = (string) $rootfile['full-path'];

        // Read content.opf
        $contentOpf = $zip->getFromName($contentOpfPath);
        if (!$contentOpf) {
            $zip->close();
            return null;
        }

        $opf = simplexml_load_string($contentOpf);
        if (!$opf) {
            $zip->close();
            return null;
        }

        $manifest = $opf->manifest;

        // Find cover image
        $coverHref = null;
        foreach ($manifest->item as $item) {
            $properties = (string) $item['properties'];
            if (strpos($properties, 'cover-image') !== false) {
                $coverHref = (string) $item['href'];
                break;
            }
        }

        if (!$coverHref) {
            // Fallback: look for item with id containing 'cover'
            foreach ($manifest->item as $item) {
                $id = (string) $item['id'];
                $mediaType = (string) $item['media-type'];
                if (stripos($id, 'cover') !== false && strpos($mediaType, 'image/') === 0) {
                    $coverHref = (string) $item['href'];
                    break;
                }
            }
        }

        if (!$coverHref) {
            $zip->close();
            return null;
        }

        // Resolve relative path
        $baseDir = dirname($contentOpfPath);
        if ($baseDir !== '.') {
            $coverHref = $baseDir . '/' . $coverHref;
        }

        // Extract cover
        $coverData = $zip->getFromName($coverHref);
        if (!$coverData) {
            $zip->close();
            return null;
        }

        $zip->close();

        // Determine extension
        $extension = pathinfo($coverHref, PATHINFO_EXTENSION);
        if (!$extension) {
            $extension = 'jpg'; // default
        }

        $thumbPath = $this->buildThumbPath($epubPath, $extension);
        if (file_put_contents($thumbPath, $coverData) === false) {
            return null;
        }

        return $thumbPath;
    }

    private function extractCoverFromCbz(string $cbzPath): ?string
    {
        if (!extension_loaded('zip')) {
            return null;
        }

        if (!is_dir($this->thumbDir)) {
            mkdir($this->thumbDir, 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($cbzPath) !== true) {
            return null;
        }

        $firstImageName = null;
        $firstImageIndex = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = (string) $zip->getNameIndex($i);
            if ($entryName === '' || str_ends_with($entryName, '/')) {
                continue;
            }

            $extension = strtolower((string) pathinfo($entryName, PATHINFO_EXTENSION));
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                continue;
            }

            $firstImageName = $entryName;
            $firstImageIndex = $i;
            break;
        }

        if ($firstImageName === null || $firstImageIndex === null) {
            $zip->close();
            return null;
        }

        $coverData = $zip->getFromIndex($firstImageIndex);
        $zip->close();
        if ($coverData === false || $coverData === '') {
            return null;
        }

        $extension = strtolower((string) pathinfo($firstImageName, PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = 'jpg';
        }

        $thumbPath = $this->buildThumbPath($cbzPath, $extension);
        if (file_put_contents($thumbPath, $coverData) === false) {
            return null;
        }

        return $thumbPath;
    }

    private function buildThumbPath(string $sourcePath, string $extension): string
    {
        $sourceName = pathinfo($sourcePath, PATHINFO_FILENAME);
        $normalizedName = preg_replace('/[^\pL\pN._-]+/u', '_', $sourceName);
        if ($normalizedName === null) {
            $normalizedName = '';
        }

        $normalizedName = trim($normalizedName, '._-');
        if ($normalizedName === '') {
            $normalizedName = 'book';
        }

        if (function_exists('mb_substr')) {
            $normalizedName = mb_substr($normalizedName, 0, 80, 'UTF-8');
        } else {
            $normalizedName = substr($normalizedName, 0, 80);
        }

        $safeExtension = strtolower(trim($extension));
        if ($safeExtension === '') {
            $safeExtension = 'jpg';
        }

        $hash = substr(sha1($sourcePath), 0, 12);

        return $this->thumbDir
            . DIRECTORY_SEPARATOR
            . $normalizedName
            . '_'
            . $hash
            . '_cover.'
            . $safeExtension;
    }

    private function containsTitleFolders(string $path): bool
    {
        $entries = scandir($path);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child) && $this->containsEbookFiles($child)) {
                return true;
            }
        }

        return false;
    }

    private function containsEbookFiles(string $path): bool
    {
        $formats = $this->findFormats($path);
        return !empty($formats);
    }

    private function normalizeTitle(string $title): string
    {
        return trim(str_replace(['_', '-'], ' ', $title));
    }

    private function normalizeDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $normalized = trim($description);
        if ($normalized === '') {
            return null;
        }

        $normalized = preg_replace('/<\s*br\s*\/?>/i', "\n", $normalized);
        $normalized = preg_replace('/<\s*\/p\s*>/i', "\n\n", $normalized);
        $normalized = preg_replace('/<\s*li\s*>/i', "• ", $normalized);
        $normalized = preg_replace('/<\s*\/li\s*>/i', "\n", $normalized);

        if ($normalized === null) {
            return null;
        }

        $normalized = html_entity_decode(strip_tags($normalized), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace("/\r\n?/", "\n", $normalized);
        $normalized = preg_replace("/[ \t]+\n/", "\n", $normalized);
        $normalized = preg_replace("/\n{3,}/", "\n\n", $normalized);

        if ($normalized === null) {
            return null;
        }

        $normalized = trim($normalized);

        return $normalized === '' ? null : $normalized;
    }

    private function findFormats(string $titlePath): array
    {
        $ebookFormats = ['epub', 'mobi', 'azw3', 'pdf', 'cbz', 'azw', 'txt', 'html', 'rtf'];
        $files = scandir($titlePath, SCANDIR_SORT_ASCENDING);
        if ($files === false) {
            return [];
        }

        $formats = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $titlePath . DIRECTORY_SEPARATOR . $file;
            if (!is_file($filePath)) {
                continue;
            }

            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (in_array($extension, $ebookFormats, true)) {
                $formats[$extension] = $filePath;
            }
        }

        return $formats;
    }

    private function readMetadataOpf(string $titlePath): array
    {
        $files = scandir($titlePath, SCANDIR_SORT_ASCENDING);
        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $titlePath . DIRECTORY_SEPARATOR . $file;
            if (!is_file($filePath)) {
                continue;
            }

            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if ($extension !== 'opf') {
                continue;
            }

            try {
                $xml = @simplexml_load_file($filePath);
                if ($xml === false) {
                    continue;
                }

                $ns = $xml->getNamespaces(true);
                $metadataNode = $xml->metadata;
                if ($metadataNode === null) {
                    return [];
                }

                $title = $this->readElement($metadataNode, 'title', $ns);
                $creator = $this->readElement($metadataNode, 'creator', $ns);
                $identifier = $this->readElement($metadataNode, 'identifier', $ns);
                $publisher = $this->readElement($metadataNode, 'publisher', $ns);
                $language = $this->readElement($metadataNode, 'language', $ns);
                $publishedAt = $this->readElement($metadataNode, 'date', $ns);
                $description = $this->readElement($metadataNode, 'description', $ns)
                    ?? $this->readMetaByName($metadataNode, 'calibre:comments', $ns);
                $subjects = $this->readElements($metadataNode, 'subject', $ns);
                $series = $this->readMetaByName($metadataNode, 'calibre:series', $ns);
                $seriesIndex = $this->readMetaByName($metadataNode, 'calibre:series_index', $ns);
                $isbn = $this->extractIsbn($identifier, $metadataNode, $ns);
                $uuid = $this->extractUuid($identifier, $metadataNode, $ns);
                $authorSort = $this->readMetaByName($metadataNode, 'calibre:author_sort', $ns);
                $libraryTimestamp = $this->readMetaByName($metadataNode, 'calibre:timestamp', $ns);

                $normalizedPublishedAt = $this->normalizeMetadataDate($publishedAt);

                return array_filter([
                    'title' => $title,
                    'author' => $creator,
                    'source_type' => 'fs',
                    'identifier' => $identifier,
                    'isbn' => $isbn,
                    'tag' => implode(', ', $subjects),
                    'series' => $series,
                    'publisher' => $publisher,
                    'language' => $language,
                    'description' => $this->normalizeDescription($description),
                    'pubdate' => $normalizedPublishedAt,
                    'published_at' => $normalizedPublishedAt,
                    'series_index' => $this->normalizeSeriesIndex($seriesIndex),
                    'uuid' => $uuid,
                    'author_sort' => $authorSort,
                    'library_timestamp' => $this->normalizeMetadataDate($libraryTimestamp),
                ], static fn($value) => $value !== null && $value !== '');
            } catch (\Throwable $e) {
                continue;
            }
        }

        return [];
    }

    private function readMetadataFromEpub(string $epubPath): array
    {
        if (!extension_loaded('zip')) {
            return [];
        }

        $zip = new \ZipArchive();
        if ($zip->open($epubPath) !== true) {
            return [];
        }

        $containerXml = $zip->getFromName('META-INF/container.xml');
        if (!$containerXml) {
            $zip->close();
            return [];
        }

        $container = simplexml_load_string($containerXml);
        if (!$container || !isset($container->rootfiles->rootfile)) {
            $zip->close();
            return [];
        }

        $rootfile = $container->rootfiles->rootfile;
        $contentOpfPath = (string) $rootfile['full-path'];
        if ($contentOpfPath === '') {
            $zip->close();
            return [];
        }

        $contentOpf = $zip->getFromName($contentOpfPath);
        if (!$contentOpf) {
            $zip->close();
            return [];
        }

        $opf = simplexml_load_string($contentOpf);
        if (!$opf) {
            $zip->close();
            return [];
        }

        $zip->close();

        $ns = $opf->getNamespaces(true);
        $metadataNode = $opf->metadata;
        if ($metadataNode === null) {
            return [];
        }

        $title = $this->readElement($metadataNode, 'title', $ns);
        $creator = $this->readElement($metadataNode, 'creator', $ns);
        $identifier = $this->readElement($metadataNode, 'identifier', $ns);
        $publisher = $this->readElement($metadataNode, 'publisher', $ns);
        $language = $this->readElement($metadataNode, 'language', $ns);
        $publishedAt = $this->readElement($metadataNode, 'date', $ns);
        $description = $this->readElement($metadataNode, 'description', $ns)
            ?? $this->readMetaByName($metadataNode, 'calibre:comments', $ns);
        $subjects = $this->readElements($metadataNode, 'subject', $ns);
        $series = $this->readMetaByName($metadataNode, 'calibre:series', $ns);
        $seriesIndex = $this->readMetaByName($metadataNode, 'calibre:series_index', $ns);
        $isbn = $this->extractIsbn($identifier, $metadataNode, $ns);
        $uuid = $this->extractUuid($identifier, $metadataNode, $ns);
        $authorSort = $this->readMetaByName($metadataNode, 'calibre:author_sort', $ns);
        $libraryTimestamp = $this->readMetaByName($metadataNode, 'calibre:timestamp', $ns);

        $normalizedPublishedAt = $this->normalizeMetadataDate($publishedAt);

        return array_filter([
            'title' => $title,
            'author' => $creator,
            'source_type' => 'fs',
            'identifier' => $identifier,
            'isbn' => $isbn,
            'tag' => implode(', ', $subjects),
            'series' => $series,
            'publisher' => $publisher,
            'language' => $language,
            'description' => $this->normalizeDescription($description),
            'pubdate' => $normalizedPublishedAt,
            'published_at' => $normalizedPublishedAt,
            'series_index' => $this->normalizeSeriesIndex($seriesIndex),
            'uuid' => $uuid,
            'author_sort' => $authorSort,
            'library_timestamp' => $this->normalizeMetadataDate($libraryTimestamp),
        ], static fn($value) => $value !== null && $value !== '');
    }

    private function readElement(\SimpleXMLElement $node, string $name, array $namespaces): ?string
    {
        if (isset($node->{$name})) {
            return trim((string) $node->{$name});
        }

        foreach ($namespaces as $ns) {
            $children = $node->children($ns);
            if (!$children) {
                continue;
            }

            $elements = $children->{$name};
            if ($elements instanceof \SimpleXMLElement && $elements->count() > 0) {
                return trim((string) $elements[0]);
            }
        }

        return null;
    }

    private function findExistingCover(string $bookPath): ?string
    {
        if (is_file($bookPath)) {
            $bookPath = dirname($bookPath);
        }

        $coverFiles = ['cover.jpg', 'cover.jpeg', 'cover.png'];
        foreach ($coverFiles as $coverFile) {
            $coverPath = $bookPath . DIRECTORY_SEPARATOR . $coverFile;
            if (file_exists($coverPath)) {
                return $coverPath;
            }
        }

        return null;
    }

    private function pickPrimaryFormatPath(array $formats): string
    {
        $priority = ['epub', 'pdf', 'cbz', 'azw3', 'mobi', 'azw', 'txt', 'html', 'rtf'];
        foreach ($priority as $format) {
            if (isset($formats[$format]) && is_string($formats[$format])) {
                return $formats[$format];
            }
        }

        $first = reset($formats);
        return is_string($first) ? $first : '';
    }

    private function readElements(\SimpleXMLElement $node, string $name, array $namespaces): array
    {
        $results = [];

        if (isset($node->{$name})) {
            foreach ($node->{$name} as $element) {
                $value = trim((string) $element);
                if ($value !== '') {
                    $results[] = $value;
                }
            }
        }

        foreach ($namespaces as $ns) {
            $children = $node->children($ns);
            if (!$children) {
                continue;
            }

            $elements = $children->{$name};
            if (!($elements instanceof \SimpleXMLElement)) {
                continue;
            }

            foreach ($elements as $element) {
                $value = trim((string) $element);
                if ($value !== '') {
                    $results[] = $value;
                }
            }
        }

        return array_values(array_unique($results));
    }

    private function readMetaByName(\SimpleXMLElement $metadataNode, string $metaName, array $namespaces): ?string
    {
        $target = strtolower($metaName);
        foreach ($this->readMetaElements($metadataNode, $namespaces) as $meta) {
            $name = strtolower((string) $meta['name']);
            if ($name === $target) {
                $content = trim((string) $meta['content']);
                if ($content !== '') {
                    return $content;
                }
            }
        }

        return null;
    }

    private function extractIsbn(?string $identifier, \SimpleXMLElement $metadataNode, array $namespaces): ?string
    {
        $candidates = [];

        if ($identifier !== null && trim($identifier) !== '') {
            $candidates[] = $identifier;
        }

        $identifierList = $this->readElements($metadataNode, 'identifier', $namespaces);
        foreach ($identifierList as $idValue) {
            $candidates[] = $idValue;
        }

        foreach ($this->readMetaElements($metadataNode, $namespaces) as $meta) {
            $name = strtolower((string) $meta['name']);
            if ($name === 'calibre:isbn') {
                $content = trim((string) $meta['content']);
                if ($content !== '') {
                    $candidates[] = $content;
                }
            }
        }

        foreach ($candidates as $candidate) {
            $normalized = preg_replace('/[^0-9Xx]/', '', $candidate);
            if ($normalized === null) {
                continue;
            }
            if (preg_match('/^(?:\d{9}[\dXx]|\d{13})$/', $normalized)) {
                return strtoupper($normalized);
            }
        }

        return null;
    }

    /**
     * @return \SimpleXMLElement[]
     */
    private function readMetaElements(\SimpleXMLElement $metadataNode, array $namespaces): array
    {
        $metaNodes = [];

        if (isset($metadataNode->meta)) {
            foreach ($metadataNode->meta as $meta) {
                if ($meta instanceof \SimpleXMLElement) {
                    $metaNodes[] = $meta;
                }
            }
        }

        foreach ($namespaces as $ns) {
            $children = $metadataNode->children($ns);
            if (!$children || !isset($children->meta)) {
                continue;
            }

            foreach ($children->meta as $meta) {
                if ($meta instanceof \SimpleXMLElement) {
                    $metaNodes[] = $meta;
                }
            }
        }

        return $metaNodes;
    }

    private function normalizeMetadataDate(?string $value): ?string
    {
        $normalized = $this->toNullableString($value);
        if ($normalized === null) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($normalized))->format(DATE_ATOM);
        } catch (\Throwable) {
            return $normalized;
        }
    }

    private function normalizeSeriesIndex($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function toNullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function extractUuid(?string $identifier, \SimpleXMLElement $metadataNode, array $namespaces): ?string
    {
        $candidates = [];
        if ($identifier !== null && trim($identifier) !== '') {
            $candidates[] = $identifier;
        }

        foreach ($this->readElements($metadataNode, 'identifier', $namespaces) as $idValue) {
            $candidates[] = $idValue;
        }

        foreach ($candidates as $candidate) {
            if (preg_match(
                '/(?:urn:uuid:)?([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})/',
                $candidate,
                $matches
            )) {
                return strtolower($matches[1]);
            }
        }

        return null;
    }

    private function normalizeCsvField(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $parts = array_map('trim', explode(',', $value));
        $parts = array_filter($parts, static fn($part) => $part !== '');
        $parts = array_values(array_unique($parts));

        return implode(', ', $parts);
    }
}
