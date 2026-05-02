<?php

namespace Calibre\Database;

use Calibre\ScanService;

final class MigrationRunner
{
    private const CHECKSUM_MISMATCH_PREFIX = 'Migration checksum mismatch for ';

    private string $appRoot;
    private string $migrationDbPath;
    private ?\PDO $migrationPdo = null;

    public function __construct(string $appRoot, ?string $migrationDbPath = null)
    {
        $this->appRoot = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->migrationDbPath = $migrationDbPath ?? $this->resolveMigrationDbPath();
    }

    public function migrateAuth(\PDO $targetPdo): void
    {
        $this->applyMigrations('auth', $targetPdo, $this->authMigrations());
    }

    public function migrateLibrary(\PDO $targetPdo): void
    {
        $this->applyMigrations('library', $targetPdo, $this->libraryMigrations());
    }

    public function runTarget(string $targetDb, \PDO $targetPdo): void
    {
        $normalized = $this->normalizeTargetDb($targetDb);
        if ($normalized === 'auth') {
            $this->migrateAuth($targetPdo);
            return;
        }

        $this->migrateLibrary($targetPdo);
    }

    public function replayTargetSchema(string $targetDb, \PDO $targetPdo): void
    {
        foreach ($this->getMigrationsForTarget($targetDb) as $migration) {
            ($migration['up'])($targetPdo);
        }
    }

    public function getMigrationDbPath(): string
    {
        return $this->migrationDbPath;
    }

    public function resetTargetMigrationRecords(string $targetDb): void
    {
        $this->clearTargetMigrationRecords($targetDb);
    }

    public static function isChecksumMismatchException(\Throwable $e): bool
    {
        return str_starts_with($e->getMessage(), self::CHECKSUM_MISMATCH_PREFIX);
    }

    /**
     * @return array{target_backup:?string}
     */
    public function recoverVersionMismatch(string $failedTargetDb): array
    {
        $failedTarget = $this->normalizeTargetDb($failedTargetDb);
        $failedTargetPath = $this->resolveTargetDbPath($failedTarget);
        $this->migrationPdo = null;
        $targetBackup = $this->backupSqliteArtifacts($failedTargetPath, date('YmdHis'));
        $this->clearTargetMigrationRecords($failedTarget);
        $this->recreateSqliteDatabaseFile($failedTargetPath);

        return [
            'target_backup' => $targetBackup,
        ];
    }

