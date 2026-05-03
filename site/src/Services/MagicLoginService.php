<?php

namespace Calibre\Services;

use Calibre\ScanService;
use Calibre\Support\Lang;

final class MagicLoginService
{
    private const TOKEN_TTL_SECONDS = 600;
    private const CREATE_RATE_LIMIT_WINDOW_SECONDS = 600;
    private const CREATE_RATE_LIMIT_MAX = 20;

    private AuthService $authService;
    private \PDO $pdo;

    public function __construct(private string $appRoot, ?AuthService $authService = null)
    {
        $this->appRoot = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->authService = $authService ?? new AuthService($this->appRoot);
        $this->authService->ensureSettingsDatabaseReady();
        $this->pdo = new \PDO('sqlite:' . $this->authService->getSettingsDbPath());
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @return array{token:string,login_url:string,qr_url:string,expires_at:string,expires_in:int}
     */
    public function createOrReuse(array $server): array
    {
        $this->ensureSessionStarted();
        $this->cleanupOldTokens();
        $browserNonce = $this->getBrowserNonce();
        $existingToken = (string) ($_SESSION['magic_login_token'] ?? '');
        if ($existingToken !== '') {
            $existing = $this->findUsablePendingToken($existingToken, $browserNonce);
            if (is_array($existing)) {
                return $this->formatTokenResponse($existingToken, (string) $existing['expires_at'], $server);
            }
        }

        $createdIp = $this->clientIp($server);
        $this->assertCreateRateLimit($createdIp);

        $token = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::TOKEN_TTL_SECONDS);
        $stmt = $this->pdo->prepare(
            'INSERT INTO magic_login_tokens(token_hash, browser_nonce, status, expires_at, created_at, created_ip)
             VALUES(:token_hash, :browser_nonce, "pending", :expires_at, CURRENT_TIMESTAMP, :created_ip)'
        );
        $stmt->execute([
            ':token_hash' => $this->hashToken($token),
            ':browser_nonce' => $browserNonce,
            ':expires_at' => $expiresAt,
            ':created_ip' => $createdIp,
        ]);

        $_SESSION['magic_login_token'] = $token;

        return $this->formatTokenResponse($token, $expiresAt, $server);
    }

    /**
     * @return array{status:string,redirect?:string,expires_in?:int}
     */
    public function pollStatus(string $token): array
    {
        $this->ensureSessionStarted();
        $browserNonce = $this->getBrowserNonce(false);
        if ($browserNonce === '') {
            return ['status' => 'invalid'];
        }

        $row = $this->findToken($token);
        if (!is_array($row) || !hash_equals((string) $row['browser_nonce'], $browserNonce)) {
            return ['status' => 'invalid'];
        }

        if ($this->isExpired((string) $row['expires_at'])) {
            $this->markExpired((int) $row['id']);
            return ['status' => 'expired'];
        }

        $status = (string) ($row['status'] ?? '');
        if ($status === 'authenticated') {
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId > 0 && $this->authService->loginByUserId($userId)) {
                $this->consumeToken((int) $row['id']);
                unset($_SESSION['magic_login_token']);
                return ['status' => 'authenticated', 'redirect' => 'index.php'];
            }

            return ['status' => 'invalid'];
        }

        if ($status !== 'pending') {
            return ['status' => $status === 'consumed' ? 'consumed' : 'invalid'];
        }

