<?php

namespace Calibre\Support;

final class OpdsErrorResponse
{
    public static function send(int $status, string $title, string $detail, string $method = 'GET'): never
    {
        http_response_code($status);
        header_remove('Content-Length');
        header_remove('Content-Disposition');
        header('Content-Type: application/atom+xml;profile=opds-catalog;kind=navigation; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        if ($status === 401) {
            header('WWW-Authenticate: Basic realm="SamBooksLib OPDS", charset="UTF-8"');
        }
        if ($status === 405) {
            header('Allow: GET, HEAD');
        }
        if ($method === 'HEAD') {
            exit;
        }

        $ns = 'http://www.w3.org/2005/Atom';
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $feed = $dom->createElementNS($ns, 'feed');
        $dom->appendChild($feed);
        foreach (['id' => 'urn:bookslib:opds:error:' . $status, 'title' => $title,
            'updated' => gmdate(DATE_ATOM), 'subtitle' => $detail] as $name => $text) {
            $node = $dom->createElementNS($ns, $name);
            $node->appendChild($dom->createTextNode($text));
            $feed->appendChild($node);
        }
        $author = $dom->createElementNS($ns, 'author');
        $author->appendChild($dom->createElementNS($ns, 'name', 'SamBooksLib'));
        $feed->appendChild($author);
        echo $dom->saveXML();
        exit;
    }
}
