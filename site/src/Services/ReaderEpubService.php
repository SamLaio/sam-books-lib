<?php

namespace Calibre\Services;

use Calibre\Http\HttpException;
use Calibre\Support\Lang;

final class ReaderEpubService
{
    private string $appRoot;

    /** @var array<string, array<string, mixed>> */
    private static array $inspectionCache = [];

    public function __construct(string $appRoot)
    {
        $this->appRoot = rtrim($appRoot, DIRECTORY_SEPARATOR);
    }

    public function buildManifest(int $bookId, array $book, string $epubPath): array
    {
        $inspection = $this->inspectEpub($epubPath);

        return [
            'book_id' => $bookId,
            'title' => trim((string) ($book['title'] ?? $inspection['book_title'] ?? '')),
            'format' => 'epub',
            'reading_mode' => 'epub-section',
            'toc' => $inspection['toc'],
            'sections' => $inspection['sections'],
            'initial_section' => $inspection['initial_section'],
            'initial_fragment' => $inspection['initial_fragment'],
        ];
    }

    public function renderSectionDocument(int $bookId, string $epubPath, string $sectionId, string $theme): string
    {
        $inspection = $this->inspectEpub($epubPath);
        $section = $this->resolveSection($inspection, $sectionId);

        $zip = new \ZipArchive();
        if ($zip->open($epubPath) !== true) {
            throw new HttpException(500, Lang::t('error.reader_epub_open_failed'));
        }

        try {
            $rawContent = $zip->getFromName($section['zip_path']);
            if (!is_string($rawContent) || $rawContent === '') {
                throw new HttpException(404, Lang::t('error.reader_section_not_found'));
            }

            return $this->rewriteSectionDocument($rawContent, $bookId, $inspection, $section, $theme);
        } finally {
            $zip->close();
        }
    }

    public function resolveAssetBinary(int $bookId, string $epubPath, string $assetPath): array
    {
        $inspection = $this->inspectEpub($epubPath);
        $normalizedAssetPath = $this->normalizeZipPath($assetPath);
        if ($normalizedAssetPath === '') {
            throw new HttpException(404, Lang::t('error.reader_asset_not_found'));
        }

        $zip = new \ZipArchive();
        if ($zip->open($epubPath) !== true) {
            throw new HttpException(500, Lang::t('error.reader_epub_open_failed'));
        }

        try {
            $zipPath = $this->resolveExistingZipPath($zip, $normalizedAssetPath);
            $content = $zipPath !== null ? $zip->getFromName($zipPath) : false;
            if (!is_string($content)) {
                throw new HttpException(404, Lang::t('error.reader_asset_not_found'));
            }

            $resolvedManifestPath = $this->normalizeZipPath($zipPath ?? $normalizedAssetPath);
            $mimeType = (string) ($inspection['manifest_by_path'][$resolvedManifestPath]['media_type']
                ?? $inspection['manifest_by_path'][$normalizedAssetPath]['media_type']
                ?? '');
            if ($mimeType === '') {
                $mimeType = $this->detectMimeTypeFromPath($resolvedManifestPath);
            }

            if (str_starts_with(strtolower($mimeType), 'text/css')) {
                $content = $this->sanitizeStylesheetContent($content, $inspection, $resolvedManifestPath, $bookId);
            }

            return [
                'content' => $content,
                'mime_type' => $mimeType,
            ];
        } finally {
            $zip->close();
        }
    }

