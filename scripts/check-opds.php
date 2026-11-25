<?php

declare(strict_types=1);

// Isolated regression checks; never bootstrap the live site's settings/database.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Calibre\\')) {
        require_once dirname(__DIR__) . '/site/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
    }
});

use Calibre\Book;
use Calibre\LibraryIndex;
use Calibre\ScanService;
use Calibre\Services\OpdsService;
use Calibre\Support\Lang;
use Calibre\Support\OpdsUrlGenerator;

Lang::boot(dirname(__DIR__) . '/site', 'en');
$root = sys_get_temp_dir() . '/bookslib-opds-' . bin2hex(random_bytes(8));
mkdir($root . '/books', 0777, true);
mkdir($root . '/data', 0777, true);
$failures = [];
$checks = 0;
$check = static function (bool $ok, string $message) use (&$failures, &$checks): void {
    ++$checks;
    if (!$ok) {
        $failures[] = $message;
        fwrite(STDERR, "FAIL: $message\n");
    }
};
$parse = static function (string $xml) use ($check): DOMXPath {
    $dom = new DOMDocument();
    $check($dom->loadXML($xml, LIBXML_NONET), 'XML parses');
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('a', 'http://www.w3.org/2005/Atom');
    $xpath->registerNamespace('d', 'http://purl.org/dc/terms/');
    $xpath->registerNamespace('s', 'http://a9.com/-/spec/opensearch/1.1/');
    return $xpath;
};

