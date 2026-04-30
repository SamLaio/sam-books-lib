<?php

namespace Calibre\Services;

use Calibre\Support\Lang;

final class SmtpMailer
{
    private string $host;
    private int $port;
    private string $encryption;
    private string $username;
    private string $password;
    private int $timeoutSeconds;

    /** @var resource|null */
    private $socket = null;

    public function __construct(
        string $host,
        int $port,
        string $encryption = 'none',
        string $username = '',
        string $password = '',
        int $timeoutSeconds = 15
    ) {
        $this->host = trim($host);
        $this->port = $port;
        $this->encryption = strtolower(trim($encryption));
        $this->username = trim($username);
        $this->password = $password;
        $this->timeoutSeconds = max(5, $timeoutSeconds);

        if ($this->host === '') {
            throw new \RuntimeException(Lang::t('error.smtp_host_missing'));
        }

        if ($this->port < 1 || $this->port > 65535) {
            throw new \RuntimeException(Lang::t('error.smtp_port_invalid'));
        }

        if (!in_array($this->encryption, ['none', 'tls', 'ssl'], true)) {
            throw new \RuntimeException(Lang::t('error.smtp_encryption_invalid'));
        }
    }

    public function send(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $subject,
        string $bodyText
    ): void {
        $this->sendWithAttachments($fromEmail, $fromName, $toEmail, $subject, $bodyText, []);
    }