    private function inspectEpub(string $epubPath): array
    {
        $cacheKey = $epubPath . '|' . (string) @filemtime($epubPath) . '|' . (string) @filesize($epubPath);
        if (isset(self::$inspectionCache[$cacheKey])) {
            return self::$inspectionCache[$cacheKey];
        }

        $zip = new \ZipArchive();
        if ($zip->open($epubPath) !== true) {
            throw new HttpException(500, Lang::t('error.reader_epub_open_failed'));
        }

        try {
            $containerXml = $zip->getFromName('META-INF/container.xml');
            if (!is_string($containerXml) || trim($containerXml) === '') {
                throw new HttpException(500, Lang::t('error.reader_epub_container_missing'));
            }

            $container = $this->loadXml($containerXml);
            $containerXPath = new \DOMXPath($container);
            $opfPath = trim((string) $containerXPath->evaluate('string(//*[local-name()="rootfile"][1]/@full-path)'));
            if ($opfPath === '') {
                throw new HttpException(500, Lang::t('error.reader_epub_package_missing'));
            }

            $normalizedOpfPath = $this->normalizeZipPath($opfPath);
            $opfXml = $zip->getFromName($normalizedOpfPath);
            if (!is_string($opfXml) || trim($opfXml) === '') {
                throw new HttpException(500, Lang::t('error.reader_epub_package_missing'));
            }

            $opf = $this->loadXml($opfXml);
            $xpath = new \DOMXPath($opf);

            $opfDir = $this->directoryOfZipPath($normalizedOpfPath);
            $bookTitle = trim((string) $xpath->evaluate('string(//*[local-name()="metadata"]/*[local-name()="title"][1])'));

            $manifestById = [];
            $manifestByPath = [];
            foreach ($xpath->query('//*[local-name()="manifest"]/*[local-name()="item"]') ?: [] as $itemNode) {
                if (!$itemNode instanceof \DOMElement) {
                    continue;
                }

                $itemId = trim($itemNode->getAttribute('id'));
                $href = trim($itemNode->getAttribute('href'));
                if ($itemId === '' || $href === '') {
                    continue;
                }

                $zipPath = $this->resolveZipRelative($opfDir, $href);
                $manifestItem = [
                    'id' => $itemId,
                    'href' => $href,
                    'zip_path' => $zipPath,
                    'media_type' => trim($itemNode->getAttribute('media-type')),
                    'properties' => trim($itemNode->getAttribute('properties')),
                ];
                $manifestById[$itemId] = $manifestItem;
                $manifestByPath[$zipPath] = $manifestItem;
            }

            $spine = [];
            foreach ($xpath->query('//*[local-name()="spine"]/*[local-name()="itemref"]') ?: [] as $itemRefNode) {
                if (!$itemRefNode instanceof \DOMElement) {
                    continue;
                }

                $idRef = trim($itemRefNode->getAttribute('idref'));
                if ($idRef === '' || !isset($manifestById[$idRef])) {
                    continue;
                }

                $item = $manifestById[$idRef];
                $mediaType = strtolower((string) ($item['media_type'] ?? ''));
                if (!in_array($mediaType, ['application/xhtml+xml', 'text/html', 'application/xml'], true)) {
                    continue;
                }

                $spine[] = $item;
            }

            if ($spine === []) {
                throw new HttpException(500, Lang::t('error.reader_epub_spine_missing'));
            }

            $tocEntries = $this->extractTocEntries($zip, $opfDir, $manifestById);
            $tocLabels = [];
            foreach ($tocEntries as $tocEntry) {
                $tocLabels[(string) ($tocEntry['path'] ?? '')] = (string) ($tocEntry['label'] ?? '');
            }
            $sections = [];
            $sectionIdByPath = [];

            foreach ($spine as $index => $item) {
                $sectionId = 'section-' . ($index + 1);
                $zipPath = (string) $item['zip_path'];
                $label = trim((string) ($tocLabels[$zipPath] ?? ''));
                if ($label === '') {
                    $label = $this->fallbackSectionLabel((string) ($item['href'] ?? ''), $index + 1);
                }

                $sections[] = [
                    'id' => $sectionId,
                    'label' => $label,
                    'href' => (string) ($item['href'] ?? ''),
                    'zip_path' => $zipPath,
                ];
                $sectionIdByPath[$zipPath] = $sectionId;
            }

            $toc = [];
            foreach ($tocEntries as $index => $tocEntry) {
                $tocPath = (string) ($tocEntry['path'] ?? '');
                $targetSectionId = (string) ($sectionIdByPath[$tocPath] ?? '');
                if ($targetSectionId === '') {
                    continue;
                }

                $toc[] = [
                    'id' => 'toc-' . ($index + 1),
                    'label' => trim((string) ($tocEntry['label'] ?? '')) !== ''
                        ? trim((string) $tocEntry['label'])
                        : ($tocPath !== '' ? basename($tocPath) : 'section'),
                    'section' => $targetSectionId,
                    'fragment' => trim((string) ($tocEntry['fragment'] ?? '')),
                ];
            }

            if ($toc === []) {
                foreach ($sections as $section) {
                    $toc[] = [
                        'id' => (string) $section['id'],
                        'label' => (string) $section['label'],
                        'section' => (string) $section['id'],
                        'fragment' => '',
                    ];
                }
            }

            [$initialSection, $initialFragment] = $this->resolveInitialTarget($toc, $sections);

            $inspection = [
                'book_title' => $bookTitle,
                'opf_path' => $normalizedOpfPath,
                'opf_dir' => $opfDir,
                'manifest_by_id' => $manifestById,
                'manifest_by_path' => $manifestByPath,
                'sections' => $sections,
                'section_id_by_path' => $sectionIdByPath,
                'toc' => $toc,
                'initial_section' => $initialSection,
                'initial_fragment' => $initialFragment,
            ];
            self::$inspectionCache[$cacheKey] = $inspection;

            return $inspection;
        } finally {
            $zip->close();
        }
    }

