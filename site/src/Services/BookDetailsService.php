<?php

namespace Calibre\Services;

use Calibre\Http\HttpException;
use Calibre\LibraryIndex;
use Calibre\ScanService;
use Calibre\Support\Lang;

final class BookDetailsService
{
    private ScanService $scanService;
    private OpdsAssetService $opdsAssetService;
    private ReaderAccessService $readerAccessService;

    public function __construct(
        string $appRoot,
        ?ScanService $scanService = null,
        ?OpdsAssetService $opdsAssetService = null,
        ?ReaderAccessService $readerAccessService = null
    )
    {
        $this->scanService = $scanService ?? new ScanService($appRoot);
        $this->opdsAssetService = $opdsAssetService ?? new OpdsAssetService($appRoot, $this->scanService);
        $this->readerAccessService = $readerAccessService ?? new ReaderAccessService($appRoot);
    }

    public function getDetails(int $bookId): array
    {
        $index = new LibraryIndex($this->scanService->getSqlitePath());
        $book = $index->getBookById($bookId);

        if ($book === null) {
            throw new HttpException(404, Lang::t('error.book_not_found'));
        }

        $metadata = $this->decodeMetadata($book['metadata_json'] ?? null);
        $description = $this->extractDescription($metadata);

        if ($description === null) {
            $description = $this->resolveDescriptionFromSource($book, $this->scanService->getLibraryPath());
        }

        $cover = $this->opdsAssetService->resolveCoverForBook($book);
        $author = trim((string) ($book['author'] ?? ''));
        $tag = trim((string) ($book['tag'] ?? ($metadata['tag'] ?? '')));
        $series = trim((string) ($book['series'] ?? ($metadata['series'] ?? '')));
        $publisher = trim((string) ($book['publisher'] ?? ($metadata['publisher'] ?? '')));
        $language = trim((string) ($book['language'] ?? ($metadata['language'] ?? '')));

        return [
            'id' => (int) ($book['id'] ?? 0),
            'title' => trim((string) ($book['title'] ?? '')),
            'author' => $author,
            'authors' => $this->splitCsvField($author),
            'tag' => $tag,
            'tags' => $this->splitCsvField($tag),
            'series' => $series,
            'isbn' => trim((string) ($book['isbn'] ?? ($metadata['isbn'] ?? ''))),
            'publisher' => $publisher,
            'language' => $language,
            'description' => $description ?? '',
            'cover_url' => $cover !== null ? 'opds.php?feed=cover&id=' . (int) ($book['id'] ?? 0) : null,
            'read_url' => $this->readerAccessService->hasReadableBook($book)
                ? 'reader.php?id=' . (int) ($book['id'] ?? 0)
                : null,
            'send_url' => 'send.php?id=' . (int) ($book['id'] ?? 0),
        ];
    }

