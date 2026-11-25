"""Exercise the real PHP OPDS entry point with disposable accounts and books.

Usage: python scripts/check-opds-http.py
Requires PHP 8.4 on PATH; uses no running service or live application data.
"""

import base64
import json
import pathlib
import re
import shutil
import socket
import sqlite3
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

REPO = pathlib.Path(__file__).resolve().parents[1]
ATOM = '{http://www.w3.org/2005/Atom}'


def main():
    result = subprocess.run(['php', str(REPO / 'scripts/check-opds.php')], capture_output=True, text=True, check=True)
    print(result.stdout.strip())
    root = pathlib.Path(re.search(r'Fixtures: (.+)', result.stdout).group(1).strip())
    shutil.copytree(REPO / 'site/src', root / 'src')
    for name in ['bootstrap.php', 'opds.php']:
        shutil.copy2(REPO / 'site' / name, root / name)
    (root / 'books/test.epub').write_bytes(b'OPDS fixture')
    with sqlite3.connect(root / 'data/index.sqlite') as database:
        book_id = database.execute("SELECT id FROM books WHERE title LIKE 'Book 0%'").fetchone()[0]
    (root / 'config.env').write_text(
        'AUTH_ENABLED=1\nAUTH_USERNAME=opds-test\nAUTH_PASSWORD=fixture-password\n'
        'AUTH_SECRET_KEY=isolated-opds-test-key\nSITE_TITLE="HTTP OPDS & test"\n'
        'CALIBRE_LIBRARY_PATH="books"\nSQLITE_INDEX_PATH="data/index.sqlite"\nOPDS_PAGE_SIZE=1\n', encoding='utf-8'
    )
    seeded = subprocess.run(['php', '-r',
        'require $argv[1]."/bootstrap.php"; $a=new Calibre\\Services\\AuthService($argv[1]);'
        '$a->ensureBootstrapUserSeeded(); echo json_encode(["token"=>$a->rotateApiToken(1)]);', str(root)],
        capture_output=True, text=True, check=True)
    token = json.loads(seeded.stdout)['token']
    (root / 'router.php').write_text(
        '<?php $p=parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH); '
        'if(str_starts_with($p,"/opds/")) { $_GET["opds_path"]=substr($p,6); } '
        'require __DIR__."/opds.php";', encoding='utf-8'
    )
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    basic = {'Authorization': 'Basic ' + base64.b64encode(b'opds-test:fixture-password').decode()}
    count = 0

    def request(path, headers=None, method='GET', status=200, xml=True):
        nonlocal count
        req = urllib.request.Request(base + path, headers=headers or {}, method=method)
        try:
            response = urllib.request.urlopen(req, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        body = response.read()
        assert response.status == status, (path, response.status, body[:200])
        if method == 'HEAD':
            assert body == b'', path
        elif xml:
            doc = ET.fromstring(body)
            assert response.headers.get('Content-Type', '').startswith(('application/atom+xml', 'application/opensearchdescription+xml')), path
            if doc.tag == ATOM + 'feed':
                assert len(doc.findall(ATOM + 'author')) == 1, path
                for field in ['id', 'title', 'updated']:
                    assert len(doc.findall(ATOM + field)) == 1, (path, field)
            (root / f'http-{count}.xml').write_bytes(body)
        if status == 401:
            assert response.headers.get('WWW-Authenticate', '').startswith('Basic ')
        if status == 405:
            assert response.headers['Allow'] == 'GET, HEAD'
        count += 1
        return body, response.headers

    with (root / 'http.log').open('w', encoding='utf-8') as log:
        process = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(root), str(root / 'router.php')], stdout=log, stderr=log)
        try:
            for _ in range(100):
                if process.poll() is not None:
                    raise RuntimeError('PHP test server exited')
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.05)
            request('/opds', status=401)
            request('/opds', method='HEAD', status=401)
            request('/opds/invalid-token', status=401)
            request('/opds', {'Authorization': 'Basic ' + base64.b64encode(b'opds-test:wrong').decode()}, status=401)
            for prefix, headers in [('/opds', basic), ('/opds/' + token, {})]:
                cover, cover_headers = request(prefix + f'?feed=cover&id={book_id}', headers, xml=False)
                assert cover.startswith(b'\x89PNG') and cover_headers['Content-Type'] == 'image/png'
                request(prefix + f'?feed=cover&id={book_id}', headers, method='HEAD')
                queries = json.loads((root / 'queries.json').read_text(encoding='utf-8')) + [{'feed': 'osd'}]
                for params in queries:
                    query = '?' + urllib.parse.urlencode(params) if params else ''
                    body, _ = request(prefix + query, headers)
                    request(prefix + query, headers, method='HEAD')
                    doc = ET.fromstring(body)
                    for link in doc.iter(ATOM + 'link'):
                        if link.attrib.get('rel') == 'http://opds-spec.org/acquisition':
                            url = urllib.parse.urlsplit(link.attrib['href'])
                            download, _ = request(url.path + ('?' + url.query if url.query else ''), headers, xml=False)
                            assert download == b'OPDS fixture'
                for query, status in [('?feed=missing', 404), ('?feed=author', 400), ('?feed=cover&id=0', 400), ('?feed=cover&id=999', 404), ('?feed=download&id=999', 404), ('?feed[]=books', 400)]:
                    request(prefix + query, headers, status=status)
                    request(prefix + query, headers, method='HEAD', status=status)
                request(prefix, headers, method='POST', status=405)
                request(prefix + '/download/1/epub', headers, method='POST', status=405)
            # The non-token and token roots identify the same catalog.
            a, _ = request('/opds', basic)
            b, _ = request('/opds/' + token)
            assert ET.fromstring(a).findtext(ATOM + 'id') == ET.fromstring(b).findtext(ATOM + 'id')
        finally:
            process.terminate()
            process.wait(timeout=10)
    errors = (root / 'http.log').read_text(encoding='utf-8')
    assert not re.search(r'Fatal error|Warning:|Uncaught|Parse error', errors), errors
    print(f'PASS: {count} HTTP requests (Basic Auth, tokens, feeds, downloads, errors, GET/HEAD/405). Fixtures: {root}')


if __name__ == '__main__':
    main()