    private function extractTocEntries(\ZipArchive $zip, string $opfDir, array $manifestById): array
    {
        foreach ($manifestById as $item) {
            $properties = strtolower((string) ($item['properties'] ?? ''));
            if (!str_contains($properties, 'nav')) {
                continue;
            }

            $navXml = $zip->getFromName((string) $item['zip_path']);
            if (!is_string($navXml) || trim($navXml) === '') {
                continue;
            }

            return $this->parseNavDocument($navXml, $this->directoryOfZipPath((string) $item['zip_path']));
        }

        foreach ($manifestById as $item) {
            if (strtolower((string) ($item['media_type'] ?? '')) !== 'application/x-dtbncx+xml') {
                continue;
            }

            $ncxXml = $zip->getFromName((string) $item['zip_path']);
            if (!is_string($ncxXml) || trim($ncxXml) === '') {
                continue;
            }

            return $this->parseNcxDocument($ncxXml, $this->directoryOfZipPath((string) $item['zip_path']));
        }

        return [];
    }

    private function parseNavDocument(string $navXml, string $navDir): array
    {
        $document = $this->loadXml($navXml);
        $xpath = new \DOMXPath($document);
        $entries = [];
        $seen = [];

        foreach ($xpath->query('//*[local-name()="nav"]//*[local-name()="a"]') ?: [] as $anchorNode) {
            if (!$anchorNode instanceof \DOMElement) {
                continue;
            }

            $href = trim($anchorNode->getAttribute('href'));
            $label = trim($anchorNode->textContent);
            if ($href === '' || $label === '') {
                continue;
            }

            [$targetPath, $fragment] = $this->splitHrefFragment($href);
            if ($targetPath === '') {
                continue;
            }

            $resolvedPath = $this->resolveZipRelative($navDir, $targetPath);
            $dedupeKey = $resolvedPath . '#' . $fragment . '|' . $label;
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $entries[] = [
                'path' => $resolvedPath,
                'label' => $label,
                'fragment' => $fragment,
            ];
            $seen[$dedupeKey] = true;
        }

        return $entries;
    }

    private function parseNcxDocument(string $ncxXml, string $ncxDir): array
    {
        $document = $this->loadXml($ncxXml);
        $xpath = new \DOMXPath($document);
        $entries = [];
        $seen = [];

        foreach ($xpath->query('//*[local-name()="navPoint"]') ?: [] as $navPoint) {
            if (!$navPoint instanceof \DOMElement) {
                continue;
            }

            $src = trim((string) $xpath->evaluate('string(.//*[local-name()="content"][1]/@src)', $navPoint));
            $label = trim((string) $xpath->evaluate('string(.//*[local-name()="text"][1])', $navPoint));
            if ($src === '' || $label === '') {
                continue;
            }

            [$targetPath, $fragment] = $this->splitHrefFragment($src);
            if ($targetPath === '') {
                continue;
            }

            $resolvedPath = $this->resolveZipRelative($ncxDir, $targetPath);
            $dedupeKey = $resolvedPath . '#' . $fragment . '|' . $label;
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $entries[] = [
                'path' => $resolvedPath,
                'label' => $label,
                'fragment' => $fragment,
            ];
            $seen[$dedupeKey] = true;
        }

        return $entries;
    }