        return [
            'status' => 'pending',
            'expires_in' => max(0, strtotime((string) $row['expires_at'] . ' UTC') - time()),
        ];
    }

    public function getAuthorizationState(string $token): array
    {
        $row = $this->findToken($token);
        if (!is_array($row)) {
            return ['status' => 'invalid'];
        }
        if ($this->isExpired((string) $row['expires_at'])) {
            $this->markExpired((int) $row['id']);
            return ['status' => 'expired'];
        }

        return ['status' => (string) ($row['status'] ?? 'invalid')];
    }

    public function authorize(string $token, int $userId, array $server): string
    {
        $row = $this->findToken($token);
        if (!is_array($row)) {
            return 'invalid';
        }
        if ($this->isExpired((string) $row['expires_at'])) {
            $this->markExpired((int) $row['id']);
            return 'expired';
        }
        if ((string) ($row['status'] ?? '') !== 'pending') {
            return (string) ($row['status'] ?? 'invalid');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE magic_login_tokens
             SET user_id = :user_id,
                 status = "authenticated",
                 authenticated_at = CURRENT_TIMESTAMP,
                 authenticated_ip = :authenticated_ip
             WHERE id = :id AND status = "pending"'
        );
        $stmt->execute([
            ':id' => (int) $row['id'],
            ':user_id' => $userId,
            ':authenticated_ip' => (string) ($server['REMOTE_ADDR'] ?? ''),
        ]);

        return 'authenticated';
    }

    public function renderQrPng(string $token, array $server): string
    {
        $row = $this->findToken($token);
        if (!is_array($row) || $this->isExpired((string) $row['expires_at'])) {
            throw new \RuntimeException('Magic login token is unavailable.');
        }

        return (new QrPngService())->renderPng($this->buildLoginUrl($token, $server));
    }

    private function findUsablePendingToken(string $token, string $browserNonce): ?array
    {
        $row = $this->findToken($token);
        if (!is_array($row)
            || !hash_equals((string) ($row['browser_nonce'] ?? ''), $browserNonce)
            || (string) ($row['status'] ?? '') !== 'pending'
            || $this->isExpired((string) ($row['expires_at'] ?? ''))) {
            return null;
        }

        return $row;
    }

    private function findToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, token_hash, browser_nonce, user_id, status, expires_at
             FROM magic_login_tokens
             WHERE token_hash = :token_hash
             LIMIT 1'
        );
        $stmt->execute([':token_hash' => $this->hashToken($token)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array{token:string,login_url:string,qr_url:string,expires_at:string,expires_in:int}
     */
    private function formatTokenResponse(string $token, string $expiresAt, array $server): array
    {
        return [
            'token' => $token,
            'login_url' => $this->buildLoginUrl($token, $server),
            'qr_url' => 'magic_login.php?action=qr&token=' . rawurlencode($token),
            'expires_at' => $expiresAt,
            'expires_in' => max(0, strtotime($expiresAt . ' UTC') - time()),
        ];
    }

    private function buildLoginUrl(string $token, array $server): string
    {
        $configuredBaseUrl = (new ScanService($this->appRoot))->getSiteBaseUrl();
        if (is_string($configuredBaseUrl) && $configuredBaseUrl !== '') {
            return $configuredBaseUrl . '/magic_login.php?token=' . rawurlencode($token);
        }

        $host = trim((string) ($server['HTTP_HOST'] ?? ''));
        if ($host !== '' && preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $host) !== 1) {
            $host = '';
        }
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
        $script = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? '/magic_login.php'));
        $basePath = rtrim(str_replace('/magic_login.php', '', $script), '/');
        if ($host === '') {
            return ($basePath === '' ? '' : $basePath) . '/magic_login.php?token=' . rawurlencode($token);
        }

        return $scheme . '://' . $host . ($basePath === '' ? '' : $basePath) . '/magic_login.php?token=' . rawurlencode($token);
    }

    private function assertCreateRateLimit(string $createdIp): void
    {
        if ($createdIp === '') {
            return;
        }

        $windowStart = gmdate('Y-m-d H:i:s', time() - self::CREATE_RATE_LIMIT_WINDOW_SECONDS);
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM magic_login_tokens
             WHERE created_ip = :created_ip
               AND created_at >= :window_start'
        );
        $stmt->execute([
            ':created_ip' => $createdIp,
            ':window_start' => $windowStart,
        ]);

        if ((int) $stmt->fetchColumn() >= self::CREATE_RATE_LIMIT_MAX) {
            throw new \RuntimeException(Lang::t('error.magic_login_rate_limited'));
        }
    }

    private function cleanupOldTokens(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $deleteBefore = gmdate('Y-m-d H:i:s', time() - 86400);

        $this->pdo->prepare(
            'UPDATE magic_login_tokens
             SET status = "expired", consumed_at = COALESCE(consumed_at, CURRENT_TIMESTAMP)
             WHERE status = "pending" AND expires_at <= :now'
        )->execute([':now' => $now]);

        $this->pdo->prepare(
            'DELETE FROM magic_login_tokens
             WHERE status IN ("expired", "consumed")
               AND COALESCE(consumed_at, expires_at, created_at) <= :delete_before'
        )->execute([':delete_before' => $deleteBefore]);
    }

    private function clientIp(array $server): string
    {
        $ip = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        if ($ip === '' || strlen($ip) > 64) {
            return '';
        }

        return $ip;
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function isExpired(string $expiresAt): bool
    {
        return strtotime($expiresAt . ' UTC') <= time();
    }

    private function markExpired(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE magic_login_tokens
             SET status = "expired", consumed_at = COALESCE(consumed_at, CURRENT_TIMESTAMP)
             WHERE id = :id AND status = "pending"'
        );
        $stmt->execute([':id' => $id]);
    }

    private function consumeToken(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE magic_login_tokens
             SET status = "consumed", consumed_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    private function getBrowserNonce(bool $create = true): string
    {
        $nonce = (string) ($_SESSION['magic_login_browser_nonce'] ?? '');
        if ($nonce === '' && $create) {
            $nonce = bin2hex(random_bytes(16));
            $_SESSION['magic_login_browser_nonce'] = $nonce;
        }

        return $nonce;
    }

    private function ensureSessionStarted(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_start();
    }
}
