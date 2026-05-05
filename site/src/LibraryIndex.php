<?php

namespace Calibre;

use Calibre\Database\MigrationRunner;

class LibraryIndex
{
    private string $appRoot;
    private string $sqlitePath;
    private \PDO $pdo;
    private bool $ftsSearchAvailable = false;
    /** @var string[] */
    private array $searchableFormatTerms = ['pdf', 'epub', 'cbz'];

    public function __construct(string $sqlitePath, ?string $appRoot = null)
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new \RuntimeException('PDO SQLite extension is not loaded.');
        }

        $this->appRoot = rtrim($appRoot ?? dirname(__DIR__), DIRECTORY_SEPARATOR);
        $this->sqlitePath = $sqlitePath;
        $dir = dirname($sqlitePath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create sqlite directory: {$dir}");
        }
        @chmod($dir, 0777);

        $this->openConnection();
    }

    public function rebuild(iterable $books, ?array $readStatesByPath = null, int $batchSize = 50, ?callable $onBatchCommitted = null): int
    {
        $normalizedBatchSize = max(1, $batchSize);

        if ($readStatesByPath !== null) {
            return $this->rebuildWithReadStates($books, $this->normalizeReadStateMap($readStatesByPath), $normalizedBatchSize, $onBatchCommitted);
        }

        try {
            return $this->rebuildWithReadStates($books, $this->getReadStatesByPath(), $normalizedBatchSize, $onBatchCommitted);
        } catch (\Throwable $e) {
            if (!$this->isCorruptedDatabaseError($e)) {
                throw $e;
            }
        }

        $this->recreateDatabase();

        return $this->rebuildWithReadStates($books, [], $normalizedBatchSize, $onBatchCommitted);
    }

    public function append(iterable $books, ?array $readStatesByPath = null, int $batchSize = 50, ?callable $onBatchCommitted = null): int
    {
        $normalizedBatchSize = max(1, $batchSize);

        if ($readStatesByPath !== null) {
            return $this->appendWithReadStates($books, $this->normalizeReadStateMap($readStatesByPath), $normalizedBatchSize, $onBatchCommitted);
        }

        try {
            return $this->appendWithReadStates($books, $this->getReadStatesByPath(), $normalizedBatchSize, $onBatchCommitted);
        } catch (\Throwable $e) {
            if (!$this->isCorruptedDatabaseError($e)) {
                throw $e;
            }
        }

        $this->recreateDatabase();

        return $this->appendWithReadStates($books, [], $normalizedBatchSize, $onBatchCommitted);
    }

    public function exportReadStatesByPath(): array
    {
        try {
            return $this->getReadStatesByPath();
        } catch (\Throwable $e) {
            if ($this->isCorruptedDatabaseError($e)) {
                return [];
            }

            throw $e;
        }
    }

    public function exportBookSnapshotsByPath(): array
    {
        $snapshots = [];
        $stmt = $this->pdo->query(
            'SELECT path, title, author, cover_path, formats_json, metadata_json, source_mtime
             FROM books'
        );

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $path = isset($row['path']) ? $this->normalizePath((string) $row['path']) : null;
            if ($path === null || $path === '') {
                continue;
            }

            $formats = [];
            $formatsJson = (string) ($row['formats_json'] ?? '');
            if ($formatsJson !== '') {
                $decodedFormats = json_decode($formatsJson, true);
                if (is_array($decodedFormats)) {
                    $formats = $this->normalizeFormats($decodedFormats);
                }
            }

            $metadata = [];
            $metadataJson = (string) ($row['metadata_json'] ?? '');
            if ($metadataJson !== '') {
                $decodedMetadata = json_decode($metadataJson, true);
                if (is_array($decodedMetadata)) {
                    $metadata = $decodedMetadata;
                }
            }

            $sourceMtime = isset($row['source_mtime']) && is_numeric($row['source_mtime'])
                ? (int) $row['source_mtime']
                : null;

            $snapshots[$path] = [
                'title' => (string) ($row['title'] ?? ''),
                'author' => (string) ($row['author'] ?? ''),
                'cover_path' => $this->normalizePath(isset($row['cover_path']) ? (string) $row['cover_path'] : null),
                'formats' => $formats,
                'metadata' => $metadata,
                'source_mtime' => $sourceMtime,
            ];
        }

        return $snapshots;
    }

    public function exportScanSnapshotsByPath(): array
    {
        $snapshots = [];
        $stmt = $this->pdo->query(
            'SELECT path, title, author, cover_path, formats_json, source_mtime
             FROM books'
        );

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $path = isset($row['path']) ? $this->normalizePath((string) $row['path']) : null;
            if ($path === null || $path === '') {
                continue;
            }

            $formats = [];
            $formatsJson = (string) ($row['formats_json'] ?? '');
            if ($formatsJson !== '') {
                $decodedFormats = json_decode($formatsJson, true);
                if (is_array($decodedFormats)) {
                    $formats = $this->normalizeFormats($decodedFormats);
                }
            }

            $sourceMtime = isset($row['source_mtime']) && is_numeric($row['source_mtime'])
                ? (int) $row['source_mtime']
                : null;

            $snapshots[$path] = [
                'title' => (string) ($row['title'] ?? ''),
                'author' => (string) ($row['author'] ?? ''),
                'cover_path' => $this->normalizePath(isset($row['cover_path']) ? (string) $row['cover_path'] : null),
                'formats' => $formats,
                'metadata' => [],
                'source_mtime' => $sourceMtime,
            ];
        }

        return $snapshots;
    }

    public function exportBookPathSnapshots(): array
    {
        $snapshots = [];
        $stmt = $this->pdo->query(
            'SELECT path, title, author, source_mtime
             FROM book_paths'
        );

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $path = isset($row['path']) ? $this->normalizePath((string) $row['path']) : null;
            if ($path === null || $path === '') {
                continue;
            }

            $snapshots[$path] = [
                'title' => (string) ($row['title'] ?? ''),
                'author' => (string) ($row['author'] ?? ''),
                'source_mtime' => isset($row['source_mtime']) && is_numeric($row['source_mtime'])
                    ? (int) $row['source_mtime']
                    : null,
            ];
        }

        if ($snapshots !== []) {
            return $snapshots;
        }

        $fallbackStmt = $this->pdo->query(
            'SELECT path, title, author, source_mtime
             FROM books'
        );
        while ($row = $fallbackStmt->fetch(\PDO::FETCH_ASSOC)) {
            $path = isset($row['path']) ? $this->normalizePath((string) $row['path']) : null;
            if ($path === null || $path === '') {
                continue;
            }

            $snapshots[$path] = [
                'title' => (string) ($row['title'] ?? ''),
                'author' => (string) ($row['author'] ?? ''),
                'source_mtime' => isset($row['source_mtime']) && is_numeric($row['source_mtime'])
                    ? (int) $row['source_mtime']
                    : null,
            ];
        }

        return $snapshots;
    }

    public function replaceBookPathIndex(array $snapshotsByPath): int
    {
        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $this->pdo->exec('DELETE FROM book_paths');
            $stmt = $this->pdo->prepare(
                'INSERT INTO book_paths(path, title, author, source_mtime, updated_at)
                 VALUES(:path, :title, :author, :source_mtime, CURRENT_TIMESTAMP)'
            );

            $count = 0;
            foreach ($snapshotsByPath as $path => $snapshot) {
                if (!is_string($path) || trim($path) === '' || !is_array($snapshot)) {
                    continue;
                }

                $normalizedPath = $this->normalizePath($path);
                if ($normalizedPath === null || trim($normalizedPath) === '') {
                    continue;
                }

                $stmt->execute([
                    ':path' => $normalizedPath,
                    ':title' => (string) ($snapshot['title'] ?? ''),
                    ':author' => (string) ($snapshot['author'] ?? ''),
                    ':source_mtime' => isset($snapshot['source_mtime']) && is_numeric((string) $snapshot['source_mtime'])
                        ? (int) $snapshot['source_mtime']
                        : null,
                ]);
                $count++;
            }

            $this->pdo->exec('COMMIT');

            return $count;
        } catch (\Throwable $e) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\Throwable) {
            }

            throw $e;
        }
    }

    public function close(): void
    {
        if (isset($this->pdo)) {
            unset($this->pdo);
        }
    }

    public function getAllBooks(): array
    {
        $stmt = $this->pdo->query(
            'SELECT ' . $this->getBookListColumns() . '
             FROM books
             ORDER BY title COLLATE NOCASE ASC'
        );

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function search(string $query, array $hiddenAuthors = [], array $hiddenTags = []): array
    {
        $query = trim($query);
        $visibilityFilter = $this->buildVisibilityFilter($hiddenAuthors, $hiddenTags);
        if ($query === '') {
            if ($visibilityFilter === null) {
                return $this->getAllBooks();
            }

            $stmt = $this->pdo->prepare(
                'SELECT ' . $this->getBookListColumns() . '
                 FROM books
                 WHERE ' . $visibilityFilter['sql'] . '
                 ORDER BY title COLLATE NOCASE ASC'
            );
            $stmt->execute($visibilityFilter['params']);

            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }

        $searchFilter = $this->buildSearchFilter($query);
        $whereSql = $searchFilter['sql'];
        if ($visibilityFilter !== null) {
            $whereSql = '(' . $whereSql . ') AND ' . $visibilityFilter['sql'];
        }
        $stmt = $this->pdo->prepare(
            'SELECT ' . $this->getBookListColumns() . '
             FROM books
             WHERE ' . $whereSql . '
             ORDER BY title COLLATE NOCASE ASC'
        );
        $stmt->execute(array_merge($searchFilter['params'], $visibilityFilter['params'] ?? []));

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function getBooksPage(
        string $query,
        int $limit,
        int $offset,
        string $sortField = 'title',
        string $sortDirection = 'asc',
        array $hiddenAuthors = [],
        array $hiddenTags = []
    ): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $query = trim($query);
        $orderByClause = $this->buildOrderByClause($sortField, $sortDirection);
        $visibilityFilter = $this->buildVisibilityFilter($hiddenAuthors, $hiddenTags);

        if ($query === '') {
            $sql = 'SELECT ' . $this->getBookListColumns() . '
                 FROM books';
            if ($visibilityFilter !== null) {
                $sql .= '
                 WHERE ' . $visibilityFilter['sql'];
            }
            $sql .= '
                 ORDER BY ' . $orderByClause . '
                 LIMIT :limit OFFSET :offset';
            $stmt = $this->pdo->prepare(
                $sql
            );
            foreach (($visibilityFilter['params'] ?? []) as $name => $value) {
                $stmt->bindValue($name, $value, \PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }

        $searchFilter = $this->buildSearchFilter($query);
        $whereSql = $searchFilter['sql'];
        if ($visibilityFilter !== null) {
            $whereSql = '(' . $whereSql . ') AND ' . $visibilityFilter['sql'];
        }
        $stmt = $this->pdo->prepare(
            'SELECT ' . $this->getBookListColumns() . '
             FROM books
             WHERE ' . $whereSql . '
             ORDER BY ' . $orderByClause . '
             LIMIT :limit OFFSET :offset'
        );
        foreach (array_merge($searchFilter['params'], $visibilityFilter['params'] ?? []) as $name => $value) {
            $stmt->bindValue($name, $value, \PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function countSearchResults(string $query, array $hiddenAuthors = [], array $hiddenTags = []): int
    {
        $query = trim($query);
        $visibilityFilter = $this->buildVisibilityFilter($hiddenAuthors, $hiddenTags);
        if ($query === '') {
            if ($visibilityFilter === null) {
                return $this->countBooks();
            }

            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*)
                 FROM books
                 WHERE ' . $visibilityFilter['sql']
            );
            $stmt->execute($visibilityFilter['params']);

            return (int) $stmt->fetchColumn();
        }

        $searchFilter = $this->buildSearchFilter($query);
        $whereSql = $searchFilter['sql'];
        if ($visibilityFilter !== null) {
            $whereSql = '(' . $whereSql . ') AND ' . $visibilityFilter['sql'];
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM books
             WHERE ' . $whereSql
        );
        $stmt->execute(array_merge($searchFilter['params'], $visibilityFilter['params'] ?? []));

        return (int) $stmt->fetchColumn();
    }

    public function countBooks(array $hiddenAuthors = [], array $hiddenTags = []): int
    {
        $visibilityFilter = $this->buildVisibilityFilter($hiddenAuthors, $hiddenTags);
        if ($visibilityFilter === null) {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM books')->fetchColumn();
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM books
             WHERE ' . $visibilityFilter['sql']
        );
        $stmt->execute($visibilityFilter['params']);

        return (int) $stmt->fetchColumn();
    }

    public function getBookById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . $this->getBookDetailColumns() . '
             FROM books
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function isBookVisible(int $id, array $hiddenAuthors = [], array $hiddenTags = []): bool
    {
        if ($id < 1) {
            return false;
        }

        $visibilityFilter = $this->buildVisibilityFilter($hiddenAuthors, $hiddenTags);
        $whereSql = 'id = :id';
        if ($visibilityFilter !== null) {
            $whereSql .= ' AND ' . $visibilityFilter['sql'];
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM books
             WHERE ' . $whereSql . '
             LIMIT 1'
        );
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        foreach (($visibilityFilter['params'] ?? []) as $name => $value) {
            $stmt->bindValue($name, $value, \PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    public function iterateCoverRegenerationCandidates(): \Generator
    {
        $stmt = $this->pdo->query(
            'SELECT id, title, author, path, cover_path, formats_json, metadata_json
             FROM books
             ORDER BY id ASC'
        );

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $formats = [];
            $formatsJson = (string) ($row['formats_json'] ?? '');
            if ($formatsJson !== '') {
                $decodedFormats = json_decode($formatsJson, true);
                if (is_array($decodedFormats)) {
                    $formats = $this->normalizeFormats($decodedFormats);
                }
            }

            $metadata = [];
            $metadataJson = (string) ($row['metadata_json'] ?? '');
            if ($metadataJson !== '') {
                $decodedMetadata = json_decode($metadataJson, true);
                if (is_array($decodedMetadata)) {
                    $metadata = $decodedMetadata;
                }
            }

            yield [
                'id' => (int) ($row['id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'author' => (string) ($row['author'] ?? ''),
                'path' => $this->normalizePath((string) ($row['path'] ?? '')),
                'cover_path' => $this->normalizePath(isset($row['cover_path']) ? (string) $row['cover_path'] : null),
                'formats' => $formats,
                'metadata' => $metadata,
            ];
        }
    }

    public function updateBookCoverPathById(int $id, ?string $coverPath): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE books
             SET cover_path = :cover_path
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':cover_path' => $this->normalizePath($coverPath),
        ]);

        if ($stmt->rowCount() > 0) {
            return true;
        }

        $existsStmt = $this->pdo->prepare('SELECT 1 FROM books WHERE id = :id LIMIT 1');
        $existsStmt->execute([':id' => $id]);

        return $existsStmt->fetchColumn() !== false;
    }

    public function getBooksByIds(array $ids): array
    {
        $orderedIds = [];
        foreach ($ids as $id) {
            $normalizedId = (int) $id;
            if ($normalizedId > 0) {
                $orderedIds[] = $normalizedId;
            }
        }

        if ($orderedIds === []) {
            return [];
        }

        $uniqueIds = array_values(array_unique($orderedIds));
        $placeholders = implode(', ', array_fill(0, count($uniqueIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT ' . $this->getBookDetailColumns() . '
             FROM books
             WHERE id IN (' . $placeholders . ')'
        );
        $stmt->execute($uniqueIds);

        $fetched = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $byId = [];
        foreach ($fetched as $row) {
            $bookId = (int) ($row['id'] ?? 0);
            if ($bookId > 0) {
                $byId[$bookId] = $row;
            }
        }

        $orderedBooks = [];
        foreach ($orderedIds as $id) {
            if (isset($byId[$id])) {
                $orderedBooks[] = $byId[$id];
            }
        }

        return $orderedBooks;
    }

    public function getBooksByTitleAndAuthor(string $title, string $author, ?int $excludeId = null, int $limit = 30): array
    {
        $normalizedTitle = trim($title);
        $normalizedAuthor = trim($author);
        if ($normalizedTitle === '' || $normalizedAuthor === '') {
            return [];
        }

        $normalizedLimit = max(1, min(200, $limit));
        $params = [
            ':title' => $normalizedTitle,
            ':author' => $normalizedAuthor,
            ':limit' => $normalizedLimit,
        ];

        $where = 'title = :title COLLATE NOCASE AND author = :author COLLATE NOCASE';
        if ($excludeId !== null && $excludeId > 0) {
            $where .= ' AND id != :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, title, author, path, formats_json
             FROM books
             WHERE ' . $where . '
             ORDER BY id ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':title', $normalizedTitle, \PDO::PARAM_STR);
        $stmt->bindValue(':author', $normalizedAuthor, \PDO::PARAM_STR);
        if (isset($params[':exclude_id'])) {
            $stmt->bindValue(':exclude_id', (int) $params[':exclude_id'], \PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $normalizedLimit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function getRecentBooksPage(int $limit, int $offset, array $hiddenAuthors = [], array $hiddenTags = []): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $visibilityFilter = $this->buildVisibilityFilter($hiddenAuthors, $hiddenTags);

        $sql = 'SELECT ' . $this->getBookListColumns() . '
             FROM books';
        if ($visibilityFilter !== null) {
            $sql .= '
             WHERE ' . $visibilityFilter['sql'];
        }
        $sql .= '
             ORDER BY COALESCE(NULLIF(library_timestamp, \'\'), created_at) DESC, id DESC
             LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach (($visibilityFilter['params'] ?? []) as $name => $paramValue) {
            $stmt->bindValue($name, $paramValue, \PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function iterateFacetValues(string $facet, array $hiddenAuthors = [], array $hiddenTags = []): \Generator
    {
        $column = match ($facet) {
            'author' => 'author',
            'tag' => 'tag',
            'series' => 'series',
            default => throw new \InvalidArgumentException('Unsupported facet: ' . $facet),
        };
        $visibilityFilter = $this->buildVisibilityFilter($hiddenAuthors, $hiddenTags);

        $sql = 'SELECT ' . $column . ' FROM books';
        if ($visibilityFilter !== null) {
            $sql .= ' WHERE ' . $visibilityFilter['sql'];
        }

        $stmt = $visibilityFilter === null
            ? $this->pdo->query($sql)
            : $this->pdo->prepare($sql);
        if ($stmt instanceof \PDOStatement && $visibilityFilter !== null) {
            $stmt->execute($visibilityFilter['params']);
        }
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            yield (string) ($row[$column] ?? '');
        }
    }

    public function countReadStatus(bool $isRead, array $hiddenAuthors = [], array $hiddenTags = []): int
    {
        $visibilityFilter = $this->buildVisibilityFilter($hiddenAuthors, $hiddenTags);
        $whereSql = 'is_read = :is_read';
        if ($visibilityFilter !== null) {
            $whereSql .= ' AND ' . $visibilityFilter['sql'];
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM books WHERE ' . $whereSql);
        $stmt->execute(array_merge([':is_read' => $isRead ? 1 : 0], $visibilityFilter['params'] ?? []));

        return (int) $stmt->fetchColumn();
    }

    public function getReadStatusBooksPage(bool $isRead, int $limit, int $offset, array $hiddenAuthors = [], array $hiddenTags = []): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $visibilityFilter = $this->buildVisibilityFilter($hiddenAuthors, $hiddenTags);
        $whereSql = 'is_read = :is_read';
        if ($visibilityFilter !== null) {
            $whereSql .= ' AND ' . $visibilityFilter['sql'];
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . $this->getBookListColumns() . '
             FROM books
             WHERE ' . $whereSql . '
             ORDER BY title COLLATE NOCASE ASC, id ASC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':is_read', $isRead ? 1 : 0, \PDO::PARAM_INT);
        foreach (($visibilityFilter['params'] ?? []) as $name => $paramValue) {
            $stmt->bindValue($name, $paramValue, \PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function countFacetBooks(string $facet, string $value, array $hiddenAuthors = [], array $hiddenTags = []): int
    {
        $facetFilter = $this->buildFacetFilter($facet, $value);
        $visibilityFilter = $this->buildVisibilityFilter($hiddenAuthors, $hiddenTags);
        $whereSql = $facetFilter['sql'];
        if ($visibilityFilter !== null) {
            $whereSql = '(' . $whereSql . ') AND ' . $visibilityFilter['sql'];
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM books WHERE ' . $whereSql);
        $stmt->execute(array_merge($facetFilter['params'], $visibilityFilter['params'] ?? []));

        return (int) $stmt->fetchColumn();
    }

    public function getFacetBooksPage(string $facet, string $value, int $limit, int $offset, array $hiddenAuthors = [], array $hiddenTags = []): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $facetFilter = $this->buildFacetFilter($facet, $value);
        $visibilityFilter = $this->buildVisibilityFilter($hiddenAuthors, $hiddenTags);
        $whereSql = $facetFilter['sql'];
        if ($visibilityFilter !== null) {
            $whereSql = '(' . $whereSql . ') AND ' . $visibilityFilter['sql'];
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . $this->getBookListColumns() . '
             FROM books
             WHERE ' . $whereSql . '
             ORDER BY title COLLATE NOCASE ASC, id ASC
             LIMIT :limit OFFSET :offset'
        );
        foreach (array_merge($facetFilter['params'], $visibilityFilter['params'] ?? []) as $name => $paramValue) {
            $stmt->bindValue($name, $paramValue, \PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function setReadStatus(int $id, bool $isRead): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE books
             SET is_read = :is_read
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':is_read' => $isRead ? 1 : 0,
        ]);

        if ($stmt->rowCount() > 0) {
            return true;
        }

        $existsStmt = $this->pdo->prepare('SELECT 1 FROM books WHERE id = :id LIMIT 1');
        $existsStmt->execute([':id' => $id]);

        return $existsStmt->fetchColumn() !== false;
    }

    public function getLastRebuildAt(): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM meta WHERE key = :key LIMIT 1');
        $stmt->execute([':key' => 'last_rebuild_at']);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function getAllCoverPaths(): array
    {
        $stmt = $this->pdo->query(
            'SELECT cover_path
             FROM books
             WHERE cover_path IS NOT NULL AND TRIM(cover_path) != ""'
        );

        $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if (!is_array($rows)) {
            return [];
        }

        $paths = [];
        foreach ($rows as $path) {
            $normalized = trim((string) $path);
            if ($normalized === '') {
                continue;
            }
            $paths[] = $normalized;
        }

        return array_values(array_unique($paths));
    }

    public function getAllBookPaths(): array
    {
        $stmt = $this->pdo->query(
            'SELECT path
             FROM books
             WHERE path IS NOT NULL AND TRIM(path) != ""'
        );

        $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if (!is_array($rows)) {
            return [];
        }

        $paths = [];
        foreach ($rows as $path) {
            $normalizedPath = $this->normalizePath((string) $path);
            if ($normalizedPath === null || trim($normalizedPath) === '') {
                continue;
            }

            $paths[] = $normalizedPath;
        }

        return array_values(array_unique($paths));
    }

    public function copyBooksByPathsFrom(string $sourceSqlitePath, array $paths, int $batchSize = 200): int
    {
        if (!is_file($sourceSqlitePath)) {
            return 0;
        }

        $normalizedBatchSize = max(1, $batchSize);
        $normalizedPaths = [];
        foreach ($paths as $path) {
            if (!is_string($path) || trim($path) === '') {
                continue;
            }
            $normalized = $this->normalizePath($path);
            if ($normalized === null || trim($normalized) === '') {
                continue;
            }
            $normalizedPaths[$normalized] = true;
        }

        if ($normalizedPaths === []) {
            return 0;
        }

        $source = new \PDO("sqlite:{$sourceSqlitePath}");
        $source->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $updateStmt = $this->pdo->prepare(
            'UPDATE books
             SET title = :title,
                 author = :author,
                 tag = :tag,
                 series = :series,
                 isbn = :isbn,
                 publisher = :publisher,
                 language = :language,
                 description = :description,
                 published_at = :published_at,
                 series_index = :series_index,
                 uuid = :uuid,
                 author_sort = :author_sort,
                 library_timestamp = :library_timestamp,
                 library_last_modified = :library_last_modified,
                 cover_path = :cover_path,
                 source_mtime = :source_mtime,
                 formats_json = :formats_json,
                 metadata_json = :metadata_json,
                 is_read = :is_read
             WHERE path = :path'
        );
        $insertStmt = $this->pdo->prepare(
            'INSERT INTO books
            (title, author, tag, series, isbn, publisher, language, description, published_at, series_index,
             uuid, author_sort, library_timestamp, library_last_modified, path, cover_path, source_mtime, formats_json, metadata_json, is_read, created_at)
            VALUES
            (:title, :author, :tag, :series, :isbn, :publisher, :language, :description, :published_at, :series_index,
             :uuid, :author_sort, :library_timestamp, :library_last_modified, :path, :cover_path, :source_mtime, :formats_json, :metadata_json, :is_read, :created_at)'
        );
        $existsStmt = $this->pdo->prepare(
            'SELECT 1
             FROM books
             WHERE path = :path
             LIMIT 1'
        );

        $copied = 0;
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $pathList = array_keys($normalizedPaths);
            foreach (array_chunk($pathList, $normalizedBatchSize) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $sourceStmt = $source->prepare(
                    'SELECT title, author, tag, series, isbn, publisher, language, description, published_at, series_index,
                            uuid, author_sort, library_timestamp, library_last_modified, path, cover_path, source_mtime, formats_json, metadata_json, is_read, created_at
                     FROM books
                     WHERE path IN (' . $placeholders . ')'
                );
                $sourceStmt->execute(array_values($chunk));

                foreach ($sourceStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                    $params = [
                        ':title' => (string) ($row['title'] ?? ''),
                        ':author' => (string) ($row['author'] ?? ''),
                        ':tag' => (string) ($row['tag'] ?? ''),
                        ':series' => (string) ($row['series'] ?? ''),
                        ':isbn' => (string) ($row['isbn'] ?? ''),
                        ':publisher' => (string) ($row['publisher'] ?? ''),
                        ':language' => (string) ($row['language'] ?? ''),
                        ':description' => (string) ($row['description'] ?? ''),
                        ':published_at' => (string) ($row['published_at'] ?? ''),
                        ':series_index' => isset($row['series_index']) && is_numeric((string) $row['series_index'])
                            ? (float) $row['series_index']
                            : null,
                        ':uuid' => (string) ($row['uuid'] ?? ''),
                        ':author_sort' => (string) ($row['author_sort'] ?? ''),
                        ':library_timestamp' => (string) ($row['library_timestamp'] ?? ''),
                        ':library_last_modified' => (string) ($row['library_last_modified'] ?? ''),
                        ':path' => $this->normalizePath((string) ($row['path'] ?? '')),
                        ':cover_path' => $this->normalizePath(isset($row['cover_path']) ? (string) $row['cover_path'] : null),
                        ':source_mtime' => isset($row['source_mtime']) && is_numeric((string) $row['source_mtime'])
                            ? (int) $row['source_mtime']
                            : null,
                        ':formats_json' => (string) ($row['formats_json'] ?? ''),
                        ':metadata_json' => (string) ($row['metadata_json'] ?? ''),
                        ':is_read' => isset($row['is_read']) && (int) $row['is_read'] === 1 ? 1 : 0,
                        ':created_at' => (string) ($row['created_at'] ?? date('Y-m-d H:i:s')),
                    ];

                    if ($params[':path'] === '') {
                        continue;
                    }

                    $this->upsertBookRecord($params, $updateStmt, $insertStmt, $existsStmt);
                    $this->upsertBookPathRecord(
                        $params[':path'],
                        $params[':title'],
                        $params[':author'],
                        $params[':source_mtime']
                    );
                    $this->synchronizeSearchIndexRecord(
                        $params[':path'],
                        $params[':title'],
                        $params[':author'],
                        (string) $params[':tag'],
                        (string) $params[':series'],
                        (string) $params[':isbn']
                    );
                    $copied++;
                }
            }

            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\Throwable) {
            }

            throw $e;
        }

        return $copied;
    }

    private function verifyLibrarySchema(): void
    {
        $this->assertTableExists('books');
        $this->assertTableExists('meta');
        $this->assertTableExists('book_paths');
        $this->detectSearchIndexSupport();
    }

    private function openConnection(): void
    {
        $this->ensureSqliteFilesystemPermissions();
        $runner = new MigrationRunner($this->appRoot);

        try {
            $this->pdo = new \PDO("sqlite:{$this->sqlitePath}");
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->ensureSqliteFilesystemPermissions();
            $runner->migrateLibrary($this->pdo);
        } catch (\Throwable $e) {
            if (!MigrationRunner::isChecksumMismatchException($e)) {
                throw $e;
            }

            unset($this->pdo);
            $backups = $runner->recoverVersionMismatch('library');
            $this->logVersionMismatchRecovery($this->sqlitePath, $backups, $e);
            $this->pdo = new \PDO("sqlite:{$this->sqlitePath}");
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->ensureSqliteFilesystemPermissions();
            (new MigrationRunner($this->appRoot))->migrateLibrary($this->pdo);
        }

        try {
            $this->verifyLibrarySchema();
        } catch (\RuntimeException $e) {
            if (strpos($e->getMessage(), 'Required library table missing after migration:') !== 0) {
                throw $e;
            }

            // Scan rebuilds use a temporary sqlite path. Migration history is
            // tracked globally for the "library" target, so a fresh temp DB can
            // appear schema-less even though migrations are already marked
            // applied. Replaying the idempotent library migrations locally
            // rebuilds the transient schema without mutating migration history.
            $runner->replayTargetSchema('library', $this->pdo);
            $this->verifyLibrarySchema();
        }

        $this->synchronizeSearchIndexContentIfNeeded();
        $this->ensureSqliteFilesystemPermissions();
    }

    private function ensureSqliteFilesystemPermissions(): void
    {
        $directory = dirname($this->sqlitePath);
        if (is_dir($directory)) {
            @chmod($directory, 0777);
        }

        if (!is_file($this->sqlitePath)) {
            @touch($this->sqlitePath);
        }

        foreach ([$this->sqlitePath, $this->sqlitePath . '-wal', $this->sqlitePath . '-shm'] as $path) {
            if (!is_file($path)) {
                continue;
            }

            @chmod($path, 0666);
            clearstatcache(true, $path);

            if (!is_writable($path)) {
                throw new \RuntimeException("SQLite file is not writable: {$path}");
            }
        }
    }

    private function detectSearchIndexSupport(): void
    {
        $this->ftsSearchAvailable = false;

        try {
            $exists = $this->pdo->query(
                "SELECT name FROM sqlite_master WHERE name = 'books_fts' LIMIT 1"
            )->fetchColumn();
            if ($exists === false) {
                return;
            }

            $this->pdo->query('SELECT COUNT(*) FROM books_fts')->fetchColumn();
            $this->ftsSearchAvailable = true;
        } catch (\Throwable) {
            $this->ftsSearchAvailable = false;
        }
    }

    private function synchronizeSearchIndexContentIfNeeded(): void
    {
        if (!$this->ftsSearchAvailable) {
            return;
        }

        try {
            $bookCount = (int) $this->pdo->query('SELECT COUNT(*) FROM books')->fetchColumn();
            $ftsCount = (int) $this->pdo->query('SELECT COUNT(*) FROM books_fts')->fetchColumn();
        } catch (\Throwable) {
            $this->ftsSearchAvailable = false;
            return;
        }

        if ($bookCount === $ftsCount) {
            return;
        }

        $this->rebuildSearchIndex();
    }

    private function rebuildSearchIndex(): void
    {
        if (!$this->ftsSearchAvailable) {
            return;
        }

        $this->pdo->exec('DELETE FROM books_fts');
        $stmt = $this->pdo->prepare(
            'INSERT INTO books_fts(path, title, author, tag, series, isbn)
             VALUES(:path, :title, :author, :tag, :series, :isbn)'
        );
        $source = $this->pdo->query('SELECT path, title, author, tag, series, isbn FROM books');
        foreach ($source->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $stmt->execute([
                ':path' => $this->normalizePath((string) ($row['path'] ?? '')) ?? '',
                ':title' => (string) ($row['title'] ?? ''),
                ':author' => (string) ($row['author'] ?? ''),
                ':tag' => (string) ($row['tag'] ?? ''),
                ':series' => (string) ($row['series'] ?? ''),
                ':isbn' => (string) ($row['isbn'] ?? ''),
            ]);
        }
    }

    private function synchronizeSearchIndexRecord(string $path, string $title, string $author, string $tag, string $series, string $isbn): void
    {
        if (!$this->ftsSearchAvailable || trim($path) === '') {
            return;
        }

        $deleteStmt = $this->pdo->prepare('DELETE FROM books_fts WHERE path = :path');
        $deleteStmt->execute([':path' => $path]);

        $insertStmt = $this->pdo->prepare(
            'INSERT INTO books_fts(path, title, author, tag, series, isbn)
             VALUES(:path, :title, :author, :tag, :series, :isbn)'
        );
        $insertStmt->execute([
            ':path' => $path,
            ':title' => $title,
            ':author' => $author,
            ':tag' => $tag,
            ':series' => $series,
            ':isbn' => $isbn,
        ]);
    }

    private function buildSearchFilter(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [
                'mode' => 'all',
                'sql' => '1 = 1',
                'params' => [],
            ];
        }

        $tokens = $this->tokenizeSearchExpression($query);
        $cursor = 0;
        $tree = $this->parseSearchExpression($tokens, $cursor);

        if ($tree === null || $cursor !== count($tokens)) {
            return $this->buildFallbackSearchFilter($query);
        }

        $params = [];
        $termIndex = 0;
        $sql = $this->compileSearchExpression($tree, $params, $termIndex);

        return [
            'mode' => 'sql',
            'sql' => $sql,
            'params' => $params,
        ];
    }

    private function buildFallbackSearchFilter(string $query): array
    {
        $placeholder = ':search_0';
        $normalizedQuery = $this->normalizeSearchTerm($query);

        return [
            'mode' => 'sql',
            'sql' => $this->buildFieldMatchClause($placeholder),
            'params' => [
                $placeholder => '%' . $normalizedQuery . '%',
            ],
        ];
    }

    private function buildOrderByClause(string $sortField, string $sortDirection): string
    {
        $addedAtExpression = "COALESCE(NULLIF(library_timestamp, ''), created_at)";
        $allowedFields = [
            'is_read' => 'is_read',
            'title' => 'title',
            'author' => 'author',
            'series' => 'series',
            'added_at' => $addedAtExpression,
        ];

        $column = $allowedFields[$sortField] ?? $addedAtExpression;
        $direction = strtolower($sortDirection) === 'desc' ? 'DESC' : 'ASC';
        $orderParts = [];

        if ($column === 'series') {
            $orderParts[] = "(CASE WHEN COALESCE(TRIM(series), '') = '' THEN 1 ELSE 0 END) ASC";
        }

        if ($column === 'is_read') {
            $orderParts[] = 'is_read ' . $direction;
        } elseif ($column === $addedAtExpression) {
            $orderParts[] = $addedAtExpression . ' ' . $direction;
        } else {
            $orderParts[] = $column . ' COLLATE NOCASE ' . $direction;
        }

        if ($column !== 'title') {
            $orderParts[] = 'title COLLATE NOCASE ASC';
        }

        $orderParts[] = 'id ASC';

        return implode(', ', $orderParts);
    }

    private function buildFieldMatchClause(string $placeholder): string
    {
        return '(title LIKE ' . $placeholder . ' COLLATE NOCASE'
            . ' OR author LIKE ' . $placeholder . ' COLLATE NOCASE'
            . ' OR tag LIKE ' . $placeholder . ' COLLATE NOCASE'
            . ' OR series LIKE ' . $placeholder . ' COLLATE NOCASE'
            . ' OR isbn LIKE ' . $placeholder . ' COLLATE NOCASE)';
    }

    private function buildVisibilityFilter(array $hiddenAuthors, array $hiddenTags): ?array
    {
        $clauses = [];
        $params = [];
        $counter = 0;

        foreach ($this->normalizeHiddenValues($hiddenAuthors) as $author) {
            $placeholder = ':hidden_author_' . $counter;
            $clauses[] = $this->buildFacetExclusionClause('author', $placeholder);
            $params[$placeholder] = '%,' . $this->normalizeFacetToken($author) . ',%';
            $counter++;
        }

        foreach ($this->normalizeHiddenValues($hiddenTags) as $tag) {
            $placeholder = ':hidden_tag_' . $counter;
            $clauses[] = $this->buildFacetExclusionClause('tag', $placeholder);
            $params[$placeholder] = '%,' . $this->normalizeFacetToken($tag) . ',%';
            $counter++;
        }

        if ($clauses === []) {
            return null;
        }

        return [
            'sql' => implode(' AND ', $clauses),
            'params' => $params,
        ];
    }

    private function buildFacetExclusionClause(string $column, string $placeholder): string
    {
        $normalizedColumn = "',' || REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(" . $column . ", ''), '，', ','), ', ', ','), ' ,', ','), ' , ', ',') || ','";

        return $normalizedColumn . ' NOT LIKE ' . $placeholder . ' COLLATE NOCASE';
    }

    private function normalizeHiddenValues(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $candidate = trim($value);
            if ($candidate === '' || in_array($candidate, $normalized, true)) {
                continue;
            }

            $normalized[] = $candidate;
        }

        return $normalized;
    }

    private function normalizeFacetToken(string $value): string
    {
        return str_replace(
            ['，', ', ', ' ,', ' , '],
            [',', ',', ',', ','],
            trim($value)
        );
    }

    private function tokenizeSearchExpression(string $query): array
    {
        $rawParts = preg_split('/(\|\||\(|\)|\+|-)/u', $query, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!is_array($rawParts)) {
            return [];
        }

        $tokens = [];
        foreach ($rawParts as $part) {
            $trimmed = trim($part);
            if ($trimmed === '') {
                continue;
            }

            if (in_array($trimmed, ['||', '(', ')', '+', '-'], true)) {
                $tokens[] = [
                    'type' => $trimmed,
                    'value' => $trimmed,
                ];
                continue;
            }

            $tokens[] = [
                'type' => 'TERM',
                'value' => $trimmed,
            ];
        }

        return $tokens;
    }

    private function parseSearchExpression(array $tokens, int &$cursor): ?array
    {
        return $this->parseSearchOrExpression($tokens, $cursor);
    }

    private function parseSearchOrExpression(array $tokens, int &$cursor): ?array
    {
        $node = $this->parseSearchAndExpression($tokens, $cursor);
        if ($node === null) {
            return null;
        }

        while ($cursor < count($tokens) && (($tokens[$cursor]['type'] ?? '') === '||')) {
            $cursor++;

            $right = $this->parseSearchAndExpression($tokens, $cursor);
            if ($right === null) {
                return null;
            }

            $node = [
                'type' => 'OR',
                'left' => $node,
                'right' => $right,
            ];
        }

        return $node;
    }

    private function parseSearchAndExpression(array $tokens, int &$cursor): ?array
    {
        $node = $this->parseSearchUnary($tokens, $cursor);
        if ($node === null) {
            return null;
        }

        while ($cursor < count($tokens)) {
            $tokenType = $tokens[$cursor]['type'] ?? '';
            $isImplicitAnd = $tokenType === 'TERM' || $tokenType === '(' || $tokenType === '-';

            if ($tokenType !== '+' && $tokenType !== '-' && !$isImplicitAnd) {
                break;
            }

            $operator = '+';
            if ($tokenType === '+' || $tokenType === '-') {
                $operator = $tokenType;
                $cursor++;
            }

            $right = $this->parseSearchUnary($tokens, $cursor);
            if ($right === null) {
                return null;
            }

            $node = [
                'type' => $operator === '-' ? 'AND_NOT' : 'AND',
                'left' => $node,
                'right' => $right,
            ];
        }

        return $node;
    }

    private function parseSearchUnary(array $tokens, int &$cursor): ?array
    {
        if ($cursor >= count($tokens)) {
            return null;
        }

        $tokenType = $tokens[$cursor]['type'] ?? '';
        if ($tokenType === '-') {
            $cursor++;
            $operand = $this->parseSearchUnary($tokens, $cursor);
            if ($operand === null) {
                return null;
            }

            return [
                'type' => 'NOT',
                'value' => $operand,
            ];
        }

        return $this->parseSearchPrimary($tokens, $cursor);
    }

    private function parseSearchPrimary(array $tokens, int &$cursor): ?array
    {
        if ($cursor >= count($tokens)) {
            return null;
        }

        $token = $tokens[$cursor];
        $tokenType = $token['type'] ?? '';

        if ($tokenType === 'TERM') {
            $cursor++;

            return [
                'type' => 'TERM',
                'value' => (string) ($token['value'] ?? ''),
            ];
        }

        if ($tokenType !== '(') {
            return null;
        }

        $cursor++;
        $group = $this->parseSearchExpression($tokens, $cursor);
        if ($group === null || $cursor >= count($tokens) || (($tokens[$cursor]['type'] ?? '') !== ')')) {
            return null;
        }

        $cursor++;
        return $group;
    }

    private function compileSearchExpression(array $node, array &$params, int &$termIndex): string
    {
        $nodeType = $node['type'] ?? '';

        if ($nodeType === 'TERM') {
            $placeholder = ':search_' . $termIndex++;
            $normalizedTerm = $this->normalizeSearchTerm((string) ($node['value'] ?? ''));
            $params[$placeholder] = '%' . $normalizedTerm . '%';

            $sql = $this->buildFieldMatchClause($placeholder);
            $formatClause = $this->buildFormatMatchClause($normalizedTerm, $params, $termIndex);
            if ($formatClause !== null) {
                return '(' . $sql . ' OR ' . $formatClause . ')';
            }

            return $sql;
        }

        if ($nodeType === 'NOT') {
            $operand = $this->compileSearchExpression((array) ($node['value'] ?? []), $params, $termIndex);
            return '(NOT ' . $operand . ')';
        }

        $left = $this->compileSearchExpression((array) ($node['left'] ?? []), $params, $termIndex);
        $right = $this->compileSearchExpression((array) ($node['right'] ?? []), $params, $termIndex);

        if ($nodeType === 'AND_NOT') {
            return '(' . $left . ' AND NOT ' . $right . ')';
        }

        if ($nodeType === 'OR') {
            return '(' . $left . ' OR ' . $right . ')';
        }

        return '(' . $left . ' AND ' . $right . ')';
    }

    private function normalizeSearchTerm(string $term): string
    {
        $normalized = trim(mb_strtolower($term, 'UTF-8'));
        if ($normalized === 'pdb') {
            return 'pdf';
        }

        return $normalized;
    }

    private function buildFormatMatchClause(string $normalizedTerm, array &$params, int &$termIndex): ?string
    {
        if (!in_array($normalizedTerm, $this->searchableFormatTerms, true)) {
            return null;
        }

        $formatPlaceholder = ':search_format_' . $termIndex++;
        $params[$formatPlaceholder] = '"' . $normalizedTerm . '":';

        return '(INSTR(LOWER(COALESCE(formats_json, \'\')), ' . $formatPlaceholder . ') > 0)';
    }

    private function setMeta(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO meta(key, value) VALUES(:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute([
            ':key' => $key,
            ':value' => $value,
        ]);
    }

    private function toString($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            $value = implode(', ', $value);
        }

        return trim((string) $value);
    }

    private function toFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function getBookListColumns(): string
    {
        return 'id, title, author, tag, series, isbn, publisher, language, description, published_at, series_index,
            uuid, author_sort, library_timestamp, library_last_modified, path, cover_path, is_read, created_at';
    }

    private function getBookDetailColumns(): string
    {
        return $this->getBookListColumns() . ', formats_json, metadata_json';
    }

    private function normalizePath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return $path;
        }

        return str_replace('\\', '/', $path);
    }

    private function normalizeFormats(array $formats): array
    {
        $normalized = [];
        foreach ($formats as $format => $path) {
            $normalized[$format] = is_string($path) ? $this->normalizePath($path) : $path;
        }

        return $normalized;
    }

    private function getReadStatesByPath(): array
    {
        $states = [];
        $stmt = $this->pdo->query('SELECT path, is_read FROM books WHERE is_read = 1');

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $path = isset($row['path']) ? $this->normalizePath((string) $row['path']) : null;
            if ($path === null || $path === '') {
                continue;
            }

            $states[$path] = true;
        }

        return $states;
    }

    private function rebuildWithReadStates(iterable $books, array $readStates, int $batchSize, ?callable $onBatchCommitted = null): int
    {
        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $this->pdo->exec('DELETE FROM books');
            $this->pdo->exec('DELETE FROM book_paths');
            if ($this->ftsSearchAvailable) {
                $this->pdo->exec('DELETE FROM books_fts');
            }
            $count = $this->upsertBooksInTransaction($books, $readStates, $batchSize, $onBatchCommitted);

            $this->setMeta('last_rebuild_at', date('c'));

            $this->pdo->exec('COMMIT');

            return $count;
        } catch (\Throwable $e) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\Throwable) {
            }

            throw $e;
        }
    }

    private function appendWithReadStates(iterable $books, array $readStates, int $batchSize, ?callable $onBatchCommitted = null): int
    {
        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $count = $this->upsertBooksInTransaction($books, $readStates, $batchSize, $onBatchCommitted);
            $this->setMeta('last_rebuild_at', date('c'));
            $this->pdo->exec('COMMIT');

            return $count;
        } catch (\Throwable $e) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\Throwable) {
            }

            throw $e;
        }
    }

    private function upsertBooksInTransaction(iterable $books, array $readStates, int $batchSize, ?callable $onBatchCommitted = null): int
    {
        $insertStmt = $this->pdo->prepare(
            'INSERT INTO books
            (title, author, tag, series, isbn, publisher, language, description, published_at, series_index,
             uuid, author_sort, library_timestamp, library_last_modified, path, cover_path, source_mtime, formats_json, metadata_json, is_read)
            VALUES
            (:title, :author, :tag, :series, :isbn, :publisher, :language, :description, :published_at, :series_index,
             :uuid, :author_sort, :library_timestamp, :library_last_modified, :path, :cover_path, :source_mtime, :formats_json, :metadata_json, :is_read)'
        );
        $updateStmt = $this->pdo->prepare(
            'UPDATE books
             SET title = :title,
                 author = :author,
                 tag = :tag,
                 series = :series,
                 isbn = :isbn,
                 publisher = :publisher,
                 language = :language,
                 description = :description,
                 published_at = :published_at,
                 series_index = :series_index,
                 uuid = :uuid,
                 author_sort = :author_sort,
                 library_timestamp = :library_timestamp,
                 library_last_modified = :library_last_modified,
                 cover_path = :cover_path,
                 source_mtime = :source_mtime,
                 formats_json = :formats_json,
                 metadata_json = :metadata_json,
                 is_read = :is_read
             WHERE path = :path'
        );
        $existsStmt = $this->pdo->prepare(
            'SELECT 1
             FROM books
             WHERE path = :path
             LIMIT 1'
        );

        $count = 0;
        $pendingInBatch = 0;
        foreach ($books as $book) {
            if (!$book instanceof Book) {
                continue;
            }

            $metadata = $book->getMetadata();
            $normalizedFormats = $this->normalizeFormats($book->getFormats());
            $normalizedPath = $this->normalizePath($book->getPath());
            $baseReadState = isset($readStates[$normalizedPath ?? '']);

            $formatEntries = [];
            foreach ($normalizedFormats as $format => $formatPath) {
                if (!is_string($formatPath) || trim($formatPath) === '') {
                    continue;
                }

                $normalizedFormatPath = $this->normalizePath($formatPath);
                if ($normalizedFormatPath === null || $normalizedFormatPath === '') {
                    continue;
                }

                $formatKey = strtolower(trim((string) $format));
                if ($formatKey === '') {
                    $formatKey = strtolower((string) pathinfo($normalizedFormatPath, PATHINFO_EXTENSION));
                }
                if ($formatKey === '') {
                    $formatKey = 'file';
                }

                $formatEntries[$normalizedFormatPath] = [
                    $formatKey => $normalizedFormatPath,
                ];
            }

            if ($formatEntries === [] && $normalizedPath !== null && $normalizedPath !== '') {
                $fallbackFormat = strtolower((string) pathinfo($normalizedPath, PATHINFO_EXTENSION));
                if ($fallbackFormat === '') {
                    $fallbackFormat = 'file';
                }

                $formatEntries[$normalizedPath] = [
                    $fallbackFormat => $normalizedPath,
                ];
            }

            foreach ($formatEntries as $entryPath => $entryFormats) {
                $entryFormat = (string) key($entryFormats);
                $entryMetadata = $metadata;
                $entryMetadata['format'] = $entryFormat;

                $isRead = $baseReadState || isset($readStates[$entryPath]);
                $params = [
                    ':title' => $book->getTitle(),
                    ':author' => $book->getAuthor(),
                    ':tag' => $this->toString($metadata['tag'] ?? ''),
                    ':series' => $this->toString($metadata['series'] ?? ''),
                    ':isbn' => $this->toString($metadata['isbn'] ?? ''),
                    ':publisher' => $this->toString($metadata['publisher'] ?? ''),
                    ':language' => $this->toString($metadata['language'] ?? ''),
                    ':description' => $this->toString($metadata['description'] ?? ''),
                    ':published_at' => $this->toString($metadata['published_at'] ?? ($metadata['pubdate'] ?? '')),
                    ':series_index' => $this->toFloat($metadata['series_index'] ?? null),
                    ':uuid' => $this->toString($metadata['uuid'] ?? ''),
                    ':author_sort' => $this->toString($metadata['author_sort'] ?? ''),
                    ':library_timestamp' => $this->toString($metadata['library_timestamp'] ?? ''),
                    ':library_last_modified' => $this->toString($metadata['library_last_modified'] ?? ''),
                    ':path' => $entryPath,
                    ':cover_path' => $this->normalizePath($book->getCoverPath()),
                    ':source_mtime' => isset($metadata['source_mtime']) && is_numeric($metadata['source_mtime'])
                        ? (int) $metadata['source_mtime']
                        : null,
                    ':formats_json' => json_encode($entryFormats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':metadata_json' => json_encode($entryMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':is_read' => $isRead ? 1 : 0,
                ];

                $this->upsertBookRecord($params, $updateStmt, $insertStmt, $existsStmt);
                $this->upsertBookPathRecord(
                    $params[':path'],
                    $params[':title'],
                    $params[':author'],
                    $params[':source_mtime']
                );
                $this->synchronizeSearchIndexRecord(
                    $params[':path'],
                    $params[':title'],
                    $params[':author'],
                    (string) $params[':tag'],
                    (string) $params[':series'],
                    (string) $params[':isbn']
                );

                $count++;
                $pendingInBatch++;

                if ($pendingInBatch >= $batchSize) {
                    $this->pdo->exec('COMMIT');
                    $this->pdo->exec('BEGIN IMMEDIATE');
                    $pendingInBatch = 0;
                    if ($onBatchCommitted !== null) {
                        $onBatchCommitted($count);
                    }
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }
            }
        }

        if ($onBatchCommitted !== null) {
            $onBatchCommitted($count);
        }

        return $count;
    }

    private function upsertBookRecord(array $params, \PDOStatement $updateStmt, \PDOStatement $insertStmt, \PDOStatement $existsStmt): void
    {
        $updateStmt->execute($params);
        if ($updateStmt->rowCount() === 0) {
            $existsStmt->execute([
                ':path' => $params[':path'],
            ]);

            if ($existsStmt->fetchColumn() === false) {
                $insertStmt->execute($params);
            }
        }
    }

    private function upsertBookPathRecord(string $path, string $title, string $author, ?int $sourceMtime): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO book_paths(path, title, author, source_mtime, updated_at)
             VALUES(:path, :title, :author, :source_mtime, CURRENT_TIMESTAMP)
             ON CONFLICT(path) DO UPDATE SET
                title = excluded.title,
                author = excluded.author,
                source_mtime = excluded.source_mtime,
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            ':path' => $path,
            ':title' => $title,
            ':author' => $author,
            ':source_mtime' => $sourceMtime,
        ]);
    }

    private function recreateDatabase(): void
    {
        $this->close();

        $timestamp = gmdate('YmdHis');
        foreach ([$this->sqlitePath, $this->sqlitePath . '-wal', $this->sqlitePath . '-shm'] as $path) {
            if (!is_file($path)) {
                continue;
            }

            $backupPath = $path . '.corrupt-' . $timestamp;
            if (@rename($path, $backupPath)) {
                continue;
            }

            if (!@unlink($path) && is_file($path)) {
                throw new \RuntimeException("Cannot reset corrupted sqlite file: {$path}");
            }
        }

        clearstatcache();
        $this->openConnection();
    }

    private function isCorruptedDatabaseError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'database disk image is malformed')
            || str_contains($message, 'file is not a database');
    }

    private function normalizeReadStateMap(array $readStatesByPath): array
    {
        $normalizedStates = [];

        foreach ($readStatesByPath as $path => $isRead) {
            if (!$isRead || !is_string($path) || trim($path) === '') {
                continue;
            }

            $normalizedPath = $this->normalizePath($path);
            if ($normalizedPath === null || $normalizedPath === '') {
                continue;
            }

            $normalizedStates[$normalizedPath] = true;
        }

        return $normalizedStates;
    }

    private function assertTableExists(string $tableName): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tableName)) {
            throw new \RuntimeException('Unsafe table name check: ' . $tableName);
        }

        $stmt = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = '" . $tableName . "' LIMIT 1"
        );
        if ($stmt->fetchColumn() === false) {
            throw new \RuntimeException('Required library table missing after migration: ' . $tableName);
        }
    }

    /**
     * @param array{target_backup:?string} $backups
     */
    private function logVersionMismatchRecovery(string $dbPath, array $backups, \Throwable $e): void
    {
        error_log(sprintf(
            '[bookslib][migration-recovery] checksum mismatch detected. target=library source=%s backup=%s error=%s',
            $dbPath,
            (string) ($backups['target_backup'] ?? ''),
            $e->getMessage()
        ));
    }

    private function buildFacetFilter(string $facet, string $value): array
    {
        $normalizedValue = trim($value);
        if ($normalizedValue === '') {
            return [
                'sql' => '1 = 0',
                'params' => [],
            ];
        }

        $needle = function_exists('mb_strtolower')
            ? mb_strtolower($normalizedValue, 'UTF-8')
            : strtolower($normalizedValue);

        if ($facet === 'series') {
            return [
                'sql' => 'LOWER(TRIM(COALESCE(series, \'\'))) = :needle',
                'params' => [':needle' => $needle],
            ];
        }

        $column = match ($facet) {
            'author' => 'author',
            'tag' => 'tag',
            default => throw new \InvalidArgumentException('Unsupported facet: ' . $facet),
        };

        return [
            'sql' => '\',\' || REPLACE(LOWER(COALESCE(' . $column . ', \'\')), \', \', \',\') || \',\' LIKE :needle',
            'params' => [':needle' => '%,' . $needle . ',%'],
        ];
    }
}