try {
    $config = [
        'SITE_TITLE' => str_repeat('測試書庫', 270),
        'SITE_BASE_URL' => 'https://example.test/library',
        'CALIBRE_LIBRARY_PATH' => $root . '/books',
        'SQLITE_INDEX_PATH' => $root . '/data/index.sqlite',
        'OPDS_PAGE_SIZE' => '1',
    ];
    $scan = new ScanService($root, $config);
    $index = new LibraryIndex($config['SQLITE_INDEX_PATH'], $root);
    $books = [];
    $cover = $root . '/books/cover.png';
    file_put_contents($cover, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII='));
    foreach (['epub', 'pdf', 'cbz'] as $n => $format) {
        $path = $root . '/books/test.' . $format;
        file_put_contents($path, 'OPDS fixture');
        $books[] = new Book('Book ' . $n . ' & <標題>', 'Author ' . $n, $path, [$format => $path], [
            'uuid' => $n < 2 ? '6409a00b-7bf2-405e-826c-3fdff0fd0734' : '',
            'tag' => 'Tag ' . $n, 'series' => 'Series ' . $n, 'series_index' => 1.5,
            'description' => '<p>Description &amp; text</p>', 'identifier' => 'urn:test:' . $n,
            'published_at' => $n === 0 ? '2001-01-01' : '',
            'library_timestamp' => '2020-01-01',
        ], $cover);
    }
    $index->rebuild($books, []);
    $pdo = new PDO('sqlite:' . $config['SQLITE_INDEX_PATH']);
    $pdo->exec('UPDATE books SET is_read = 1 WHERE id = 2');
    $service = new OpdsService($root, $scan, $index);
    $server = ['HTTP_HOST' => 'example.test', 'HTTPS' => 'on', 'OPDS_BASE_PATH' => '/opds'];
    $tokenServer = array_replace($server, ['OPDS_BASE_PATH' => '/opds/test-token']);
    $urls = new OpdsUrlGenerator($server, $config['SITE_BASE_URL']);
    $tokenUrls = new OpdsUrlGenerator($tokenServer, $config['SITE_BASE_URL']);
    $check($urls->asset('assets/opds/catalog.svg') === $tokenUrls->asset('assets/opds/catalog.svg'), 'Token does not change public asset location');
    $rootFeed = $parse($service->renderCatalog($server, []));
    foreach (['books', 'read', 'unread', 'new'] as $feed) {
        $links = $rootFeed->query('/a:feed/a:entry/a:link[contains(@href,"feed=' . $feed . '")]');
        $check($links->length === 1 && str_contains($links->item(0)->getAttribute('type'), 'kind=acquisition'), "$feed root link is acquisition");
    }
    $id = $rootFeed->evaluate('string(/a:feed/a:id)');
    $check($id !== 'urn:uuid:2853dacf-ed79-42f5-8e8a-a7bb3d1ae6a2', 'Root ID is not the specification example');
    $fresh = new OpdsService($root, $scan, $index);
    $check($id === $parse($fresh->renderCatalog($tokenServer, []))->evaluate('string(/a:feed/a:id)'), 'Root ID persists across instances and token URLs');
    mkdir($root . '/other/data', 0777, true);
    $other = new OpdsService($root . '/other', $scan, $index);
    $check($id !== $parse($other->renderCatalog($server, []))->evaluate('string(/a:feed/a:id)'), 'Separate installations have distinct root IDs');

    $queries = $service->buildStaticCatalogQueries();
    $queries[] = ['feed' => 'read'];
    $queries[] = ['feed' => 'unread'];
    $queries[] = ['feed' => 'search', 'query' => 'Book'];
    $queries[] = ['feed' => 'search', 'query' => 'no-such-title'];
    $queries[] = ['feed' => 'search'];
    file_put_contents($root . '/queries.json', json_encode($queries, JSON_THROW_ON_ERROR));
    $entryIds = [];
    foreach ([$server, $tokenServer] as $context => $request) {
        foreach ($queries as $n => $query) {
            $xml = $service->renderCatalog($request, $query);
            file_put_contents($root . '/feed-' . $context . '-' . $n . '.xml', $xml);
            $xp = $parse($xml);
            $feedName = $query['feed'] ?? 'index';
            foreach ($xp->query('/a:feed | /a:feed/a:entry') as $node) {
                foreach (['id', 'title', 'updated'] as $required) {
                    $check($xp->query('a:' . $required, $node)->length === 1, "$feedName has exactly one $required");
                }
            }
            $check($xp->query('/a:feed/a:author/a:name')->length === 1, "$feedName has a feed author");
            $check($xp->query('/a:feed/a:link[@rel="self"]')->length === 1, "$feedName has self");
            $check($xp->query('/a:feed/a:link[@rel="start"]')->length === 1, "$feedName has start");
            foreach ($xp->query('//a:entry') as $entry) {
                $check($xp->query('a:content | a:link[@rel="alternate"]', $entry)->length > 0, "$feedName entry has content or alternate (Atom 4.1.2)");
                foreach ($xp->query('a:summary', $entry) as $summary) {
                    $check($summary->getAttribute('type') === 'text' && $xp->query('*', $summary)->length === 0, 'Summary is text only');
                }
                if (str_contains($service->responseContentType($feedName), 'kind=acquisition')) {
                    $downloads = $xp->query('a:link[@rel="http://opds-spec.org/acquisition"]', $entry);
                    $check($downloads->length > 0, "$feedName entry has acquisition");
                    $check($xp->query('a:link[@rel="http://opds-spec.org/image" and @type="image/png"]', $entry)->length === 1, 'Artwork includes a supported raster type');
                    foreach ($downloads as $download) {
                        $check(in_array($download->getAttribute('type'), ['application/epub+zip', 'application/pdf', 'application/vnd.comicbook+zip'], true), 'Download MIME matches fixture format');
                        $check($context === 0 || str_contains($download->getAttribute('href'), '/opds/test-token/'), 'Download retains token');
                    }
                    $check($xp->query('a:category[@scheme="series"]', $entry)->length === 0, 'Category scheme is an absolute IRI');
                }
            }
            if ($feedName === 'books') {
                $offset = $query['offset'] ?? 0;
                $entryIds[$context][$offset] = $xp->evaluate('string(/a:feed/a:entry/a:id)');
                $check($xp->query('/a:feed/a:entry/*[local-name()="identifier" and text()="urn:test:' . $offset . '"]')->length === 1, 'Known publication identifier is included');
                if ($offset > 0) {
                    $check($xp->query('/a:feed/a:entry/d:issued')->length === 0, 'Unknown publication date is not replaced with import date');
                }
                if ($offset < 2) {
                    $check($xp->query('/a:feed/a:link[@rel="next"]')->length === 1, 'Next page is linked');
                }
            }
        }
    }
    $check(count(array_unique($entryIds[0])) === 3, 'Different format records have unique entry IDs');
    $check($entryIds[0] === $entryIds[1], 'Entry IDs do not depend on authentication URL');
    $osd = $service->renderSearchDescription($tokenServer);
    file_put_contents($root . '/search.xml', $osd);
    $xp = $parse($osd);
    $check(mb_strlen($xp->evaluate('string(/s:OpenSearchDescription/s:ShortName)')) <= 16, 'OpenSearch ShortName is at most 16 characters');
    $check(mb_strlen($xp->evaluate('string(/s:OpenSearchDescription/s:Description)')) <= 1024, 'OpenSearch Description is at most 1024 characters');
    $check(str_contains($xp->evaluate('string(//s:Url/@template)'), '/opds/test-token?feed=search&query={searchTerms}'), 'Search template retains token and searchTerms');
    unlink($root . '/books/test.epub');
    $xp = $parse($service->renderCatalog($server, ['feed' => 'books']));
    $check($xp->query('/a:feed/a:entry[not(a:link[@rel="http://opds-spec.org/acquisition"])]')->length === 0, 'Missing files never produce invalid acquisition entries');
    // Reindexing must not turn a changed SQLite row ID into a new OPDS entry.
    file_put_contents($root . '/books/test.epub', 'OPDS fixture');
    $index->rebuild(array_reverse($books), []);
    foreach ([0, 1, 2] as $offset) {
        $xp = $parse($service->renderCatalog($server, ['feed' => 'books', 'offset' => $offset]));
        $check($entryIds[0][$offset] === $xp->evaluate('string(/a:feed/a:entry/a:id)'), 'Entry ID survives reindexing with different row IDs');
    }
    $index->rebuild([], []);
    $xp = $parse($service->renderCatalog($server, ['feed' => 'books']));
    $check($xp->query('/a:feed/a:entry')->length === 0 && $xp->query('/a:feed/a:author')->length === 1, 'Empty library is a valid feed');
    // Leave a populated isolated fixture for the HTTP/schema checks.
    $index->rebuild($books, []);
    $index->close();
    echo count($failures) . " failure(s), $checks checks. Fixtures: $root\n";
    exit($failures === [] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, (string) $e . "\nFixtures: $root\n");
    exit(1);
}