    private function resolveInitialTarget(array $toc, array $sections): array
    {
        if ($sections !== []) {
            return [
                (string) ($sections[0]['id'] ?? ''),
                '',
            ];
        }

        if ($toc !== []) {
            return [
                (string) ($toc[0]['section'] ?? ''),
                trim((string) ($toc[0]['fragment'] ?? '')),
            ];
        }

        return ['', ''];
    }

    private function resolveSection(array $inspection, string $sectionId): array
    {
        $normalized = trim($sectionId);
        foreach ($inspection['sections'] as $index => $section) {
            if (($section['id'] ?? '') !== $normalized) {
                continue;
            }

            $resolved = $section;
            $resolved['prev_id'] = $inspection['sections'][$index - 1]['id'] ?? null;
            $resolved['next_id'] = $inspection['sections'][$index + 1]['id'] ?? null;

            return $resolved;
        }

        throw new HttpException(404, Lang::t('error.reader_section_not_found'));
    }

    private function rewriteSectionDocument(string $content, int $bookId, array $inspection, array $section, string $theme): string
    {
        $document = $this->loadHtmlDocument($content);
        $this->stripUnsafeNodes($document);
        $this->stripUnsupportedStylesheets($document);
        $this->stripEventAttributes($document->documentElement ?? $document);
        $this->rewriteSectionLinks($document, $bookId, $inspection, $section, $theme);
        $this->ensureReaderStylesheet($document);
        $this->decorateDocument($document, $section, $theme);

        return (string) $document->saveHTML();
    }