    /**
     * @param array<int, array{name:string,path:string,mime_type?:string}> $attachments
     */
    public function sendWithAttachments(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $subject,
        string $bodyText,
        array $attachments
    ): void {
        $fromEmail = trim($fromEmail);
        $toEmail = trim($toEmail);
        $subject = trim($subject);

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException(Lang::t('error.from_email_invalid'));
        }
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException(Lang::t('error.to_email_invalid'));
        }
        if ($subject === '') {
            throw new \RuntimeException(Lang::t('error.subject_empty'));
        }

        $this->connect();

        try {
            $this->expect([220]);
            $this->sendEhlo();

            if ($this->encryption === 'tls') {
                $this->sendLine('STARTTLS');
                $this->expect([220]);
                $this->enableTls();
                $this->sendEhlo();
            }

            if ($this->username !== '') {
                $this->sendLine('AUTH LOGIN');
                $this->expect([334]);
                $this->sendLine(base64_encode($this->username));
                $this->expect([334]);
                $this->sendLine(base64_encode($this->password));
                $this->expect([235]);
            }

            $this->sendLine('MAIL FROM:<' . $fromEmail . '>');
            $this->expect([250]);

            $this->sendLine('RCPT TO:<' . $toEmail . '>');
            $this->expect([250, 251]);

            $this->sendLine('DATA');
            $this->expect([354]);

            if ($attachments === []) {
                $this->sendData($this->buildMessage($fromEmail, $fromName, $toEmail, $subject, $bodyText));
            } else {
                $this->sendMultipartData($fromEmail, $fromName, $toEmail, $subject, $bodyText, $attachments);
            }
            $this->expect([250]);

            $this->sendLine('QUIT');
        } finally {
            $this->close();
        }
    }

    private function connect(): void
    {
        $transport = $this->encryption === 'ssl'
            ? 'ssl://' . $this->host
            : 'tcp://' . $this->host;

        $socket = @stream_socket_client(
            $transport . ':' . $this->port,
            $errorCode,
            $errorMessage,
            $this->timeoutSeconds
        );

        if (!is_resource($socket)) {
            throw new \RuntimeException(Lang::t('error.smtp_connect_failed', [
                'message' => (string) $errorMessage,
                'code' => (string) (int) $errorCode,
            ]));
        }

        stream_set_timeout($socket, $this->timeoutSeconds);
        $this->socket = $socket;
    }

    private function enableTls(): void
    {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException(Lang::t('error.smtp_connection_not_initialized'));
        }

        $method = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')
            ? STREAM_CRYPTO_METHOD_TLS_CLIENT
            : STREAM_CRYPTO_METHOD_ANY_CLIENT;

        $ok = @stream_socket_enable_crypto($this->socket, true, $method);
        if ($ok !== true) {
            throw new \RuntimeException(Lang::t('error.smtp_tls_failed'));
        }
    }

    private function sendEhlo(): void
    {
        $hostname = gethostname();
        if (!is_string($hostname) || trim($hostname) === '') {
            $hostname = 'localhost';
        }

        $this->sendLine('EHLO ' . $hostname);
        $this->expect([250]);
    }

    private function sendLine(string $line): void
    {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException(Lang::t('error.smtp_connection_not_initialized'));
        }

        $written = @fwrite($this->socket, $line . "\r\n");
        if ($written === false) {
            throw new \RuntimeException(Lang::t('error.smtp_write_failed'));
        }
    }

    private function sendData(string $data): void
    {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException(Lang::t('error.smtp_connection_not_initialized'));
        }

        $payload = str_replace(["\r\n", "\r"], "\n", $data);
        $payload = str_replace("\n", "\r\n", $payload);
        $payload = preg_replace('/^\./m', '..', $payload);
        if (!is_string($payload)) {
            throw new \RuntimeException(Lang::t('error.smtp_payload_process_failed'));
        }

        $written = @fwrite($this->socket, $payload . "\r\n.\r\n");
        if ($written === false) {
            throw new \RuntimeException(Lang::t('error.smtp_payload_write_failed'));
        }
    }

    /**
     * @param array<int, array{name:string,path:string,mime_type?:string}> $attachments
     */
    private function sendMultipartData(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $subject,
        string $bodyText,
        array $attachments
    ): void {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException(Lang::t('error.smtp_connection_not_initialized'));
        }

        $safeSubject = str_replace(["\r", "\n"], '', trim($subject));
        $encodedSubject = '=?UTF-8?B?' . base64_encode($safeSubject) . '?=';
        $boundary = 'bookslib-' . bin2hex(random_bytes(12));
        $messageIdDomain = $this->resolveMessageIdDomain($fromEmail);
        $messageId = sprintf('<%s@%s>', bin2hex(random_bytes(16)), $messageIdDomain);

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $fromEmail,
            'To: <' . $toEmail . '>',
            'Subject: ' . $encodedSubject,
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
            '',
        ];
        $this->writeRaw(implode("\r\n", $headers) . "\r\n");

        $this->writeRaw('--' . $boundary . "\r\n");
        $this->writeRaw("Content-Type: text/plain; charset=UTF-8\r\n");
        $this->writeRaw("Content-Transfer-Encoding: 8bit\r\n\r\n");
        $normalizedBody = str_replace(["\r\n", "\r"], "\n", $bodyText);
        $bodyLines = explode("\n", $normalizedBody);
        foreach ($bodyLines as $line) {
            if (str_starts_with($line, '.')) {
                $line = '.' . $line;
            }
            $this->writeRaw($line . "\r\n");
        }

        foreach ($attachments as $attachment) {
            $path = trim((string) ($attachment['path'] ?? ''));
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                throw new \RuntimeException(Lang::t('error.attachment_missing'));
            }

            $name = trim((string) ($attachment['name'] ?? basename($path)));
            if ($name === '') {
                $name = basename($path);
            }
            $name = str_replace(["\r", "\n", '"'], ['', '', "'"], $name);
            $encodedName = rawurlencode($name);

            $this->writeRaw('--' . $boundary . "\r\n");
            $this->writeRaw("Content-Type: application/octet-stream\r\n");
            $this->writeRaw('Content-Disposition: attachment; filename*=utf-8\'\'' . $encodedName . "\r\n");
            $this->writeRaw("Content-Transfer-Encoding: base64\r\n\r\n");

            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new \RuntimeException(Lang::t('error.attachment_open_failed'));
            }

            try {
                while (!feof($handle)) {
                    // 57 bytes => 76 base64 chars per line (RFC friendly)
                    $chunk = fread($handle, 57);
                    if ($chunk === false) {
                        throw new \RuntimeException(Lang::t('error.attachment_read_failed'));
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    $this->writeRaw(base64_encode($chunk) . "\r\n");
                }
            } finally {
                fclose($handle);
            }
        }

        $this->writeRaw('--' . $boundary . "--\r\n");
        $this->writeRaw(".\r\n");
    }

    private function writeRaw(string $payload): void
    {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException(Lang::t('error.smtp_connection_not_initialized'));
        }

        $length = strlen($payload);
        $written = 0;
        while ($written < $length) {
            $result = @fwrite($this->socket, substr($payload, $written));
            if ($result === false || $result === 0) {
                throw new \RuntimeException(Lang::t('error.smtp_write_failed'));
            }
            $written += $result;
        }
    }

    private function expect(array $allowedCodes): void
    {
        [$code, $message] = $this->readResponse();

        if (!in_array($code, $allowedCodes, true)) {
            throw new \RuntimeException(Lang::t('error.smtp_response_invalid', [
                'code' => (string) $code,
                'message' => $message,
            ]));
        }
    }

    /**
     * @return array{0:int,1:string}
     */
    private function readResponse(): array
    {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException(Lang::t('error.smtp_connection_not_initialized'));
        }

        $full = '';
        $code = 0;

        while (true) {
            $line = fgets($this->socket, 8192);
            if ($line === false) {
                throw new \RuntimeException(Lang::t('error.smtp_response_read_failed'));
            }

            $full .= $line;
            $trim = rtrim($line, "\r\n");

            if (preg_match('/^(\d{3})([\s-])(.*)$/', $trim, $matches) === 1) {
                $code = (int) $matches[1];
                if ($matches[2] === ' ') {
                    break;
                }
            } else {
                break;
            }
        }

        return [$code, trim($full)];
    }

    private function buildMessage(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $subject,
        string $bodyText
    ): string {
        $safeFromName = str_replace(["\r", "\n"], '', trim($fromName));
        $safeSubject = str_replace(["\r", "\n"], '', trim($subject));
        $encodedSubject = '=?UTF-8?B?' . base64_encode($safeSubject) . '?=';
        $encodedFromName = '=?UTF-8?B?' . base64_encode($safeFromName === '' ? $fromEmail : $safeFromName) . '?=';
        $messageIdDomain = $this->resolveMessageIdDomain($fromEmail);
        $messageId = sprintf('<%s@%s>', bin2hex(random_bytes(16)), $messageIdDomain);

        $commonHeaders = [
            'Date: ' . date('r'),
            'From: ' . $encodedFromName . ' <' . $fromEmail . '>',
            'To: <' . $toEmail . '>',
            'Subject: ' . $encodedSubject,
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
        ];

        $headers = array_merge($commonHeaders, [
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ]);

        return implode("\r\n", $headers) . "\r\n\r\n" . $bodyText;
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    private function resolveMessageIdDomain(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (isset($parts[1]) && trim($parts[1]) !== '') {
            return trim($parts[1]);
        }

        $hostname = gethostname();
        if (!is_string($hostname) || trim($hostname) === '') {
            return 'localhost';
        }

        return trim($hostname);
    }
}