    private function splitCsvField(string $value): array
    {
        $parts = preg_split('/\s*(?:,|，)\s*/u', trim($value)) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $parts), static function (string $part): bool {
            return $part !== '';
        })));
    }

    private function decodeMetadata($metadataJson): array
    {
        if (!is_string($metadataJson) || trim($metadataJson) === '') {
            return [];
        }

        $decoded = json_decode($metadataJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function extractDescription(array $metadata): ?string
    {
        foreach (['description', 'comments', 'summary', 'synopsis'] as $key) {
            if (isset($metadata[$key]) && is_string($metadata[$key])) {
                $description = $this->normalizeDescription($metadata[$key]);
                if ($description !== null) {
                    return $description;
                }
            }
        }

        return null;
    }

    private function resolveDescriptionFromSource(array $book, string $libraryRoot): ?string
    {
        $bookDirectory = $this->resolveBookDirectory($book, $libraryRoot);

        if ($bookDirectory !== null) {
            try {
                $description = $this->readDescriptionFromMetadataDb($bookDirectory, $libraryRoot);
                if ($description !== null) {
                    return $description;
                }
            } catch (\Throwable $e) {
            }

            try {
                $description = $this->readDescriptionFromOpf($bookDirectory);
                if ($description !== null) {
                    return $description;
                }
            } catch (\Throwable $e) {
            }
        }

        $epubPath = $this->resolveEpubPath($book, $libraryRoot);
        if ($epubPath !== null) {
            try {
                return $this->readDescriptionFromEpub($epubPath);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    private function resolveBookDirectory(array $book, string $libraryRoot): ?string
    {
        $path = $book['path'] ?? null;

        if (is_string($path) && trim($path) !== '') {
            $resolvedDirectory = $this->resolveCandidatePath($path, $libraryRoot, true);
            if ($resolvedDirectory !== null) {
                return $resolvedDirectory;
            }

            $resolvedFile = $this->resolveCandidatePath($path, $libraryRoot, false);
            if ($resolvedFile !== null) {
                return dirname($resolvedFile);
            }
        }

        foreach ($this->decodeFormats($book['formats_json'] ?? null) as $candidatePath) {
            $resolvedFile = $this->resolveCandidatePath($candidatePath, $libraryRoot, false);
            if ($resolvedFile !== null) {
                return dirname($resolvedFile);
            }
        }

        return null;
    }

    private function resolveEpubPath(array $book, string $libraryRoot): ?string
    {
        foreach ($this->decodeFormats($book['formats_json'] ?? null) as $candidatePath) {
            if (strtolower((string) pathinfo($candidatePath, PATHINFO_EXTENSION)) !== 'epub') {
                continue;
            }

            $resolved = $this->resolveCandidatePath($candidatePath, $libraryRoot, false);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        $path = $book['path'] ?? null;
        if (is_string($path) && strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'epub') {
            return $this->resolveCandidatePath($path, $libraryRoot, false);
        }

        return null;
    }

    private function decodeFormats($formatsJson): array
    {
        if (!is_string($formatsJson) || trim($formatsJson) === '') {
            return [];
        }

        $decoded = json_decode($formatsJson, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, static function ($path): bool {
            return is_string($path) && trim($path) !== '';
        }));
    }

    private function resolveCandidatePath(string $candidate, string $libraryRoot, bool $expectDirectory): ?string
    {
        $trimmedCandidate = trim($candidate);
        if ($trimmedCandidate === '') {
            return null;
        }

        $resolvedPath = $this->resolvePathAgainstRoot($trimmedCandidate, $libraryRoot, $expectDirectory);
        if ($resolvedPath !== null) {
            return $resolvedPath;
        }

        $segments = $this->pathSegments($trimmedCandidate);
        $segmentCount = count($segments);

        for ($offset = 0; $offset < $segmentCount; $offset++) {
            $suffixSegments = array_slice($segments, $offset);
            if ($suffixSegments === []) {
                continue;
            }

            $suffixPath = rtrim($libraryRoot, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . implode(DIRECTORY_SEPARATOR, $suffixSegments);

            $resolvedPath = $this->resolveExistingPath($suffixPath, $libraryRoot, $expectDirectory);
            if ($resolvedPath !== null) {
                return $resolvedPath;
            }
        }

        return null;
    }

    private function resolvePathAgainstRoot(string $candidate, string $libraryRoot, bool $expectDirectory): ?string
    {
        if (ScanService::isAbsolutePath($candidate)) {
            return $this->resolveExistingPath($candidate, $libraryRoot, $expectDirectory);
        }

        return $this->resolveExistingPath(
            ScanService::resolvePath($libraryRoot, $candidate),
            $libraryRoot,
            $expectDirectory
        );
    }

    private function resolveExistingPath(string $candidatePath, string $libraryRoot, bool $expectDirectory): ?string
    {
        if ($expectDirectory) {
            if (is_dir($candidatePath) && $this->isWithinRoot($candidatePath, $libraryRoot)) {
                return $candidatePath;
            }

            return null;
        }

        if (is_file($candidatePath) && $this->isWithinRoot($candidatePath, $libraryRoot)) {
            return $candidatePath;
        }

        return null;
    }

    private function pathSegments(string $path): array
    {
        $normalized = str_replace('\\', '/', trim($path));
        $segments = explode('/', $normalized);

        return array_values(array_filter($segments, static function (string $segment): bool {
            return $segment !== '' && $segment !== '.' && $segment !== '..';
        }));
    }

    private function isWithinRoot(string $targetPath, string $rootPath): bool
    {
        $targetRealPath = realpath($targetPath);
        $rootRealPath = realpath($rootPath);

        if ($targetRealPath === false || $rootRealPath === false) {
            return false;
        }

        return $targetRealPath === $rootRealPath
            || strpos($targetRealPath, $rootRealPath . DIRECTORY_SEPARATOR) === 0;
    }

    private function readDescriptionFromMetadataDb(string $bookDirectory, string $libraryRoot): ?string
    {
        $metadataDbPath = rtrim($libraryRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'metadata.db';

        if (!is_file($metadataDbPath) || !extension_loaded('pdo_sqlite')) {
            return null;
        }

        $libraryRealPath = realpath($libraryRoot);
        $bookRealPath = realpath($bookDirectory);

        if ($libraryRealPath === false || $bookRealPath === false) {
            return null;
        }

        if (strpos($bookRealPath, $libraryRealPath . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }

        $relativePath = str_replace('\\', '/', substr($bookRealPath, strlen($libraryRealPath) + 1));
        if ($relativePath === '') {
            return null;
        }

        $pdo = new \PDO('sqlite:' . $metadataDbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare(
            'SELECT c.text
             FROM books b
             INNER JOIN comments c ON c.book = b.id
             WHERE b.path = :path
             LIMIT 1'
        );
        $stmt->execute([':path' => $relativePath]);

        $description = $stmt->fetchColumn();

        return is_string($description) ? $this->normalizeDescription($description) : null;
    }

    private function readDescriptionFromOpf(string $bookDirectory): ?string
    {
        $files = scandir($bookDirectory, SCANDIR_SORT_ASCENDING);
        if ($files === false) {
            return null;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $bookDirectory . DIRECTORY_SEPARATOR . $file;
            if (!is_file($filePath) || strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION)) !== 'opf') {
                continue;
            }

            try {
                $xml = @simplexml_load_file($filePath);
                if ($xml === false || $xml->metadata === null) {
                    continue;
                }

                return $this->readDescriptionFromMetadataNode($xml->metadata, $xml->getNamespaces(true));
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }

    private function readDescriptionFromEpub(string $epubPath): ?string
    {
        if (!extension_loaded('zip')) {
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($epubPath) !== true) {
            return null;
        }

        $containerXml = $zip->getFromName('META-INF/container.xml');
        if (!$containerXml) {
            $zip->close();
            return null;
        }

        $container = simplexml_load_string($containerXml);
        if (!$container || !isset($container->rootfiles->rootfile)) {
            $zip->close();
            return null;
        }

        $contentOpfPath = (string) $container->rootfiles->rootfile['full-path'];
        if ($contentOpfPath === '') {
            $zip->close();
            return null;
        }

        $contentOpf = $zip->getFromName($contentOpfPath);
        $zip->close();

        if (!$contentOpf) {
            return null;
        }

        $opf = simplexml_load_string($contentOpf);
        if (!$opf || $opf->metadata === null) {
            return null;
        }

        return $this->readDescriptionFromMetadataNode($opf->metadata, $opf->getNamespaces(true));
    }

    private function readDescriptionFromMetadataNode(\SimpleXMLElement $metadataNode, array $namespaces): ?string
    {
        $description = $this->readElement($metadataNode, 'description', $namespaces);

        if ($description === null) {
            $description = $this->readMetaByName($metadataNode, 'calibre:comments', $namespaces);
        }

        return $this->normalizeDescription($description);
    }

    private function readElement(\SimpleXMLElement $node, string $name, array $namespaces): ?string
    {
        if (isset($node->{$name})) {
            $value = trim((string) $node->{$name});
            if ($value !== '') {
                return $value;
            }
        }

        foreach ($namespaces as $namespace) {
            $children = $node->children($namespace);
            if (!$children) {
                continue;
            }

            $elements = $children->{$name};
            if ($elements instanceof \SimpleXMLElement && $elements->count() > 0) {
                $value = trim((string) $elements[0]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function readMetaByName(\SimpleXMLElement $metadataNode, string $metaName, array $namespaces): ?string
    {
        $targetName = strtolower($metaName);

        if (isset($metadataNode->meta)) {
            foreach ($metadataNode->meta as $meta) {
                $name = strtolower(trim((string) $meta['name']));
                $content = trim((string) $meta['content']);

                if ($name === $targetName && $content !== '') {
                    return $content;
                }
            }
        }

        foreach ($namespaces as $namespace) {
            $children = $metadataNode->children($namespace);
            if (!$children || !isset($children->meta)) {
                continue;
            }

            foreach ($children->meta as $meta) {
                $name = strtolower(trim((string) $meta['name']));
                $content = trim((string) $meta['content']);

                if ($name === $targetName && $content !== '') {
                    return $content;
                }
            }
        }

        return null;
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
}