    private function stripUnsafeNodes(\DOMDocument $document): void
    {
        foreach (['script', 'iframe', 'object', 'embed', 'base'] as $tagName) {
            $nodes = [];
            foreach ($document->getElementsByTagName($tagName) as $node) {
                $nodes[] = $node;
            }

            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
    }

    private function stripUnsupportedStylesheets(\DOMDocument $document): void
    {
        $links = [];
        foreach ($document->getElementsByTagName('link') as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $rel = strtolower(trim($node->getAttribute('rel')));
            $as = strtolower(trim($node->getAttribute('as')));
            if (str_contains($rel, 'preload') || str_contains($rel, 'prefetch') || $as === 'font') {
                $links[] = $node;
            }
        }

        foreach ($links as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach ($document->getElementsByTagName('style') as $styleNode) {
            if (!$styleNode instanceof \DOMElement) {
                continue;
            }

            $styleNode->nodeValue = $this->stripFontRules((string) $styleNode->textContent);
        }
    }

    private function stripEventAttributes(\DOMNode $node): void
    {
        if ($node instanceof \DOMElement && $node->hasAttributes()) {
            $remove = [];
            foreach ($node->attributes as $attribute) {
                if (str_starts_with(strtolower($attribute->name), 'on')) {
                    $remove[] = $attribute->name;
                    continue;
                }

                if (strtolower($attribute->name) === 'style') {
                    $sanitized = $this->sanitizeInlineStyle((string) $attribute->value);
                    if ($sanitized === '') {
                        $remove[] = $attribute->name;
                    } else {
                        $node->setAttribute('style', $sanitized);
                    }
                }
            }

            foreach ($remove as $attributeName) {
                $node->removeAttribute($attributeName);
            }
        }

        foreach ($node->childNodes as $childNode) {
            $this->stripEventAttributes($childNode);
        }
    }

    private function rewriteSectionLinks(\DOMDocument $document, int $bookId, array $inspection, array $section, string $theme): void
    {
        $elements = $document->getElementsByTagName('*');
        foreach ($elements as $element) {
            if (!$element instanceof \DOMElement) {
                continue;
            }

            foreach (['src', 'poster'] as $attributeName) {
                if (!$element->hasAttribute($attributeName)) {
                    continue;
                }

                $this->rewriteAssetAttribute($element, $attributeName, $bookId, (string) $section['zip_path']);
            }

            foreach (['data-src', 'data-original', 'data-lazy-src'] as $attributeName) {
                if (!$element->hasAttribute($attributeName)) {
                    continue;
                }

                $this->rewriteAssetAttribute($element, $attributeName, $bookId, (string) $section['zip_path']);
            }

            if ($element->hasAttribute('srcset')) {
                $this->rewriteSrcsetAttribute($element, 'srcset', $bookId, (string) $section['zip_path']);
            }

            if ($element->hasAttribute('data-srcset')) {
                $this->rewriteSrcsetAttribute($element, 'data-srcset', $bookId, (string) $section['zip_path']);
            }

            if ($element->hasAttribute('xlink:href')) {
                $this->rewriteAssetAttribute($element, 'xlink:href', $bookId, (string) $section['zip_path']);
            }

            if (!$element->hasAttribute('href')) {
                continue;
            }

            $originalHref = trim($element->getAttribute('href'));
            if ($originalHref === '') {
                continue;
            }

            if ($this->isExternalHyperlink($originalHref)) {
                $element->setAttribute('target', '_blank');
                $element->setAttribute('rel', 'noopener noreferrer');
                continue;
            }

            if ($originalHref[0] === '#') {
                continue;
            }

            [$hrefPath, $fragment] = $this->splitHrefFragment($originalHref);
            if ($hrefPath === '') {
                continue;
            }

            $resolvedPath = $this->resolveZipRelative($this->directoryOfZipPath((string) $section['zip_path']), $hrefPath);
            if (isset($inspection['section_id_by_path'][$resolvedPath])) {
                $targetSectionId = (string) $inspection['section_id_by_path'][$resolvedPath];
                $element->setAttribute('href', $this->buildReaderPageUrl($bookId, $targetSectionId, $theme, $fragment));
                continue;
            }

            $element->setAttribute('href', $this->buildReaderAssetUrl($bookId, $resolvedPath));
        }
    }

    private function rewriteAssetAttribute(\DOMElement $element, string $attributeName, int $bookId, string $sectionZipPath): void
    {
        $originalValue = trim($element->getAttribute($attributeName));
        if ($this->isExternalResource($originalValue) || str_starts_with($originalValue, '#')) {
            return;
        }

        [$assetPath] = $this->splitHrefFragment($originalValue);
        if ($assetPath === '') {
            return;
        }

        $resolvedPath = $this->resolveZipRelative($this->directoryOfZipPath($sectionZipPath), $assetPath);
        $element->setAttribute($attributeName, $this->buildReaderAssetUrl($bookId, $resolvedPath));
    }

    private function rewriteSrcsetAttribute(\DOMElement $element, string $attributeName, int $bookId, string $sectionZipPath): void
    {
        $srcset = trim($element->getAttribute($attributeName));
        if ($srcset === '') {
            return;
        }

        $candidates = [];
        foreach (explode(',', $srcset) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $candidate, 2) ?: [];
            $source = trim((string) ($parts[0] ?? ''));
            $descriptor = trim((string) ($parts[1] ?? ''));
            if ($source === '' || $this->isExternalResource($source) || str_starts_with($source, '#')) {
                $candidates[] = $candidate;
                continue;
            }

            [$assetPath] = $this->splitHrefFragment($source);
            if ($assetPath === '') {
                $candidates[] = $candidate;
                continue;
            }

            $resolvedPath = $this->resolveZipRelative($this->directoryOfZipPath($sectionZipPath), $assetPath);
            $rewritten = $this->buildReaderAssetUrl($bookId, $resolvedPath);
            $candidates[] = $descriptor !== '' ? $rewritten . ' ' . $descriptor : $rewritten;
        }

        if ($candidates !== []) {
            $element->setAttribute($attributeName, implode(', ', $candidates));
        }
    }

    private function ensureReaderStylesheet(\DOMDocument $document): void
    {
        $head = $document->getElementsByTagName('head')->item(0);
        if (!$head instanceof \DOMElement) {
            $html = $document->getElementsByTagName('html')->item(0);
            if (!$html instanceof \DOMElement) {
                $html = $document->createElement('html');
                while ($document->firstChild !== null) {
                    $html->appendChild($document->firstChild);
                }
                $document->appendChild($html);
            }

            $head = $document->createElement('head');
            $html->insertBefore($head, $html->firstChild);
        }

        $stylesheet = $document->createElement('link');
        $stylesheet->setAttribute('rel', 'stylesheet');
        $stylesheet->setAttribute('href', 'assets/css/reader.css?v=' . rawurlencode((string) (@filemtime($this->appRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'reader.css') ?: time())));
        $head->appendChild($stylesheet);

        $metaCharset = $document->createElement('meta');
        $metaCharset->setAttribute('charset', 'utf-8');
        $head->insertBefore($metaCharset, $head->firstChild);
    }

    private function decorateDocument(\DOMDocument $document, array $section, string $theme): void
    {
        $html = $document->getElementsByTagName('html')->item(0);
        if (!$html instanceof \DOMElement) {
            return;
        }

        $html->setAttribute('data-theme', $theme === 'dark' ? 'dark' : 'light');

        $body = $document->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            $body = $document->createElement('body');
            $html->appendChild($body);
        }

        $className = trim($body->getAttribute('class'));
        $body->setAttribute('class', trim($className . ' reader-embedded'));
        $body->setAttribute('data-reader-section', (string) ($section['id'] ?? ''));
    }

