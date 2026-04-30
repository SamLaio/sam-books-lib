<?php

namespace Calibre\Services;

final class LoginCaptchaService
{
    private const SESSION_KEY = 'auth_login_captcha';
    private const CODE_LENGTH = 5;
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function ensureChallenge(): void
    {
        $this->ensureSessionStarted();

        $stored = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($stored) || !is_string($stored['code'] ?? null) || trim((string) $stored['code']) === '') {
            $this->rotateChallenge();
        }
    }

    public function rotateChallenge(): string
    {
        $this->ensureSessionStarted();
        $code = $this->generateCode();

        $_SESSION[self::SESSION_KEY] = [
            'code' => $code,
            'issued_at' => time(),
        ];

        return $code;
    }

    public function getCode(): string
    {
        $this->ensureChallenge();

        return (string) ($_SESSION[self::SESSION_KEY]['code'] ?? '');
    }

    public function validateAndRotate(string $input): bool
    {
        $this->ensureSessionStarted();

        $storedCode = '';
        $stored = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_array($stored) && is_string($stored['code'] ?? null)) {
            $storedCode = strtoupper(trim((string) $stored['code']));
        }

        $normalizedInput = strtoupper(trim($input));
        $isValid = $storedCode !== '' && hash_equals($storedCode, $normalizedInput);

        $this->rotateChallenge();

        return $isValid;
    }

    public function renderSvg(string $code): string
    {
        $safeCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code) ?? '');
        if ($safeCode === '') {
            $safeCode = 'ERROR';
        }

        $width = 160;
        $height = 52;
        $chars = str_split($safeCode);

        $text = '';
        foreach ($chars as $index => $char) {
            $x = 18 + ($index * 27);
            $y = random_int(32, 40);
            $rotate = random_int(-15, 15);
            $size = random_int(22, 28);
            $text .= sprintf(
                '<text x="%d" y="%d" font-size="%d" transform="rotate(%d %d %d)" fill="#1a2942">%s</text>',
                $x,
                $y,
                $size,
                $rotate,
                $x,
                $y,
                htmlspecialchars($char, ENT_QUOTES, 'UTF-8')
            );
        }

        $noise = '';
        for ($i = 0; $i < 7; $i++) {
            $x1 = random_int(0, $width);
            $y1 = random_int(0, $height);
            $x2 = random_int(0, $width);
            $y2 = random_int(0, $height);
            $opacity = random_int(25, 45) / 100;
            $noise .= sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#5f7ea6" stroke-opacity="%.2f" stroke-width="1.2"/>',
                $x1,
                $y1,
                $x2,
                $y2,
                $opacity
            );
        }

        $dots = '';
        for ($i = 0; $i < 30; $i++) {
            $cx = random_int(0, $width);
            $cy = random_int(0, $height);
            $r = random_int(1, 2);
            $opacity = random_int(20, 45) / 100;
            $dots .= sprintf(
                '<circle cx="%d" cy="%d" r="%d" fill="#89a6cc" fill-opacity="%.2f"/>',
                $cx,
                $cy,
                $r,
                $opacity
            );
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" role="img" aria-label="captcha">'
            . '<rect width="100%%" height="100%%" rx="8" ry="8" fill="#f2f7ff" stroke="#7e96bb"/>'
            . '%s%s%s'
            . '</svg>',
            $width,
            $height,
            $width,
            $height,
            $noise,
            $dots,
            $text
        );
    }

    public function outputImage(bool $refresh = false): void
    {
        if ($refresh) {
            $this->rotateChallenge();
        }

        $code = $this->getCode();

        header('Content-Type: image/svg+xml; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $this->renderSvg($code);
    }

    private function generateCode(): string
    {
        $alphabet = self::CODE_ALPHABET;
        $maxIndex = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $maxIndex)];
        }

        return $code;
    }

    private function ensureSessionStarted(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}
