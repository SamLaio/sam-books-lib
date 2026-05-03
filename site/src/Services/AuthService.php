<?php

namespace Calibre\Services;

use Calibre\Database\MigrationRunner;
use Calibre\ScanService;
use Calibre\Support\Lang;

final class AuthService
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_USER = 'user';
    public const THEME_COOKIE_NAME = 'books_theme';
    public const THEME_COOKIE_TS_NAME = 'books_theme_ts';
    public const CATALOG_STATE_COOKIE_NAME = 'books_catalog_state';
    public const SORT_FIELD_COOKIE_NAME = 'books_sort_field';
    public const SORT_DIRECTION_COOKIE_NAME = 'books_sort_direction';
    private const THEME_COOKIE_TTL = 31536000;
    private const CATALOG_STATE_COOKIE_TTL = 2592000;
    private const SORT_COOKIE_TTL = 31536000;
    private const ALLOWED_SORT_FIELDS = ['is_read', 'title', 'author', 'series', 'added_at'];
    private const DEFAULT_MAX_LOGIN_ATTEMPTS = 5;

    private string $appRoot;
    private ScanService $scanService;
    private ?\PDO $pdo = null;
    private ?string $secretKey = null;
    private ?array $secretKeys = null;
    private bool $smtpConfigSynced = false;
    /** @var resource|null */
    private $settingsDbLockHandle = null;

    public function __construct(string $appRoot, ?ScanService $scanService = null)
    {
        $this->appRoot = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->scanService = $scanService ?? new ScanService($this->appRoot);
    }

    public function isEnabled(): bool
    {
        $raw = ScanService::readSetting(
            ScanService::loadConfig($this->appRoot),
            ['BOOKS_AUTH_ENABLED', 'AUTH_ENABLED'],
            'AUTH_ENABLED',
            '0'
        );

        if ($raw === null) {
            return false;
        }

        return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }

    public function isAuthenticated(): bool
    {
        $this->ensureSessionStarted();

        return isset($_SESSION['auth_user_id']) && (int) $_SESSION['auth_user_id'] > 0;
    }

    public function requireLogin(array $server, bool $json = false): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if ($this->isAuthenticated()) {
            $user = $this->getCurrentUser();
            if (is_array($user) && ((int) ($user['is_enabled'] ?? 1) === 1)) {
                return;
            }

            $this->logout();
        }

        if ($json) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => Lang::t('error.unauthorized')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $requestUri = (string) ($server['REQUEST_URI'] ?? 'index.php');
        $target = 'login.php?next=' . rawurlencode($requestUri);
        header('Location: ' . $target, true, 302);
        exit;
    }

    public function login(string $username, string $password): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        $username = trim($username);
        if ($username === '' || $password === '') {
            return false;
        }

        $this->ensureBootstrapUserSeeded();
        $this->ensureSessionStarted();

        $stmt = $this->getPdo()->prepare(
            'SELECT id, username, password_hash, role, is_enabled, is_default, failed_login_attempts
             FROM users
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            return false;
        }

        if ((int) ($user['is_enabled'] ?? 1) !== 1) {
            return false;
        }

        $passwordHash = (string) ($user['password_hash'] ?? '');
        if ($passwordHash === '') {
            return false;
        }

        $matchedKey = $this->resolveMatchedSecretKey($password, $passwordHash);

        if ($matchedKey === null) {
            $this->recordFailedLoginAttempt($user);
            return false;
        }

        // 若是使用舊密鑰驗證成功，立即升級為目前密鑰雜湊
        $currentKey = $this->getSecretKey();
        if ($matchedKey !== $currentKey) {
            $rehashStmt = $this->getPdo()->prepare(
                'UPDATE users
                 SET password_hash = :password_hash, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $rehashStmt->execute([
                ':id' => (int) $user['id'],
                ':password_hash' => password_hash($this->pepperWithKey($password, $currentKey), PASSWORD_DEFAULT),
            ]);
        }

        $_SESSION['auth_user_id'] = (int) $user['id'];
        $_SESSION['auth_username'] = (string) $user['username'];
        $_SESSION['auth_user_role'] = $this->normalizeRole((string) ($user['role'] ?? self::ROLE_USER));
        $_SESSION['auth_password_change_required'] = $this->shouldForceDefaultAdminPasswordChange($user, $username, $password) ? 1 : 0;
        $this->resetFailedLoginAttempts((int) $user['id']);
        $this->clearRecoveredPasswordFileForAdmin($this->normalizeRole((string) ($user['role'] ?? self::ROLE_USER)));
        session_regenerate_id(true);

        return true;
    }

    public function changeCurrentUserPassword(string $currentPassword, string $newPassword, string $confirmPassword): void
    {
        if (!$this->isEnabled()) {
            throw new \RuntimeException(Lang::t('error.auth_disabled'));
        }

        $currentPassword = (string) $currentPassword;
        $newPassword = trim((string) $newPassword);
        $confirmPassword = trim((string) $confirmPassword);

        if ($currentPassword === '') {
            throw new \RuntimeException(Lang::t('error.current_password_required'));
        }

        if ($newPassword === '' || $confirmPassword === '') {
            throw new \RuntimeException(Lang::t('error.new_password_and_confirm_required'));
        }

        if ($newPassword !== $confirmPassword) {
            throw new \RuntimeException(Lang::t('error.new_password_mismatch'));
        }

        $this->assertValidPassword($newPassword, Lang::t('auth.label.new_password'));

        $this->ensureSessionStarted();
        $userId = (int) ($_SESSION['auth_user_id'] ?? 0);
        if ($userId < 1) {
            throw new \RuntimeException(Lang::t('error.not_logged_in'));
        }

        $stmt = $this->getPdo()->prepare(
            'SELECT id, password_hash
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($user)) {
            throw new \RuntimeException(Lang::t('error.user_not_found_current'));
        }

        $passwordHash = (string) ($user['password_hash'] ?? '');
        if ($passwordHash === '') {
            throw new \RuntimeException(Lang::t('error.password_data_invalid'));
        }

        $matchedKey = $this->resolveMatchedSecretKey($currentPassword, $passwordHash);
        if ($matchedKey === null) {
            throw new \RuntimeException(Lang::t('error.current_password_invalid'));
        }

        if ($currentPassword === $newPassword) {
            throw new \RuntimeException(Lang::t('error.new_password_same_as_current'));
        }

        $this->updatePassword((int) $user['id'], $newPassword);
        $this->clearPasswordChangeRequired();
    }

    public function logout(): void
    {
        $this->ensureSessionStarted();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 3600,
                $params['path'] ?? '/',
                $params['domain'] ?? '',
                (bool) ($params['secure'] ?? false),
                (bool) ($params['httponly'] ?? true)
            );
        }

        session_destroy();
    }

    public function loginByUserId(int $userId): bool
    {
        if (!$this->isEnabled() || $userId < 1) {
            return false;
        }

        $this->ensureBootstrapUserSeeded();
        $this->ensureSessionStarted();

        $stmt = $this->getPdo()->prepare(
            'SELECT id, username, role, is_enabled
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($user) || (int) ($user['is_enabled'] ?? 1) !== 1) {
            return false;
        }

        $_SESSION['auth_user_id'] = (int) $user['id'];
        $_SESSION['auth_username'] = (string) $user['username'];
        $_SESSION['auth_user_role'] = $this->normalizeRole((string) ($user['role'] ?? self::ROLE_USER));
        $_SESSION['auth_password_change_required'] = 0;
        $this->resetFailedLoginAttempts((int) $user['id']);
        $this->clearRecoveredPasswordFileForAdmin($this->normalizeRole((string) ($user['role'] ?? self::ROLE_USER)));
        session_regenerate_id(true);

        return true;
    }

    public function getCurrentUser(): ?array
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        $userId = (int) ($_SESSION['auth_user_id'] ?? 0);
        if ($userId < 1) {
            return null;
        }

        $stmt = $this->getPdo()->prepare(
            'SELECT id, username, email, role, is_enabled, api_token, ui_theme, ui_theme_updated_at, ui_locale, ui_sort_field, ui_sort_direction, is_default, hidden_authors, hidden_tags, created_at, updated_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function isCurrentUserAdmin(): bool
    {
        $user = $this->getCurrentUser();
        if (!is_array($user)) {
            return false;
        }

        return $this->normalizeRole((string) ($user['role'] ?? self::ROLE_USER)) === self::ROLE_ADMIN;
    }

    public function clearRecoveredPasswordFileForCurrentAdmin(): void
    {
        if ($this->isCurrentUserAdmin()) {
            $this->clearRecoveredPasswordFileForAdmin(self::ROLE_ADMIN);
        }
    }

    public function updateCurrentUserEmail(string $email): void
    {
        if (!$this->isEnabled()) {
            throw new \RuntimeException(Lang::t('error.auth_disabled'));
        }

        $this->ensureSessionStarted();
        $userId = (int) ($_SESSION['auth_user_id'] ?? 0);
        if ($userId < 1) {
            throw new \RuntimeException(Lang::t('error.not_logged_in'));
        }

        $normalizedEmail = $this->normalizeEmail($email);
        $stmt = $this->getPdo()->prepare(
            'UPDATE users
             SET email = :email, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $userId,
            ':email' => $normalizedEmail,
        ]);
    }

    public function listUsers(): array
    {
        $this->enforceLoginAttemptLimit();

        $stmt = $this->getPdo()->query(
            'SELECT id, username, email, role, is_enabled, is_default, failed_login_attempts, ui_locale, hidden_authors, hidden_tags, created_at, updated_at
             FROM users
             ORDER BY is_default DESC, id ASC'
        );

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        return array_map(function (array $row): array {
            $row['role'] = $this->normalizeRole((string) ($row['role'] ?? self::ROLE_USER));
            $row['is_enabled'] = (int) ($row['is_enabled'] ?? 1) === 1 ? 1 : 0;
            $row['is_default'] = (int) ($row['is_default'] ?? 0) === 1 ? 1 : 0;
            $row['failed_login_attempts'] = max(0, (int) ($row['failed_login_attempts'] ?? 0));
            return $row;
        }, $rows);
    }

    public function createUser(
        string $username,
        string $password,
        string $email = '',
        string $role = self::ROLE_USER,
        bool $enabled = true
    ): void
    {
        $normalizedUsername = $this->normalizeUsername($username);

        $existsStmt = $this->getPdo()->prepare(
            'SELECT 1
             FROM users
             WHERE username = :username
             LIMIT 1'
        );
        $existsStmt->execute([':username' => $normalizedUsername]);
        if ($existsStmt->fetchColumn() !== false) {
            throw new \RuntimeException(Lang::t('error.username_taken'));
        }

        $password = trim($password);
        $this->assertValidPassword($password, Lang::t('auth.label.new_user_password'));

        $normalizedEmail = $this->normalizeEmail($email);
        $normalizedRole = $this->normalizeRole($role);

        $stmt = $this->getPdo()->prepare(
            'INSERT INTO users(
                username, email, password_hash, role, is_enabled, failed_login_attempts, api_token, ui_theme, ui_theme_updated_at, ui_locale, ui_sort_field, ui_sort_direction, is_default, created_at, updated_at
             )
             VALUES(
                :username, :email, :password_hash, :role, :is_enabled, 0, :api_token, :ui_theme, CURRENT_TIMESTAMP, :ui_locale, :ui_sort_field, :ui_sort_direction, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )'
        );

        try {
            $stmt->execute([
                ':username' => $normalizedUsername,
                ':email' => $normalizedEmail,
                ':password_hash' => password_hash($this->pepper($password), PASSWORD_DEFAULT),
                ':role' => $normalizedRole,
                ':is_enabled' => $enabled ? 1 : 0,
                ':api_token' => bin2hex(random_bytes(24)),
                ':ui_theme' => $this->getDefaultTheme(),
                ':ui_locale' => $this->getDefaultLocale(),
                ':ui_sort_field' => 'added_at',
                ':ui_sort_direction' => 'desc',
            ]);
        } catch (\PDOException $e) {
            if (stripos((string) $e->getMessage(), 'UNIQUE') !== false) {
                throw new \RuntimeException(Lang::t('error.username_taken'));
            }

            throw $e;
        }
    }

    public function adminUpdateUser(
        int $actorUserId,
        int $targetUserId,
        string $username,
        string $email,
        ?string $newPassword,
        bool $enabled
    ): void
    {
        if ($targetUserId < 1) {
            throw new \RuntimeException(Lang::t('error.user_id_invalid'));
        }

        $stmt = $this->getPdo()->prepare(
            'SELECT id, username, role, is_enabled, is_default
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $targetUserId]);
        $target = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($target)) {
            throw new \RuntimeException(Lang::t('error.target_user_not_found'));
        }

        if ((int) ($target['is_default'] ?? 0) === 1 && !$enabled) {
            throw new \RuntimeException(Lang::t('error.default_admin_cannot_disable'));
        }

        if ($targetUserId === $actorUserId && !$enabled) {
            throw new \RuntimeException(Lang::t('error.current_user_cannot_disable'));
        }

        $normalizedUsername = $this->normalizeUsername($username);
        $normalizedEmail = $this->normalizeEmail($email);
        $pdo = $this->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $update = $pdo->prepare(
                'UPDATE users
                 SET username = :username,
                     email = :email,
                     is_enabled = :is_enabled,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $enabledValue = $enabled ? 1 : 0;
            $currentEnabledValue = (int) ($target['is_enabled'] ?? 1) === 1 ? 1 : 0;
            $update->execute([
                ':id' => $targetUserId,
                ':username' => $normalizedUsername,
                ':email' => $normalizedEmail,
                ':is_enabled' => $enabledValue,
            ]);

            if ($enabledValue !== $currentEnabledValue) {
                $this->resetFailedLoginAttempts($targetUserId);
            }

            if ($newPassword !== null) {
                $trimmed = trim($newPassword);
                if ($trimmed !== '') {
                    $this->assertValidPassword($trimmed, Lang::t('auth.label.new_password'));
                    $this->updatePassword($targetUserId, $trimmed);
                }
            }

            $pdo->exec('COMMIT');
        } catch (\PDOException $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (\Throwable) {
            }
            if (stripos((string) $e->getMessage(), 'UNIQUE') !== false) {
                throw new \RuntimeException(Lang::t('error.username_exists_use_another'));
            }
            throw $e;
        } catch (\Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    public function adminUpdateUserLibrarySettings(
        int $actorUserId,
        int $targetUserId,
        array $hiddenAuthors,
        array $hiddenTags
    ): void
    {
        if ($targetUserId < 1) {
            throw new \RuntimeException(Lang::t('error.user_id_invalid'));
        }

        $stmt = $this->getPdo()->prepare(
            'SELECT id
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $targetUserId]);
        $target = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($target)) {
            throw new \RuntimeException(Lang::t('error.target_user_not_found'));
        }

        if ($actorUserId < 1) {
            throw new \RuntimeException(Lang::t('error.admin_status_invalid'));
        }

        $update = $this->getPdo()->prepare(
            'UPDATE users
             SET hidden_authors = :hidden_authors,
                 hidden_tags = :hidden_tags,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $update->execute([
            ':id' => $targetUserId,
            ':hidden_authors' => $this->encodeStringList($hiddenAuthors),
            ':hidden_tags' => $this->encodeStringList($hiddenTags),
        ]);
    }

    public function adminDeleteUser(int $actorUserId, int $targetUserId): void
    {
        if ($targetUserId < 1) {
            throw new \RuntimeException(Lang::t('error.user_id_invalid'));
        }

        $stmt = $this->getPdo()->prepare(
            'SELECT id, is_default
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $targetUserId]);
        $target = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($target)) {
            throw new \RuntimeException(Lang::t('error.target_user_not_found'));
        }

        if ((int) ($target['is_default'] ?? 0) === 1) {
            throw new \RuntimeException(Lang::t('error.default_admin_cannot_delete'));
        }

        if ($targetUserId === $actorUserId) {
            throw new \RuntimeException(Lang::t('error.current_user_cannot_delete'));
        }

        $delete = $this->getPdo()->prepare('DELETE FROM users WHERE id = :id');
        $delete->execute([':id' => $targetUserId]);
    }

    public function getAppSettings(): array
    {
        $this->syncSmtpSettingsFromConfig();

        $defaults = [
            'default_locale' => $this->getConfiguredDefaultLocale(),
            'login_max_attempts' => (string) self::DEFAULT_MAX_LOGIN_ATTEMPTS,
            'magic_login_enabled' => '1',
            'smtp_host' => '',
            'smtp_port' => '',
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_encryption' => 'none',
        ];

        $stmt = $this->getPdo()->query(
            'SELECT setting_key, setting_value
             FROM app_settings'
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return $defaults;
        }

        foreach ($rows as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            if (!array_key_exists($key, $defaults)) {
                continue;
            }
            $defaults[$key] = (string) ($row['setting_value'] ?? '');
        }

        $defaults['smtp_encryption'] = strtolower(trim((string) ($defaults['smtp_encryption'] ?? 'none')));
        if (!in_array($defaults['smtp_encryption'], ['none', 'tls', 'ssl'], true)) {
            $defaults['smtp_encryption'] = 'none';
        }

        return $defaults;
    }

    public function isSmtpConfigured(?array $settings = null): bool
    {
        $settings = is_array($settings) ? $settings : $this->getAppSettings();
        $host = trim((string) ($settings['smtp_host'] ?? ''));
        $port = trim((string) ($settings['smtp_port'] ?? ''));
        $encryption = strtolower(trim((string) ($settings['smtp_encryption'] ?? 'none')));

        if ($host === '') {
            return false;
        }
        if ($port === '' || !ctype_digit($port)) {
            return false;
        }
        $portValue = (int) $port;
        if ($portValue < 1 || $portValue > 65535) {
            return false;
        }
        if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
            return false;
        }

        return true;
    }

    public function getLoginMaxAttempts(): int
    {
        $settings = $this->readAppSettingsFromDb();

        return $this->normalizeMaxLoginAttempts((int) ($settings['login_max_attempts'] ?? self::DEFAULT_MAX_LOGIN_ATTEMPTS));
    }

    public function updateLoginMaxAttempts(int $maxAttempts): int
    {
        $normalized = $this->normalizeMaxLoginAttempts($maxAttempts);
        $stmt = $this->getPdo()->prepare(
            'INSERT INTO app_settings(setting_key, setting_value, updated_at)
             VALUES(:setting_key, :setting_value, CURRENT_TIMESTAMP)
             ON CONFLICT(setting_key) DO UPDATE SET
                setting_value = excluded.setting_value,
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            ':setting_key' => 'login_max_attempts',
            ':setting_value' => (string) $normalized,
        ]);
        $this->enforceLoginAttemptLimit($normalized);

        return $normalized;
    }

    public function isMagicLoginEnabled(): bool
    {
        $settings = $this->readAppSettingsFromDb();

        return ((string) ($settings['magic_login_enabled'] ?? '1')) === '1';
    }

    public function updateMagicLoginEnabled(bool $enabled): void
    {
        $stmt = $this->getPdo()->prepare(
            'INSERT INTO app_settings(setting_key, setting_value, updated_at)
             VALUES(:setting_key, :setting_value, CURRENT_TIMESTAMP)
             ON CONFLICT(setting_key) DO UPDATE SET
                setting_value = excluded.setting_value,
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            ':setting_key' => 'magic_login_enabled',
            ':setting_value' => $enabled ? '1' : '0',
        ]);
    }

    public function recordFailedLoginAttemptForUsername(string $username): void
    {
        $username = trim($username);
        if ($username === '') {
            return;
        }

        $this->ensureBootstrapUserSeeded();

        $stmt = $this->getPdo()->prepare(
            'SELECT id, username, role, is_enabled, failed_login_attempts
             FROM users
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($user) || (int) ($user['is_enabled'] ?? 1) !== 1) {
            return;
        }

        $this->recordFailedLoginAttempt($user);
    }

    public function enforceLoginAttemptLimit(?int $maxAttempts = null): void
    {
        $normalizedMaxAttempts = $maxAttempts === null
            ? $this->getLoginMaxAttempts()
            : $this->normalizeMaxLoginAttempts($maxAttempts);
        if ($normalizedMaxAttempts < 1) {
            return;
        }

        $stmt = $this->getPdo()->prepare(
            "UPDATE users
             SET is_enabled = 0,
                 updated_at = CURRENT_TIMESTAMP
             WHERE role != :admin_role
               AND is_enabled = 1
               AND failed_login_attempts >= :max_attempts"
        );
        $stmt->execute([
            ':admin_role' => self::ROLE_ADMIN,
            ':max_attempts' => $normalizedMaxAttempts,
        ]);
    }

    private function syncSmtpSettingsFromConfig(): void
    {
        if ($this->smtpConfigSynced) {
            return;
        }
        $this->smtpConfigSynced = true;

        $config = ScanService::loadConfig($this->appRoot);
        $host = trim((string) (ScanService::readSetting($config, ['BOOKS_SMTP_HOST', 'SMTP_HOST'], 'SMTP_HOST', '') ?? ''));
        $port = trim((string) (ScanService::readSetting($config, ['BOOKS_SMTP_PORT', 'SMTP_PORT'], 'SMTP_PORT', '') ?? ''));
        $encryption = strtolower(trim((string) (ScanService::readSetting($config, ['BOOKS_SMTP_ENCRYPTION', 'SMTP_ENCRYPTION'], 'SMTP_ENCRYPTION', 'none') ?? 'none')));
        $username = trim((string) (ScanService::readSetting($config, ['BOOKS_SMTP_USERNAME', 'SMTP_USERNAME'], 'SMTP_USERNAME', '') ?? ''));
        $password = (string) (ScanService::readSetting($config, ['BOOKS_SMTP_PASSWORD', 'SMTP_PASSWORD'], 'SMTP_PASSWORD', '') ?? '');

        // 若 env/compose 沒有提供 SMTP，則保持 DB 現有值不覆蓋。
        if ($host === '' && $port === '' && $username === '' && $password === '') {
            return;
        }

        if ($encryption === '' || !in_array($encryption, ['none', 'tls', 'ssl'], true)) {
            $encryption = 'none';
        }

        $existing = $this->readAppSettingsFromDb();
        // 僅在 DB 尚未設定 SMTP 時，才把 env 值初始化進 DB，避免覆蓋管理員在 UI 的修改。
        $dbAlreadyHasSmtp = trim((string) ($existing['smtp_host'] ?? '')) !== ''
            || trim((string) ($existing['smtp_port'] ?? '')) !== ''
            || trim((string) ($existing['smtp_username'] ?? '')) !== ''
            || trim((string) ($existing['smtp_password'] ?? '')) !== '';
        if ($dbAlreadyHasSmtp) {
            return;
        }

        $this->updateAppSettings([
            'smtp_host' => $host,
            'smtp_port' => $port,
            'smtp_encryption' => $encryption,
            'smtp_username' => $username,
            'smtp_password' => $password,
        ]);
    }

    private function readAppSettingsFromDb(): array
    {
        $defaults = [
            'default_locale' => $this->getConfiguredDefaultLocale(),
            'login_max_attempts' => (string) self::DEFAULT_MAX_LOGIN_ATTEMPTS,
            'magic_login_enabled' => '1',
            'smtp_host' => '',
            'smtp_port' => '',
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_encryption' => 'none',
        ];

        $stmt = $this->getPdo()->query(
            'SELECT setting_key, setting_value
             FROM app_settings'
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return $defaults;
        }

        foreach ($rows as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            if (!array_key_exists($key, $defaults)) {
                continue;
            }
            $defaults[$key] = (string) ($row['setting_value'] ?? '');
        }

        $defaults['smtp_encryption'] = strtolower(trim((string) ($defaults['smtp_encryption'] ?? 'none')));
        if (!in_array($defaults['smtp_encryption'], ['none', 'tls', 'ssl'], true)) {
            $defaults['smtp_encryption'] = 'none';
        }

        return $defaults;
    }

    public function updateAppSettings(array $settings): void
    {
        $allowedKeys = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption'];
        $normalized = [];
        foreach ($allowedKeys as $key) {
            $normalized[$key] = trim((string) ($settings[$key] ?? ''));
        }

        $normalized['smtp_encryption'] = strtolower($normalized['smtp_encryption']);
        if (!in_array($normalized['smtp_encryption'], ['none', 'tls', 'ssl'], true)) {
            throw new \RuntimeException(Lang::t('error.smtp_encryption_invalid'));
        }

        if ($normalized['smtp_port'] !== '') {
            if (!ctype_digit($normalized['smtp_port'])) {
                throw new \RuntimeException(Lang::t('error.smtp_port_numeric'));
            }
            $port = (int) $normalized['smtp_port'];
            if ($port < 1 || $port > 65535) {
                throw new \RuntimeException(Lang::t('error.smtp_port_range'));
            }
        }

        $pdo = $this->getPdo();
        $stmt = $pdo->prepare(
            'INSERT INTO app_settings(setting_key, setting_value, updated_at)
             VALUES(:setting_key, :setting_value, CURRENT_TIMESTAMP)
             ON CONFLICT(setting_key) DO UPDATE SET
                setting_value = excluded.setting_value,
                updated_at = CURRENT_TIMESTAMP'
        );

        foreach ($normalized as $key => $value) {
            $stmt->execute([
                ':setting_key' => $key,
                ':setting_value' => $value,
            ]);
        }
    }

    public function getAvailableLocales(): array
    {
        return ['zhTW', 'en'];
    }

    public function getConfiguredDefaultLocale(): string
    {
        $value = ScanService::readSetting(
            ScanService::loadConfig($this->appRoot),
            ['BOOKS_LOCALE', 'APP_LOCALE', 'LOCALE'],
            'APP_LOCALE',
            'zhTW'
        );

        return $this->normalizeLocale((string) $value, 'zhTW');
    }

    public function getDefaultLocale(): string
    {
        $settings = $this->readAppSettingsFromDb();
        $defaultLocale = trim((string) ($settings['default_locale'] ?? ''));

        return $this->normalizeLocale($defaultLocale, $this->getConfiguredDefaultLocale());
    }

    public function updateDefaultLocale(string $locale): string
    {
        $normalized = $this->normalizeLocale($locale, $this->getConfiguredDefaultLocale());
        $stmt = $this->getPdo()->prepare(
            'INSERT INTO app_settings(setting_key, setting_value, updated_at)
             VALUES(:setting_key, :setting_value, CURRENT_TIMESTAMP)
             ON CONFLICT(setting_key) DO UPDATE SET
                setting_value = excluded.setting_value,
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            ':setting_key' => 'default_locale',
            ':setting_value' => $normalized,
        ]);

        return $normalized;
    }

    public function getPreferredTheme(): string
    {
        return $this->resolvePreferredTheme();
    }

    public function resolvePreferredTheme(?string $requestedTheme = null): string
    {
        $defaultTheme = $this->getDefaultTheme();
        $requestedTheme = is_string($requestedTheme) ? strtolower(trim($requestedTheme)) : '';
        $requestedTheme = in_array($requestedTheme, ['light', 'dark'], true) ? $requestedTheme : '';

        $cookieState = $this->readThemeCookieState();
        $user = $this->isEnabled() ? $this->getCurrentUser() : null;
        $dbTheme = is_array($user) ? $this->normalizeTheme((string) ($user['ui_theme'] ?? ''), $defaultTheme) : $defaultTheme;
        $dbTimestamp = is_array($user) ? $this->parseStoredTimestamp((string) ($user['ui_theme_updated_at'] ?? ($user['updated_at'] ?? ''))) : 0;

        if ($requestedTheme !== '') {
            $timestamp = time();
            $this->persistThemeCookie($requestedTheme, $timestamp);
            if (is_array($user)) {
                $this->updateThemeForUserId((int) ($user['id'] ?? 0), $requestedTheme, $timestamp);
            }

            return $requestedTheme;
        }

        if (!is_array($user)) {
            return $cookieState['theme'] !== '' ? $cookieState['theme'] : $defaultTheme;
        }

        $cookieTheme = $cookieState['theme'];
        $cookieTimestamp = $cookieState['timestamp'];
        if ($cookieTheme !== '' && $cookieTimestamp >= $dbTimestamp) {
            if ($cookieTheme !== $dbTheme || $cookieTimestamp > $dbTimestamp) {
                $this->updateThemeForUserId((int) ($user['id'] ?? 0), $cookieTheme, $cookieTimestamp);
            }

            return $cookieTheme;
        }

        $syncTimestamp = $dbTimestamp > 0 ? $dbTimestamp : time();
        $this->persistThemeCookie($dbTheme, $syncTimestamp);

        return $dbTheme;
    }

    public function getPreferredLocale(): string
    {
        if (!$this->isEnabled()) {
            return $this->getDefaultLocale();
        }

        $user = $this->getCurrentUser();
        if (!is_array($user)) {
            return $this->getDefaultLocale();
        }

        return $this->normalizeLocale((string) ($user['ui_locale'] ?? ''), $this->getDefaultLocale());
    }

    public function updateCurrentUserLocale(string $locale): string
    {
        if (!$this->isEnabled()) {
            throw new \RuntimeException(Lang::t('error.auth_disabled'));
        }

        $this->ensureSessionStarted();
        $userId = (int) ($_SESSION['auth_user_id'] ?? 0);
        if ($userId < 1) {
            throw new \RuntimeException(Lang::t('error.not_logged_in'));
        }

        $normalizedLocale = $this->normalizeLocale($locale, $this->getDefaultLocale());
        $stmt = $this->getPdo()->prepare(
            'UPDATE users
             SET ui_locale = :ui_locale, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $userId,
            ':ui_locale' => $normalizedLocale,
        ]);

        return $normalizedLocale;
    }

    public function updateCurrentUserTheme(string $theme): string
    {
        if (!$this->isEnabled()) {
            throw new \RuntimeException(Lang::t('error.theme_persistence_requires_auth_enabled'));
        }

        $this->ensureSessionStarted();
        $userId = (int) ($_SESSION['auth_user_id'] ?? 0);
        if ($userId < 1) {
            throw new \RuntimeException(Lang::t('error.not_authenticated'));
        }

        return $this->updateThemeForUserId($userId, $theme, time());
    }

    public function persistThemeCookie(string $theme, ?int $timestamp = null): void
    {
        $normalizedTheme = $this->normalizeTheme($theme, $this->getDefaultTheme());
        if (headers_sent()) {
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);

        $timestamp = $timestamp ?? time();

        setcookie(self::THEME_COOKIE_NAME, $normalizedTheme, [
            'expires' => time() + self::THEME_COOKIE_TTL,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        setcookie(self::THEME_COOKIE_TS_NAME, (string) $timestamp, [
            'expires' => time() + self::THEME_COOKIE_TTL,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    public function persistCatalogStateCookie(string $catalogUrl): void
    {
        $catalogUrl = trim($catalogUrl);
        if ($catalogUrl === '' || headers_sent()) {
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) ($_SERVER['HTTPS'])) !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
        setcookie(self::CATALOG_STATE_COOKIE_NAME, $catalogUrl, [
            'expires' => time() + self::CATALOG_STATE_COOKIE_TTL,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    public function getPreferredCatalogSort(string $defaultField, string $defaultDirection): array
    {
        $normalizedDefaultField = $this->normalizeSortField($defaultField, 'added_at');
        $normalizedDefaultDirection = $this->normalizeSortDirection($defaultDirection, 'desc');

        if (!$this->isEnabled()) {
            $cookieField = (string) ($_COOKIE[self::SORT_FIELD_COOKIE_NAME] ?? '');
            $cookieDirection = (string) ($_COOKIE[self::SORT_DIRECTION_COOKIE_NAME] ?? '');

            return [
                'field' => $this->normalizeSortField($cookieField, $normalizedDefaultField),
                'direction' => $this->normalizeSortDirection($cookieDirection, $normalizedDefaultDirection),
            ];
        }

        $user = $this->getCurrentUser();
        if (!is_array($user)) {
            return [
                'field' => $normalizedDefaultField,
                'direction' => $normalizedDefaultDirection,
            ];
        }

        return [
            'field' => $this->normalizeSortField((string) ($user['ui_sort_field'] ?? ''), $normalizedDefaultField),
            'direction' => $this->normalizeSortDirection((string) ($user['ui_sort_direction'] ?? ''), $normalizedDefaultDirection),
        ];
    }

    public function persistCatalogSortPreference(string $sortField, string $sortDirection): void
    {
        $normalizedField = $this->normalizeSortField($sortField, 'added_at');
        $normalizedDirection = $this->normalizeSortDirection($sortDirection, 'desc');

        if ($this->isEnabled()) {
            $this->ensureSessionStarted();
            $userId = (int) ($_SESSION['auth_user_id'] ?? 0);
            if ($userId > 0) {
                $stmt = $this->getPdo()->prepare(
                    'UPDATE users
                     SET ui_sort_field = :ui_sort_field,
                         ui_sort_direction = :ui_sort_direction,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':id' => $userId,
                    ':ui_sort_field' => $normalizedField,
                    ':ui_sort_direction' => $normalizedDirection,
                ]);
            }

            return;
        }

        $this->persistSortCookies($normalizedField, $normalizedDirection);
    }

    public function updatePassword(int $userId, string $newPassword): void
    {
        $newPassword = trim($newPassword);
        $this->assertValidPassword($newPassword, Lang::t('auth.label.new_password'));

        $stmt = $this->getPdo()->prepare(
            'UPDATE users
             SET password_hash = :password_hash, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $userId,
            ':password_hash' => password_hash($this->pepper($newPassword), PASSWORD_DEFAULT),
        ]);
    }

    public function isPasswordChangeRequired(): bool
    {
        $this->ensureSessionStarted();

        return (int) ($_SESSION['auth_password_change_required'] ?? 0) === 1;
    }

    public function clearPasswordChangeRequired(): void
    {
        $this->ensureSessionStarted();
        unset($_SESSION['auth_password_change_required']);
    }

    public function rotateApiToken(int $userId): string
    {
        $token = bin2hex(random_bytes(24));
        $stmt = $this->getPdo()->prepare(
            'UPDATE users
             SET api_token = :api_token, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $userId,
            ':api_token' => $token,
        ]);

        return $token;
    }

    public function getUserHiddenAuthors(?array $user): array
    {
        if (!is_array($user)) {
            return [];
        }

        return $this->decodeStringList((string) ($user['hidden_authors'] ?? ''));
    }

    public function getUserHiddenTags(?array $user): array
    {
        if (!is_array($user)) {
            return [];
        }

        return $this->decodeStringList((string) ($user['hidden_tags'] ?? ''));
    }

    public function findUserByToken(string $token): ?array
    {
        $normalized = trim($token);
        if ($normalized === '') {
            return null;
        }

        $stmt = $this->getPdo()->prepare(
            'SELECT id, username, api_token, role, is_enabled
             FROM users
             WHERE api_token = :api_token AND is_enabled = 1
             LIMIT 1'
        );
        $stmt->execute([':api_token' => $normalized]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function recordFailedLoginAttempt(array $user): void
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId < 1) {
            return;
        }

        $role = $this->normalizeRole((string) ($user['role'] ?? self::ROLE_USER));
        $failedAttempts = max(0, (int) ($user['failed_login_attempts'] ?? 0)) + 1;
        $maxAttempts = $this->getLoginMaxAttempts();
        $limitReached = $maxAttempts > 0 && $failedAttempts >= $maxAttempts;

        if ($role === self::ROLE_ADMIN && $limitReached) {
            $this->resetAdminPasswordAfterFailedAttempts($userId, (string) ($user['username'] ?? ''));
            return;
        }

        $shouldDisable = $role !== self::ROLE_ADMIN && $limitReached;

        $stmt = $this->getPdo()->prepare(
            'UPDATE users
             SET failed_login_attempts = :failed_login_attempts,
                 is_enabled = CASE WHEN :should_disable = 1 THEN 0 ELSE is_enabled END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $userId,
            ':failed_login_attempts' => $failedAttempts,
            ':should_disable' => $shouldDisable ? 1 : 0,
        ]);
    }

    private function resetAdminPasswordAfterFailedAttempts(int $userId, string $username): void
    {
        $newPassword = $this->generateRecoveryPassword();
        $pdo = $this->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $pdo->prepare(
                'UPDATE users
                 SET password_hash = :password_hash,
                     failed_login_attempts = 0,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $userId,
                ':password_hash' => password_hash($this->pepper($newPassword), PASSWORD_DEFAULT),
            ]);

            $this->writeRecoveredPasswordFile($username, $newPassword);
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function generateRecoveryPassword(): string
    {
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $symbols = '!@#$%^&*_-+=';
        $all = $lower . $upper . $digits . $symbols;

        $chars = [
            $lower[random_int(0, strlen($lower) - 1)],
            $upper[random_int(0, strlen($upper) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $symbols[random_int(0, strlen($symbols) - 1)],
        ];

        for ($i = count($chars); $i < 16; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }

        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    private function writeRecoveredPasswordFile(string $username, string $newPassword): void
    {
        $dataDir = $this->appRoot . DIRECTORY_SEPARATOR . 'data';
        if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
            throw new \RuntimeException('Cannot create data directory for recovered password.');
        }

        $path = $dataDir . DIRECTORY_SEPARATOR . 'new_pass.txt';
        $content = sprintf(
            "username=%s\npassword=%s\ngenerated_at=%s\n",
            $username,
            $newPassword,
            gmdate('c')
        );

        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write recovered admin password file.');
        }

        @chmod($path, 0600);
    }

    private function resetFailedLoginAttempts(int $userId): void
    {
        if ($userId < 1) {
            return;
        }

        $stmt = $this->getPdo()->prepare(
            'UPDATE users
             SET failed_login_attempts = 0,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([':id' => $userId]);
    }

    private function clearRecoveredPasswordFileForAdmin(string $role): void
    {
        if ($this->normalizeRole($role) !== self::ROLE_ADMIN) {
            return;
        }

        $path = $this->appRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'new_pass.txt';
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function ensureBootstrapUser(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $credentials = $this->loadBootstrapCredentials();
        if ($credentials === null) {
            return;
        }

        $username = (string) $credentials['username'];
        $normalizedUsername = trim($username);
        $normalizedPassword = (string) $credentials['password'];
        $normalizedEmail = $this->normalizeEmail((string) ($credentials['email'] ?? ''));
        $syncEmail = trim((string) ($credentials['email'] ?? '')) !== '';
        $pdo = $this->getPdo();
        $passwordHash = password_hash($this->pepper($normalizedPassword), PASSWORD_DEFAULT);

        $pdo->exec('BEGIN IMMEDIATE');

        try {
            $pdo->exec('UPDATE users SET is_default = 0 WHERE is_default != 0');

            $defaultAdminStmt = $pdo->query(
                'SELECT id
                 FROM users
                 WHERE id = 1
                 LIMIT 1'
            );
            $defaultAdminId = $defaultAdminStmt->fetchColumn();

            if ($defaultAdminId !== false) {
                $updateStmt = $pdo->prepare(
                    'UPDATE users
                     SET username = :username,
                         email = CASE WHEN :sync_email = 1 THEN :email ELSE email END,
                         password_hash = :password_hash,
                         role = :role,
                         is_enabled = 1,
                         is_default = 1,
                         ui_theme = COALESCE(NULLIF(ui_theme, ""), :default_theme),
                         ui_theme_updated_at = COALESCE(ui_theme_updated_at, CURRENT_TIMESTAMP),
                         ui_locale = COALESCE(NULLIF(ui_locale, ""), :default_locale),
                         ui_sort_field = COALESCE(NULLIF(ui_sort_field, ""), :default_sort_field),
                         ui_sort_direction = COALESCE(NULLIF(ui_sort_direction, ""), :default_sort_direction),
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    ':id' => (int) $defaultAdminId,
                    ':username' => $normalizedUsername,
                    ':email' => $normalizedEmail,
                    ':sync_email' => $syncEmail ? 1 : 0,
                    ':password_hash' => $passwordHash,
                    ':role' => self::ROLE_ADMIN,
                    ':default_theme' => $this->getDefaultTheme(),
                    ':default_locale' => $this->getDefaultLocale(),
                    ':default_sort_field' => 'added_at',
                    ':default_sort_direction' => 'desc',
                ]);
            } else {
                $insertStmt = $pdo->prepare(
                    'INSERT INTO users(
                        username, email, password_hash, role, is_enabled, api_token, ui_theme, ui_theme_updated_at, ui_locale, ui_sort_field, ui_sort_direction, is_default, created_at, updated_at
                     )
                     VALUES(
                        :username, :email, :password_hash, :role, 1, :api_token, :ui_theme, CURRENT_TIMESTAMP, :ui_locale, :ui_sort_field, :ui_sort_direction, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                     )'
                );
                $insertStmt->execute([
                    ':username' => $normalizedUsername,
                    ':email' => $normalizedEmail,
                    ':password_hash' => $passwordHash,
                    ':role' => self::ROLE_ADMIN,
                    ':api_token' => bin2hex(random_bytes(24)),
                    ':ui_theme' => $this->getDefaultTheme(),
                    ':ui_locale' => $this->getDefaultLocale(),
                    ':ui_sort_field' => 'added_at',
                    ':ui_sort_direction' => 'desc',
                ]);
            }

            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (\Throwable) {
            }

            throw $e;
        }
    }

    public function ensureBootstrapUserSeeded(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $credentials = $this->loadBootstrapCredentials();
        if ($credentials === null) {
            return;
        }

        $pdo = $this->getPdo();
        $defaultAdminExists = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE id = 1')->fetchColumn() > 0;
        if ($defaultAdminExists) {
            return;
        }

        $hasAnyUser = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
        if ($hasAnyUser) {
            return;
        }

        $this->ensureBootstrapUser();
    }

    public function ensureSettingsDatabaseReady(): void
    {
        $this->getPdo();
    }

    private function loadBootstrapCredentials(): ?array
    {
        $config = ScanService::loadConfig($this->appRoot);
        $username = ScanService::readSetting(
            $config,
            ['BOOKS_AUTH_USERNAME', 'AUTH_USERNAME'],
            'AUTH_USERNAME'
        );
        $password = ScanService::readSetting(
            $config,
            ['BOOKS_AUTH_PASSWORD', 'AUTH_PASSWORD'],
            'AUTH_PASSWORD'
        );
        $email = ScanService::readSetting(
            $config,
            ['BOOKS_AUTH_EMAIL', 'AUTH_EMAIL'],
            'AUTH_EMAIL',
            ''
        );

        if ($username === null || trim($username) === '' || $password === null || trim($password) === '') {
            return null;
        }

        return [
            'username' => trim((string) $username),
            'password' => trim((string) $password),
            'email' => (string) $email,
        ];
    }

    public function hasAnyUser(): bool
    {
        return (int) $this->getPdo()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    }

    public function isUsernameDisabled(string $username): bool
    {
        $username = trim($username);
        if ($username === '') {
            return false;
        }

        $stmt = $this->getPdo()->prepare(
            'SELECT is_enabled
             FROM users
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute([':username' => $username]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return false;
        }

        return (int) $value !== 1;
    }

    public function getSettingsDbPath(): string
    {
        $value = ScanService::readSetting(
            ScanService::loadConfig($this->appRoot),
            ['BOOKS_AUTH_SETTINGS_DB_PATH', 'AUTH_SETTINGS_DB_PATH'],
            'AUTH_SETTINGS_DB_PATH',
            'data/auth_settings.sqlite'
        );

        if ($value === null || trim($value) === '') {
            $value = 'data/auth_settings.sqlite';
        }

        return ScanService::resolvePath($this->appRoot, trim($value));
    }

    private function getPdo(): \PDO
    {
        if ($this->pdo instanceof \PDO) {
            return $this->pdo;
        }

        $dbPath = $this->getSettingsDbPath();
        $directory = dirname($dbPath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create auth settings directory: {$directory}");
        }
        @chmod($directory, 0777);

        if (!is_file($dbPath)) {
            @touch($dbPath);
        }
        @chmod($dbPath, 0666);
        clearstatcache(true, $dbPath);
        if (!is_writable($dbPath)) {
            throw new \RuntimeException("Auth settings DB is not writable: {$dbPath}");
        }

        $hadExistingDb = is_file($dbPath) && (int) @filesize($dbPath) > 0;

        $runner = new MigrationRunner($this->appRoot);
        $lockHandle = $this->acquireSettingsDbLock();

        try {
            try {
                $this->pdo = $this->openSettingsPdo($dbPath);
                $this->assertSettingsDatabaseHealthy($this->pdo, $dbPath);
                $runner->migrateAuth($this->pdo);
                $this->verifySettingsSchema($this->pdo);

                return $this->pdo;
            } catch (\Throwable $e) {
                if (MigrationRunner::isChecksumMismatchException($e)) {
                    $this->pdo = null;
                    $backups = $runner->recoverVersionMismatch('auth');
                    $this->logVersionMismatchRecovery('auth', $dbPath, $backups, $e);
                    $this->pdo = $this->openSettingsPdo($dbPath);
                    (new MigrationRunner($this->appRoot))->migrateAuth($this->pdo);
                    $this->verifySettingsSchema($this->pdo);

                    return $this->pdo;
                }

                if (!$hadExistingDb) {
                    throw $e;
                }

                $backupPath = $this->backupBrokenSettingsDatabase($dbPath);
                $this->logBrokenSettingsDatabase($dbPath, $backupPath, $e);
                $this->pdo = null;
                $runner->resetTargetMigrationRecords('auth');

                if (is_file($dbPath) && !@unlink($dbPath)) {
                    throw new \RuntimeException("Cannot remove broken auth settings DB: {$dbPath}", 0, $e);
                }

                $this->recreateSettingsDatabaseFile($dbPath, $e);

                $this->pdo = $this->openSettingsPdo($dbPath);
                (new MigrationRunner($this->appRoot))->migrateAuth($this->pdo);
                $this->verifySettingsSchema($this->pdo);
            }

            return $this->pdo;
        } finally {
            $this->releaseSettingsDbLock($lockHandle);
        }
    }

    private function openSettingsPdo(string $dbPath): \PDO
    {
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function assertSettingsDatabaseHealthy(\PDO $pdo, string $dbPath): void
    {
        $result = $pdo->query('PRAGMA integrity_check')->fetchColumn();
        if (!is_string($result) || strtolower(trim($result)) !== 'ok') {
            $message = is_scalar($result) ? (string) $result : 'unknown';
            throw new \RuntimeException("Auth settings DB integrity check failed: {$dbPath}; result={$message}");
        }
    }

    private function verifySettingsSchema(\PDO $pdo): void
    {
        $this->pdo = $pdo;
        $this->assertTableExists('users');
        $this->assertTableExists('app_settings');
    }

    private function backupBrokenSettingsDatabase(string $dbPath): string
    {
        $timestamp = date('YmdHis');
        $backupPath = $dbPath . '.broken.' . $timestamp;
        $counter = 1;
        while (file_exists($backupPath)) {
            $backupPath = $dbPath . '.broken.' . $timestamp . '.' . $counter;
            $counter++;
        }

        if (!@rename($dbPath, $backupPath)) {
            throw new \RuntimeException("Cannot backup broken auth settings DB: {$dbPath}");
        }

        return $backupPath;
    }

    private function logBrokenSettingsDatabase(string $dbPath, string $backupPath, \Throwable $e): void
    {
        error_log(sprintf(
            '[bookslib][auth-db-recovery] broken auth settings DB detected. source=%s backup=%s error=%s',
            $dbPath,
            $backupPath,
            $e->getMessage()
        ));
    }

    /**
     * @param array{target_backup:?string} $backups
     */
    private function logVersionMismatchRecovery(string $targetDb, string $dbPath, array $backups, \Throwable $e): void
    {
        error_log(sprintf(
            '[bookslib][migration-recovery] checksum mismatch detected. target=%s source=%s backup=%s error=%s',
            $targetDb,
            $dbPath,
            (string) ($backups['target_backup'] ?? ''),
            $e->getMessage()
        ));
    }

    private function recreateSettingsDatabaseFile(string $dbPath, \Throwable $e): void
    {
        if (!@touch($dbPath)) {
            throw new \RuntimeException("Cannot recreate auth settings DB file: {$dbPath}", 0, $e);
        }
        @chmod($dbPath, 0666);
        clearstatcache(true, $dbPath);
    }

    /**
     * @return resource
     */
    private function acquireSettingsDbLock()
    {
        $lockPath = dirname($this->getSettingsDbPath()) . DIRECTORY_SEPARATOR . 'auth_settings.init.lock';
        $handle = fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open auth settings lock file: ' . $lockPath);
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new \RuntimeException('Cannot lock auth settings DB initialization: ' . $lockPath);
        }

        $this->settingsDbLockHandle = $handle;

        return $handle;
    }

    /**
     * @param resource $handle
     */
    private function releaseSettingsDbLock($handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
        $this->settingsDbLockHandle = null;
    }

    public function getDefaultTheme(): string
    {
        $value = ScanService::readSetting(
            ScanService::loadConfig($this->appRoot),
            ['BOOKS_SITE_DEFAULT_THEME', 'SITE_DEFAULT_THEME'],
            'SITE_DEFAULT_THEME',
            'light'
        );

        return $this->normalizeTheme((string) $value, 'light');
    }

    public function resolvePreferredLocaleForBoot(): string
    {
        $defaultLocale = $this->getDefaultLocale();
        if (!$this->isEnabled()) {
            return $defaultLocale;
        }

        $this->ensureSessionStarted();
        $userId = (int) ($_SESSION['auth_user_id'] ?? 0);
        if ($userId < 1) {
            return $defaultLocale;
        }

        $stmt = $this->getPdo()->prepare(
            'SELECT ui_locale
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $userLocale = $stmt->fetchColumn();

        return $this->normalizeLocale(is_scalar($userLocale) ? (string) $userLocale : '', $defaultLocale);
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
            throw new \RuntimeException('Required auth table missing after migration: ' . $tableName);
        }
    }

    private function normalizeLocale(string $locale, string $fallback = 'zhTW'): string
    {
        $candidate = strtolower(str_replace(['-', '_'], '', trim($locale)));

        return match ($candidate) {
            'en', 'enus', 'engb' => 'en',
            'zhtw', 'tw', 'zhhant', 'zhtraditional' => 'zhTW',
            default => $fallback,
        };
    }

    private function getSecretKey(): string
    {
        return $this->getSecretKeys()[0];
    }

    private function getSecretKeys(): array
    {
        if ($this->secretKeys !== null && $this->secretKeys !== []) {
            return $this->secretKeys;
        }

        $config = ScanService::loadConfig($this->appRoot);
        $envSecret = ScanService::readSetting(
            $config,
            ['BOOKS_AUTH_SECRET_KEY', 'AUTH_SECRET_KEY'],
            'AUTH_SECRET_KEY'
        );

        $dataDir = $this->appRoot . DIRECTORY_SEPARATOR . 'data';
        $keyPath = $dataDir . DIRECTORY_SEPARATOR . 'auth.key';
        $legacyKeyPath = $dataDir . DIRECTORY_SEPARATOR . 'auth.key.legacy';
        $legacySecretPath = $dataDir . DIRECTORY_SEPARATOR . 'auth.secret';

        $fileKey = '';
        if (is_file($keyPath)) {
            $fileKey = trim((string) file_get_contents($keyPath));
        }

        $legacyKeys = $this->readLegacyKeys($legacyKeyPath);

        if ($envSecret !== null && trim($envSecret) !== '') {
            $currentKey = trim($envSecret);

            // env 有設定時，強制寫入 key 檔
            if (file_put_contents($keyPath, $currentKey . PHP_EOL) === false) {
                throw new \RuntimeException("Cannot write auth key file: {$keyPath}");
            }

            if ($fileKey !== '' && $fileKey !== $currentKey) {
                $legacyKeys = $this->prependUniqueKey($legacyKeys, $fileKey);
            }

            $this->writeLegacyKeys($legacyKeyPath, $legacyKeys);
            $this->secretKeys = array_merge([$currentKey], $legacyKeys);
            $this->secretKey = $currentKey;

            return $this->secretKeys;
        }

        $currentKey = $fileKey;
        if ($currentKey === '' && is_file($legacySecretPath)) {
            $legacySecret = trim((string) file_get_contents($legacySecretPath));
            if ($legacySecret !== '') {
                $currentKey = $legacySecret;
                @file_put_contents($keyPath, $currentKey . PHP_EOL);
            }
        }

        if ($currentKey === '') {
            $currentKey = bin2hex(random_bytes(32));
            if (file_put_contents($keyPath, $currentKey . PHP_EOL) === false) {
                throw new \RuntimeException("Cannot write auth key file: {$keyPath}");
            }
        }

        $this->secretKeys = array_merge([$currentKey], array_values(array_filter(
            $legacyKeys,
            static fn (string $key): bool => $key !== '' && $key !== $currentKey
        )));
        $this->secretKey = $currentKey;

        return $this->secretKeys;
    }

    private function readLegacyKeys(string $legacyKeyPath): array
    {
        if (!is_file($legacyKeyPath)) {
            return [];
        }

        $lines = file($legacyKeyPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $keys = [];
        foreach ($lines as $line) {
            $normalized = trim((string) $line);
            if ($normalized === '') {
                continue;
            }

            if (in_array($normalized, $keys, true)) {
                continue;
            }

            $keys[] = $normalized;
        }

        return $keys;
    }

    private function writeLegacyKeys(string $legacyKeyPath, array $keys): void
    {
        $normalized = [];
        foreach ($keys as $key) {
            $candidate = trim((string) $key);
            if ($candidate === '' || in_array($candidate, $normalized, true)) {
                continue;
            }
            $normalized[] = $candidate;
        }

        if ($normalized === []) {
            @unlink($legacyKeyPath);
            return;
        }

        $limited = array_slice($normalized, 0, 5);
        @file_put_contents($legacyKeyPath, implode(PHP_EOL, $limited) . PHP_EOL);
    }

    private function prependUniqueKey(array $keys, string $newKey): array
    {
        $normalizedNewKey = trim($newKey);
        if ($normalizedNewKey === '') {
            return $keys;
        }

        $filtered = array_values(array_filter(
            $keys,
            static fn (string $key): bool => trim($key) !== '' && trim($key) !== $normalizedNewKey
        ));

        array_unshift($filtered, $normalizedNewKey);

        return $filtered;
    }

    private function decodeStringList(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $this->normalizeStringList($decoded);
    }

    private function encodeStringList(array $values): string
    {
        return json_encode($this->normalizeStringList($values), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private function normalizeStringList(array $values): array
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

        sort($normalized, SORT_NATURAL | SORT_FLAG_CASE);

        return $normalized;
    }

    private function pepper(string $password): string
    {
        return $this->pepperWithKey($password, $this->getSecretKey());
    }

    private function resolveMatchedSecretKey(string $password, string $passwordHash): ?string
    {
        foreach ($this->getSecretKeys() as $key) {
            if (!password_verify($this->pepperWithKey($password, $key), $passwordHash)) {
                continue;
            }

            return $key;
        }

        return null;
    }

    private function pepperWithKey(string $password, string $key): string
    {
        return hash_hmac('sha256', $password, $key);
    }

    private function shouldForceDefaultAdminPasswordChange(array $user, string $username, string $password): bool
    {
        if ($this->normalizeRole((string) ($user['role'] ?? self::ROLE_USER)) !== self::ROLE_ADMIN) {
            return false;
        }

        if ((int) ($user['is_default'] ?? 0) !== 1) {
            return false;
        }

        if ($this->isBootstrapCredentialDefinedInRuntimeEnv()) {
            return false;
        }

        $credentials = $this->getBootstrapCredentials();
        $bootstrapUsername = trim((string) ($credentials['username'] ?? ''));
        $bootstrapPassword = trim((string) ($credentials['password'] ?? ''));

        if ($bootstrapUsername === '' || $bootstrapPassword === '') {
            return false;
        }

        return $username === $bootstrapUsername && trim($password) === $bootstrapPassword;
    }

    private function getBootstrapCredentials(): array
    {
        $config = ScanService::loadConfig($this->appRoot);

        return [
            'username' => (string) (ScanService::readSetting(
                $config,
                ['BOOKS_AUTH_USERNAME', 'AUTH_USERNAME'],
                'AUTH_USERNAME',
                ''
            ) ?? ''),
            'password' => (string) (ScanService::readSetting(
                $config,
                ['BOOKS_AUTH_PASSWORD', 'AUTH_PASSWORD'],
                'AUTH_PASSWORD',
                ''
            ) ?? ''),
        ];
    }

    private function isBootstrapCredentialDefinedInRuntimeEnv(): bool
    {
        foreach (['BOOKS_AUTH_USERNAME', 'AUTH_USERNAME', 'BOOKS_AUTH_PASSWORD', 'AUTH_PASSWORD'] as $envKey) {
            $value = getenv($envKey);
            if ($value !== false && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function assertValidPassword(string $password, string $label = ''): void
    {
        if ($label === '') {
            $label = Lang::t('auth.label.password');
        }

        if ($password === '') {
            throw new \RuntimeException(Lang::t('error.password_empty', ['label' => $label]));
        }

        $length = mb_strlen($password);
        if ($length < 6 || $length > 32) {
            throw new \RuntimeException(Lang::t('error.password_length', ['label' => $label]));
        }

        if (!preg_match('/[0-9]/', $password)) {
            throw new \RuntimeException(Lang::t('error.password_require_digit', ['label' => $label]));
        }

        if (!preg_match('/[a-z]/', $password)) {
            throw new \RuntimeException(Lang::t('error.password_require_lower', ['label' => $label]));
        }

        if (!preg_match('/[A-Z]/', $password)) {
            throw new \RuntimeException(Lang::t('error.password_require_upper', ['label' => $label]));
        }

        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            throw new \RuntimeException(Lang::t('error.password_require_symbol', ['label' => $label]));
        }
    }

    private function normalizeMaxLoginAttempts(int $value): int
    {
        return max(0, min(100, $value));
    }

    private function normalizeEmail(string $email): string
    {
        $normalized = trim($email);
        if ($normalized === '') {
            return '';
        }

        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException(Lang::t('error.email_invalid'));
        }

        return $normalized;
    }

    private function normalizeUsername(string $username): string
    {
        $normalizedUsername = trim($username);
        if ($normalizedUsername === '') {
            throw new \RuntimeException(Lang::t('error.username_empty'));
        }

        if (!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $normalizedUsername)) {
            throw new \RuntimeException(Lang::t('error.username_format'));
        }

        return $normalizedUsername;
    }

    private function normalizeRole(string $role): string
    {
        $normalized = strtolower(trim($role));
        if ($normalized === self::ROLE_ADMIN) {
            return self::ROLE_ADMIN;
        }

        return self::ROLE_USER;
    }

    private function normalizeTheme(string $theme, string $fallback): string
    {
        $normalized = strtolower(trim($theme));
        if ($normalized === 'dark' || $normalized === 'night') {
            return 'dark';
        }
        if ($normalized === 'light' || $normalized === 'day') {
            return 'light';
        }

        return $fallback === 'dark' ? 'dark' : 'light';
    }

    /**
     * @return array{theme:string,timestamp:int}
     */
    private function readThemeCookieState(): array
    {
        $defaultTheme = $this->getDefaultTheme();
        $cookieTheme = (string) ($_COOKIE[self::THEME_COOKIE_NAME] ?? '');
        $normalizedTheme = $cookieTheme !== '' ? $this->normalizeTheme($cookieTheme, $defaultTheme) : '';
        $timestamp = max(0, (int) ($_COOKIE[self::THEME_COOKIE_TS_NAME] ?? 0));

        return [
            'theme' => $normalizedTheme,
            'timestamp' => $timestamp,
        ];
    }

    private function parseStoredTimestamp(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $timestamp = strtotime($value . (str_contains($value, 'T') ? '' : ' UTC'));
        if ($timestamp === false) {
            $timestamp = strtotime($value);
        }

        return $timestamp === false ? 0 : max(0, (int) $timestamp);
    }

    private function updateThemeForUserId(int $userId, string $theme, int $timestamp): string
    {
        if ($userId < 1) {
            return $this->normalizeTheme($theme, $this->getDefaultTheme());
        }

        $normalizedTheme = $this->normalizeTheme($theme, $this->getDefaultTheme());
        $storedTimestamp = gmdate('Y-m-d H:i:s', max(0, $timestamp));
        $stmt = $this->getPdo()->prepare(
            'UPDATE users
             SET ui_theme = :ui_theme,
                 ui_theme_updated_at = :ui_theme_updated_at,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $userId,
            ':ui_theme' => $normalizedTheme,
            ':ui_theme_updated_at' => $storedTimestamp,
        ]);

        return $normalizedTheme;
    }

    private function normalizeSortField(string $sortField, string $fallback): string
    {
        $normalized = strtolower(trim($sortField));
        if (in_array($normalized, self::ALLOWED_SORT_FIELDS, true)) {
            return $normalized;
        }

        return in_array($fallback, self::ALLOWED_SORT_FIELDS, true) ? $fallback : 'added_at';
    }

    private function normalizeSortDirection(string $sortDirection, string $fallback): string
    {
        $normalized = strtolower(trim($sortDirection));
        if ($normalized === 'asc' || $normalized === 'desc') {
            return $normalized;
        }

        return $fallback === 'asc' ? 'asc' : 'desc';
    }

    private function persistSortCookies(string $sortField, string $sortDirection): void
    {
        if (headers_sent()) {
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);

        setcookie(self::SORT_FIELD_COOKIE_NAME, $sortField, [
            'expires' => time() + self::SORT_COOKIE_TTL,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);

        setcookie(self::SORT_DIRECTION_COOKIE_NAME, $sortDirection, [
            'expires' => time() + self::SORT_COOKIE_TTL,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    private function ensureSessionStarted(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}