    private function buildReaderAssetUrl(int $bookId, string $assetPath): string
    {
        return 'reader_asset.php?id=' . $bookId . '&asset=' . rawurlencode($assetPath);
    }

    private function buildReaderPageUrl(int $bookId, string $sectionId, string $theme, string $fragment = ''): string
    {
        $url = 'reader_page.php?id=' . $bookId
            . '&section=' . rawurlencode($sectionId)
            . '&theme=' . rawurlencode($theme === 'dark' ? 'dark' : 'light');

        if ($fragment !== '') {
            $url .= '#' . rawurlencode($fragment);
        }

        return $url;
    }

    private function loadXml(string $xml): \DOMDocument
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded !== true) {
            throw new HttpException(500, Lang::t('error.reader_epub_parse_failed'));
        }

        return $document;
    }

    private function loadHtmlDocument(string $html): \DOMDocument
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded !== true) {
            throw new HttpException(500, Lang::t('error.reader_epub_parse_failed'));
        }

        return $document;
    }

    private function fallbackSectionLabel(string $href, int $index): string
    {
        $basename = trim((string) pathinfo($href, PATHINFO_FILENAME));
        if ($basename !== '') {
            return $basename;
        }

        return Lang::t('reader.section_fallback', ['number' => (string) $index]);
    }

    private function splitHrefFragment(string $href): array
    {
        $parts = explode('#', $href, 2);
        $path = trim($parts[0] ?? '');
        $queryOffset = strpos($path, '?');
        if ($queryOffset !== false) {
            $path = substr($path, 0, $queryOffset);
        }

        return [
            trim($path),
            trim($parts[1] ?? ''),
        ];
    }

    private function resolveZipRelative(string $baseDir, string $relativePath): string
    {
        return $this->normalizeZipPath(
            ($baseDir !== '' ? $baseDir . '/' : '')
            . str_replace('\\', '/', $relativePath)
        );
    }

    private function directoryOfZipPath(string $path): string
    {
        $normalized = $this->normalizeZipPath($path);
        $directory = str_replace('\\', '/', dirname($normalized));

        return $directory === '.' ? '' : trim($directory, '/');
    }

    private function normalizeZipPath(string $path): string
    {
        $normalized = str_replace('\\', '/', rawurldecode(trim($path)));
        $parts = [];
        foreach (explode('/', $normalized) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);
                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function resolveExistingZipPath(\ZipArchive $zip, string $normalizedPath): ?string
    {
        if ($normalizedPath === '') {
            return null;
        }

        if ($zip->locateName($normalizedPath) !== false) {
            return $normalizedPath;
        }

        $caseInsensitiveMatch = null;
        $targetLower = strtolower($normalizedPath);
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $candidate = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
            if ($candidate === '') {
                continue;
            }

            $candidateNormalized = $this->normalizeZipPath($candidate);
            if ($candidateNormalized === $normalizedPath) {
                return $candidate;
            }

            if ($caseInsensitiveMatch === null && strtolower($candidateNormalized) === $targetLower) {
                $caseInsensitiveMatch = $candidate;
            }
        }

        return $caseInsensitiveMatch;
    }

    private function isExternalResource(string $value): bool
    {
        $trimmed = strtolower(trim($value));

        return $trimmed === ''
            || str_starts_with($trimmed, 'data:')
            || str_starts_with($trimmed, 'http://')
            || str_starts_with($trimmed, 'https://')
            || str_starts_with($trimmed, '//');
    }

    private function isExternalHyperlink(string $value): bool
    {
        $trimmed = strtolower(trim($value));

        return $trimmed === ''
            || str_starts_with($trimmed, 'http://')
            || str_starts_with($trimmed, 'https://')
            || str_starts_with($trimmed, '//')
            || str_starts_with($trimmed, 'mailto:')
            || str_starts_with($trimmed, 'javascript:');
    }

    private function detectMimeTypeFromPath(string $path): string
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'css' => 'text/css; charset=UTF-8',
            'js' => 'application/javascript; charset=UTF-8',
            'svg' => 'image/svg+xml',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'xhtml', 'html', 'htm' => 'text/html; charset=UTF-8',
            'xml' => 'application/xml; charset=UTF-8',
            default => 'application/octet-stream',
        };
    }

    private function sanitizeStylesheetContent(string $css, array $inspection, string $stylesheetPath, int $bookId): string
    {
        $sanitized = $this->stripFontRules($css);
        $baseDir = $this->directoryOfZipPath($stylesheetPath);

        return (string) preg_replace_callback('/url\(([^)]+)\)/i', function (array $matches) use ($inspection, $baseDir, $bookId): string {
            $raw = trim((string) ($matches[1] ?? ''));
            $raw = trim($raw, "\"' \t\r\n");
            if ($raw === '' || $this->isExternalResource($raw) || str_starts_with($raw, '#')) {
                return $matches[0];
            }

            [$assetPath] = $this->splitHrefFragment($raw);
            if ($assetPath === '') {
                return $matches[0];
            }

            $resolvedPath = $this->resolveZipRelative($baseDir, $assetPath);
            $manifestItem = $inspection['manifest_by_path'][$resolvedPath] ?? null;
            $mediaType = strtolower((string) ($manifestItem['media_type'] ?? $this->detectMimeTypeFromPath($resolvedPath)));
            if (str_contains($mediaType, 'font') || preg_match('/\.(ttf|otf|woff2?|eot)$/i', $resolvedPath)) {
                return 'url("")';
            }

            return 'url("' . $this->buildReaderAssetUrl($bookId, $resolvedPath) . '")';
        }, $sanitized) ?? $sanitized;
    }

    private function stripFontRules(string $css): string
    {
        $css = preg_replace('/@font-face\s*\{.*?\}\s*/is', '', $css) ?? $css;
        $css = preg_replace('/@import\s+[^;]+;/i', '', $css) ?? $css;

        return preg_replace('/font-family\s*:\s*[^;]+;/i', '', $css) ?? $css;
    }

    private function sanitizeInlineStyle(string $style): string
    {
        $rules = preg_split('/\s*;\s*/', trim($style)) ?: [];
        $sanitized = [];

        foreach ($rules as $rule) {
            if ($rule === '' || !str_contains($rule, ':')) {
                continue;
            }

            [$property, $value] = array_map('trim', explode(':', $rule, 2));
            $normalizedProperty = strtolower($property);
            $normalizedValue = strtolower($value);

            if ($normalizedProperty === 'font-family') {
                continue;
            }

            if (str_contains($normalizedValue, '.ttf')
                || str_contains($normalizedValue, '.otf')
                || str_contains($normalizedValue, '.woff')
                || str_contains($normalizedValue, '.woff2')
                || str_contains($normalizedValue, 'font-face')) {
                continue;
            }

            $sanitized[] = $property . ': ' . $value;
        }

        return implode('; ', $sanitized);
    }
}