    /**
     * @return array<int, array{target_db:string,migration:string,checksum:string,batch:int,applied_at:string,status:string}>
     */
    public function getStatusRows(?string $targetDb = null): array
    {
        $targets = $targetDb === null ? ['auth', 'library'] : [$this->normalizeTargetDb($targetDb)];
        $appliedByTarget = [];
        foreach ($targets as $target) {
            $appliedByTarget[$target] = $this->getAppliedMigrations($target);
        }

        $rows = [];
        foreach ($targets as $target) {
            foreach ($this->getMigrationsForTarget($target) as $migration) {
                $name = $migration['name'];
                $checksum = sha1($migration['signature']);
                $appliedMeta = $this->getAppliedMigrationMeta($target, $name);
                $status = 'pending';
                if (isset($appliedByTarget[$target][$name])) {
                    $status = $appliedByTarget[$target][$name] === $checksum ? 'applied' : 'mismatch';
                }
                $rows[] = [
                    'target_db' => $target,
                    'migration' => $name,
                    'checksum' => $checksum,
                    'batch' => (int) ($appliedMeta['batch'] ?? 0),
                    'applied_at' => (string) ($appliedMeta['applied_at'] ?? ''),
                    'status' => $status,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array{target_db:string,migration:string,error_message:string,failed_at:string}>
     */
    public function getFailureRows(?string $targetDb = null, int $limit = 100): array
    {
        $normalizedLimit = max(1, min(1000, $limit));
        $pdo = $this->getMigrationPdo();

        if ($targetDb === null) {
            $stmt = $pdo->prepare(
                'SELECT target_db, migration, error_message, failed_at
                 FROM migration_failures
                 ORDER BY id DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':limit', $normalizedLimit, \PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare(
                'SELECT target_db, migration, error_message, failed_at
                 FROM migration_failures
                 WHERE target_db = :target_db
                 ORDER BY id DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':target_db', $this->normalizeTargetDb($targetDb), \PDO::PARAM_STR);
            $stmt->bindValue(':limit', $normalizedLimit, \PDO::PARAM_INT);
            $stmt->execute();
        }

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function resolveMigrationDbPath(): string
    {
        $config = ScanService::loadConfig($this->appRoot);
        $value = ScanService::readSetting(
            $config,
            ['BOOKS_MIGRATIONS_DB_PATH', 'MIGRATIONS_DB_PATH'],
            'MIGRATIONS_DB_PATH',
            'data/migrations.sqlite'
        );

        return ScanService::resolvePath($this->appRoot, trim((string) $value));
    }

    private function resolveTargetDbPath(string $targetDb): string
    {
        $config = ScanService::loadConfig($this->appRoot);

        return match ($this->normalizeTargetDb($targetDb)) {
            'auth' => ScanService::resolvePath(
                $this->appRoot,
                trim((string) ScanService::readSetting(
                    $config,
                    ['BOOKS_AUTH_SETTINGS_DB_PATH', 'AUTH_SETTINGS_DB_PATH'],
                    'AUTH_SETTINGS_DB_PATH',
                    'data/auth_settings.sqlite'
                ))
            ),
            'library' => ScanService::resolvePath(
                $this->appRoot,
                trim((string) ScanService::readSetting(
                    $config,
                    ['BOOKS_SQLITE_INDEX_PATH', 'SQLITE_INDEX_PATH'],
                    'SQLITE_INDEX_PATH',
                    'data/library_index.sqlite'
                ))
            ),
        };
    }

    private function getMigrationPdo(): \PDO
    {
        if ($this->migrationPdo instanceof \PDO) {
            return $this->migrationPdo;
        }

        $directory = dirname($this->migrationDbPath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create migration DB directory: {$directory}");
        }

        $this->migrationPdo = new \PDO('sqlite:' . $this->migrationDbPath);
        $this->migrationPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->ensureMigrationSchema($this->migrationPdo);

        return $this->migrationPdo;
    }

    private function ensureMigrationSchema(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                target_db TEXT NOT NULL,
                migration TEXT NOT NULL,
                checksum TEXT NOT NULL DEFAULT "",
                batch INTEGER NOT NULL DEFAULT 1,
                applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(target_db, migration)
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS migration_failures (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                target_db TEXT NOT NULL,
                migration TEXT NOT NULL,
                error_message TEXT NOT NULL,
                failed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    /**
     * @param array<int, array{name:string, signature:string, up:callable(\PDO):void}> $migrations
     */
    private function applyMigrations(string $targetDb, \PDO $targetPdo, array $migrations): void
    {
        $migrationPdo = $this->getMigrationPdo();
        $applied = $this->getAppliedMigrations($targetDb);
        $pending = [];

        foreach ($migrations as $migration) {
            $checksum = sha1($migration['signature']);
            $name = $migration['name'];

            if (isset($applied[$name])) {
                if ($applied[$name] !== $checksum) {
                    throw new \RuntimeException(self::CHECKSUM_MISMATCH_PREFIX . "{$targetDb}: {$name}");
                }
                continue;
            }

            $pending[] = [
                'name' => $name,
                'checksum' => $checksum,
                'up' => $migration['up'],
            ];
        }

        if ($pending === []) {
            return;
        }

        $batch = $this->nextBatchNumber($migrationPdo);
        $timestampCursor = new \DateTimeImmutable('now');
        foreach ($pending as $migration) {
            $name = $migration['name'];
            try {
                if (!$targetPdo->inTransaction()) {
                    $targetPdo->beginTransaction();
                }

                ($migration['up'])($targetPdo);

                if ($targetPdo->inTransaction()) {
                    $targetPdo->commit();
                }

                $insert = $migrationPdo->prepare(
                    'INSERT INTO schema_migrations(target_db, migration, checksum, batch, applied_at)
                     VALUES(:target_db, :migration, :checksum, :batch, :applied_at)'
                );
                $insert->execute([
                    ':target_db' => $targetDb,
                    ':migration' => $name,
                    ':checksum' => $migration['checksum'],
                    ':batch' => $batch,
                    ':applied_at' => $this->consumeMigrationTimestamp($timestampCursor),
                ]);
            } catch (\Throwable $e) {
                if ($targetPdo->inTransaction()) {
                    try {
                        $targetPdo->rollBack();
                    } catch (\Throwable) {
                    }
                }

                $failure = $migrationPdo->prepare(
                    'INSERT INTO migration_failures(target_db, migration, error_message, failed_at)
                     VALUES(:target_db, :migration, :error_message, :failed_at)'
                );
                $failure->execute([
                    ':target_db' => $targetDb,
                    ':migration' => $name,
                    ':error_message' => $e->getMessage(),
                    ':failed_at' => $this->consumeMigrationTimestamp($timestampCursor),
                ]);

                throw $e;
            }
        }
    }

    private function consumeMigrationTimestamp(\DateTimeImmutable &$timestampCursor): string
    {
        $current = $timestampCursor->format('c');
        $timestampCursor = $timestampCursor->modify('+1 second');

        return $current;
    }

    /**
     * @return array<string, string>
     */
    private function getAppliedMigrations(string $targetDb): array
    {
        $stmt = $this->getMigrationPdo()->prepare(
            'SELECT migration, checksum
             FROM schema_migrations
             WHERE target_db = :target_db'
        );
        $stmt->execute([':target_db' => $targetDb]);

        $applied = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $name = (string) ($row['migration'] ?? '');
            if ($name === '') {
                continue;
            }
            $applied[$name] = (string) ($row['checksum'] ?? '');
        }

        return $applied;
    }

    private function nextBatchNumber(\PDO $pdo): int
    {
        $value = $pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM schema_migrations')->fetchColumn();

        return max(1, (int) $value);
    }

    /**
     * @return array{name:string,signature:string,up:callable(\PDO):void}[]
     */
    private function getMigrationsForTarget(string $targetDb): array
    {
        return match ($this->normalizeTargetDb($targetDb)) {
            'auth' => $this->authMigrations(),
            'library' => $this->libraryMigrations(),
        };
    }

    /**
     * @return array{batch?:int,applied_at?:string}
     */
    private function getAppliedMigrationMeta(string $targetDb, string $migration): array
    {
        $stmt = $this->getMigrationPdo()->prepare(
            'SELECT batch, applied_at
             FROM schema_migrations
             WHERE target_db = :target_db AND migration = :migration
             LIMIT 1'
        );
        $stmt->execute([
            ':target_db' => $targetDb,
            ':migration' => $migration,
        ]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /**
     * @return array<int, array{name:string, signature:string, up:callable(\PDO):void}>
     */
    private function authMigrations(): array
    {
        return [
            [
                'name' => '202604290001_create_users_table',
                'signature' => 'create_users_table_v1',
                'up' => function (\PDO $pdo): void {
                    $pdo->exec(
                        'CREATE TABLE IF NOT EXISTS users (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            username TEXT NOT NULL UNIQUE,
                            email TEXT NOT NULL DEFAULT "",
                            password_hash TEXT NOT NULL,
                            role TEXT NOT NULL DEFAULT "user",
                            is_enabled INTEGER NOT NULL DEFAULT 1,
                            api_token TEXT NOT NULL UNIQUE,
                            ui_theme TEXT NOT NULL DEFAULT "light",
                            ui_locale TEXT NOT NULL DEFAULT "zhTW",
                            ui_sort_field TEXT NOT NULL DEFAULT "added_at",
                            ui_sort_direction TEXT NOT NULL DEFAULT "desc",
                            is_default INTEGER NOT NULL DEFAULT 0,
                            hidden_authors TEXT NOT NULL DEFAULT "[]",
                            hidden_tags TEXT NOT NULL DEFAULT "[]",
                            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                        )'
                    );
                },
            ],
            [
                'name' => '202604290002_create_app_settings_table',
                'signature' => 'create_app_settings_table_v1',
                'up' => function (\PDO $pdo): void {
                    $pdo->exec(
                        'CREATE TABLE IF NOT EXISTS app_settings (
                            setting_key TEXT PRIMARY KEY,
                            setting_value TEXT NOT NULL DEFAULT "",
                            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                        )'
                    );
                },
            ],
            [
                'name' => '202604290003_create_scan_jobs_table',
                'signature' => 'create_scan_jobs_table_v1',
                'up' => function (\PDO $pdo): void {
                    $pdo->exec(
                        'CREATE TABLE IF NOT EXISTS scan_jobs (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            action TEXT NOT NULL,
                            run_at TEXT NOT NULL,
                            source TEXT NOT NULL,
                            status TEXT NOT NULL,
                            created_at TEXT NOT NULL,
                            started_at TEXT,
                            finished_at TEXT,
                            payload TEXT
                        )'
                    );
                },
            ],
            [
                'name' => '202604290004_ensure_users_identity_columns',
                'signature' => 'users_identity_columns_v1',
                'up' => function (\PDO $pdo): void {
                    $this->ensureColumn($pdo, 'users', 'email', 'TEXT NOT NULL DEFAULT ""');
                    $this->ensureColumn($pdo, 'users', 'role', 'TEXT NOT NULL DEFAULT "user"');
                    $this->ensureColumn($pdo, 'users', 'is_enabled', 'INTEGER NOT NULL DEFAULT 1');
                    $this->ensureColumn($pdo, 'users', 'is_default', 'INTEGER NOT NULL DEFAULT 0');
                },
            ],
            [
                'name' => '202604290005_ensure_users_ui_columns',
                'signature' => 'users_ui_columns_v1',
                'up' => function (\PDO $pdo): void {
                    $this->ensureColumn($pdo, 'users', 'ui_theme', 'TEXT NOT NULL DEFAULT "light"');
                    $this->ensureColumn($pdo, 'users', 'ui_locale', 'TEXT NOT NULL DEFAULT "zhTW"');
                    $this->ensureColumn($pdo, 'users', 'ui_sort_field', 'TEXT NOT NULL DEFAULT "added_at"');
                    $this->ensureColumn($pdo, 'users', 'ui_sort_direction', 'TEXT NOT NULL DEFAULT "desc"');
                },
            ],
            [
                'name' => '202604290006_ensure_users_hidden_filters',
                'signature' => 'users_hidden_filters_v1',
                'up' => function (\PDO $pdo): void {
                    $this->ensureColumn($pdo, 'users', 'hidden_authors', 'TEXT NOT NULL DEFAULT "[]"');
                    $this->ensureColumn($pdo, 'users', 'hidden_tags', 'TEXT NOT NULL DEFAULT "[]"');
                },
            ],
            [
                'name' => '202604290007_ensure_scan_jobs_payload_and_indexes',
                'signature' => 'scan_jobs_payload_indexes_v1',
                'up' => function (\PDO $pdo): void {
                    $this->ensureColumn($pdo, 'scan_jobs', 'payload', 'TEXT');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scan_jobs_status_run_at ON scan_jobs(status, run_at)');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scan_jobs_source_status ON scan_jobs(source, status)');
                },
            ],
            [
                'name' => '202604290008_normalize_auth_defaults',
                'signature' => 'normalize_auth_defaults_v1',
                'up' => function (\PDO $pdo): void {
                    $pdo->exec("UPDATE users SET role = 'user' WHERE role IS NULL OR TRIM(role) = ''");
                    $pdo->exec("UPDATE users SET is_enabled = 1 WHERE is_enabled IS NULL");
                    $pdo->exec("UPDATE users SET ui_locale = 'zhTW' WHERE ui_locale IS NULL OR TRIM(ui_locale) = ''");
                    $pdo->exec("UPDATE users SET hidden_authors = '[]' WHERE hidden_authors IS NULL OR TRIM(hidden_authors) = ''");
                    $pdo->exec("UPDATE users SET hidden_tags = '[]' WHERE hidden_tags IS NULL OR TRIM(hidden_tags) = ''");
                    $pdo->exec("UPDATE users SET role = 'admin' WHERE is_default = 1");
                },
            ],
            [
                'name' => '202605020001_add_ui_theme_updated_at',
                'signature' => 'add_ui_theme_updated_at_v1',
                'up' => function (\PDO $pdo): void {
                    $this->ensureColumn($pdo, 'users', 'ui_theme_updated_at', 'TEXT');
                    $pdo->exec('UPDATE users SET ui_theme_updated_at = COALESCE(NULLIF(ui_theme_updated_at, ""), updated_at, CURRENT_TIMESTAMP)');
                },
            ],
        ];
    }

    /**
     * @return array<int, array{name:string, signature:string, up:callable(\PDO):void}>
     */
    private function libraryMigrations(): array
    {
        return [
            [
                'name' => '202604290101_create_books_table',
                'signature' => 'create_books_table_v1',
                'up' => function (\PDO $pdo): void {
                    $pdo->exec(
                        'CREATE TABLE IF NOT EXISTS books (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            title TEXT NOT NULL,
                            author TEXT NOT NULL,
                            tag TEXT,
                            series TEXT,
                            isbn TEXT,
                            publisher TEXT,
                            language TEXT,
                            description TEXT,
                            published_at TEXT,
                            series_index REAL,
                            uuid TEXT,
                            author_sort TEXT,
                            library_timestamp TEXT,
                            library_last_modified TEXT,
                            path TEXT NOT NULL,
                            cover_path TEXT,
                            source_mtime INTEGER,
                            formats_json TEXT,
                            metadata_json TEXT,
                            is_read INTEGER NOT NULL DEFAULT 0,
                            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                        )'
                    );
                },
            ],
            [
                'name' => '202604290102_create_meta_table',
                'signature' => 'create_meta_table_v1',
                'up' => function (\PDO $pdo): void {
                    $pdo->exec(
                        'CREATE TABLE IF NOT EXISTS meta (
                            key TEXT PRIMARY KEY,
                            value TEXT
                        )'
                    );
                },
            ],
            [
                'name' => '202604290103_create_book_paths_table',
                'signature' => 'create_book_paths_table_v1',
                'up' => function (\PDO $pdo): void {
                    $pdo->exec(
                        'CREATE TABLE IF NOT EXISTS book_paths (
                            path TEXT PRIMARY KEY,
                            title TEXT NOT NULL,
                            author TEXT NOT NULL,
                            source_mtime INTEGER,
                            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                        )'
                    );
                },
            ],
            [
                'name' => '202604290104_ensure_books_metadata_columns',
                'signature' => 'books_metadata_columns_v1',
                'up' => function (\PDO $pdo): void {
                    $this->ensureColumn($pdo, 'books', 'publisher', 'TEXT');
                    $this->ensureColumn($pdo, 'books', 'language', 'TEXT');
                    $this->ensureColumn($pdo, 'books', 'description', 'TEXT');
                    $this->ensureColumn($pdo, 'books', 'published_at', 'TEXT');
                    $this->ensureColumn($pdo, 'books', 'series_index', 'REAL');
                    $this->ensureColumn($pdo, 'books', 'uuid', 'TEXT');
                    $this->ensureColumn($pdo, 'books', 'author_sort', 'TEXT');
                    $this->ensureColumn($pdo, 'books', 'library_timestamp', 'TEXT');
                    $this->ensureColumn($pdo, 'books', 'library_last_modified', 'TEXT');
                    $this->ensureColumn($pdo, 'books', 'source_mtime', 'INTEGER');
                },
            ],
            [
                'name' => '202604290105_ensure_books_read_state',
                'signature' => 'books_read_state_v1',
                'up' => function (\PDO $pdo): void {
                    $this->ensureColumn($pdo, 'books', 'is_read', 'INTEGER NOT NULL DEFAULT 0');
                },
            ],
            [
                'name' => '202604290106_create_library_indexes',
                'signature' => 'library_indexes_v1',
                'up' => function (\PDO $pdo): void {
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_books_title ON books(title)');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_books_author ON books(author)');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_books_tag ON books(tag)');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_books_series ON books(series)');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_books_isbn ON books(isbn)');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_books_uuid ON books(uuid)');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_books_path ON books(path)');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_books_is_read ON books(is_read)');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_book_paths_source_mtime ON book_paths(source_mtime)');
                },
            ],
            [
                'name' => '202604290107_create_books_fts',
                'signature' => 'create_books_fts_v1',
                'up' => function (\PDO $pdo): void {
                    try {
                        $pdo->exec(
                            "CREATE VIRTUAL TABLE IF NOT EXISTS books_fts
                             USING fts5(
                                path UNINDEXED,
                                title,
                                author,
                                tag,
                                series,
                                isbn,
                                tokenize = 'unicode61'
                             )"
                        );
                        return;
                    } catch (\Throwable) {
                    }

                    try {
                        $pdo->exec(
                            'CREATE VIRTUAL TABLE IF NOT EXISTS books_fts
                             USING fts4(path, title, author, tag, series, isbn, notindexed=path)'
                        );
                    } catch (\Throwable) {
                    }
                },
            ],
        ];
    }

    private function ensureColumn(\PDO $pdo, string $tableName, string $columnName, string $definition): void
    {
        if (!$this->isSafeIdentifier($tableName) || !$this->isSafeIdentifier($columnName)) {
            throw new \RuntimeException('Unsafe migration identifier.');
        }

        $stmt = $pdo->query('PRAGMA table_info(' . $tableName . ')');
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $column) {
            if (($column['name'] ?? '') === $columnName) {
                return;
            }
        }

        $pdo->exec('ALTER TABLE ' . $tableName . ' ADD COLUMN ' . $columnName . ' ' . $definition);
    }

    private function isSafeIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1;
    }

    private function normalizeTargetDb(string $targetDb): string
    {
        $normalized = strtolower(trim($targetDb));

        return match ($normalized) {
            'auth', 'library' => $normalized,
            default => throw new \RuntimeException('Unsupported migration target: ' . $targetDb),
        };
    }

    private function backupSqliteArtifacts(string $dbPath, string $timestamp): ?string
    {
        if (!is_file($dbPath)) {
            return null;
        }

        $directory = dirname($dbPath);
        $backupPath = $directory . DIRECTORY_SEPARATOR . $timestamp . '-' . basename($dbPath);
        $counter = 1;
        while (file_exists($backupPath)) {
            $backupPath = $directory . DIRECTORY_SEPARATOR . $timestamp . '-' . $counter . '-' . basename($dbPath);
            $counter++;
        }

        foreach (['', '-wal', '-shm'] as $suffix) {
            $sourcePath = $dbPath . $suffix;
            if (!is_file($sourcePath)) {
                continue;
            }

            $targetPath = $backupPath . $suffix;
            if ($this->moveSqliteArtifact($sourcePath, $targetPath)) {
                continue;
            }

            if (@copy($sourcePath, $targetPath) && @unlink($sourcePath)) {
                continue;
            }

            if (is_file($sourcePath) && @copy($sourcePath, $targetPath)) {
                throw new \RuntimeException('Cannot backup sqlite artifact: ' . $sourcePath);
            }
        }

        return $backupPath;
    }

    private function moveSqliteArtifact(string $sourcePath, string $targetPath): bool
    {
        return @rename($sourcePath, $targetPath);
    }

    private function recreateSqliteDatabaseFile(string $dbPath): void
    {
        $directory = dirname($dbPath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create sqlite directory: {$directory}");
        }

        if (is_file($dbPath) && !@unlink($dbPath)) {
            @chmod($dbPath, 0666);
            if (is_file($dbPath) && !@unlink($dbPath)) {
                throw new \RuntimeException('Cannot remove sqlite DB during recovery: ' . $dbPath);
            }
        }

        if (!@touch($dbPath)) {
            throw new \RuntimeException('Cannot recreate sqlite DB during recovery: ' . $dbPath);
        }
        @chmod($dbPath, 0666);
        clearstatcache(true, $dbPath);
    }

    private function openTargetPdo(string $dbPath): \PDO
    {
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function clearTargetMigrationRecords(string $targetDb): void
    {
        $normalizedTarget = $this->normalizeTargetDb($targetDb);
        $pdo = $this->getMigrationPdo();

        $deleteApplied = $pdo->prepare('DELETE FROM schema_migrations WHERE target_db = :target_db');
        $deleteApplied->execute([':target_db' => $normalizedTarget]);

        $deleteFailures = $pdo->prepare('DELETE FROM migration_failures WHERE target_db = :target_db');
        $deleteFailures->execute([':target_db' => $normalizedTarget]);
    }
}
