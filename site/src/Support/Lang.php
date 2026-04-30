<?php

namespace Calibre\Support;

use Calibre\ScanService;

final class Lang
{
    private const DEFAULT_LOCALE = 'zhTW';

    /**
     * @var array<string, array<string, string>>
     */
    private static array $catalog = [];

    private static ?self $instance = null;

    private string $appRoot;
    private string $locale;

    /**
     * @var array<string, string>
     */
    private array $messages;

    public function __construct(string $appRoot, ?string $locale = null)
    {
        $this->appRoot = rtrim($appRoot, DIRECTORY_SEPARATOR);
        $this->locale = $this->resolveLocale($locale);
        $this->messages = $this->loadMessages($this->locale);
    }

    public static function boot(string $appRoot, ?string $locale = null): void
    {
        self::$instance = new self($appRoot, $locale);
    }

    public static function instance(?string $appRoot = null, ?string $locale = null): self
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        $resolvedRoot = $appRoot ?? dirname(__DIR__, 2);
        self::$instance = new self($resolvedRoot, $locale);

        return self::$instance;
    }

    public static function t(string $key, array $replace = []): string
    {
        return self::instance()->get($key, $replace);
    }

    public static function currentLocale(): string
    {
        return self::instance()->locale;
    }

    public function get(string $key, array $replace = []): string
    {
        $message = $this->messages[$key] ?? self::loadMessages(self::DEFAULT_LOCALE)[$key] ?? $key;
        if ($replace === []) {
            return $message;
        }

        $map = [];
        foreach ($replace as $name => $value) {
            $map['{' . $name . '}'] = (string) $value;
        }

        return strtr($message, $map);
    }

    private function resolveLocale(?string $locale): string
    {
        $candidate = trim((string) $locale);
        if ($candidate === '') {
            $config = ScanService::loadConfig($this->appRoot);
            $candidate = (string) (ScanService::readSetting(
                $config,
                ['BOOKS_LOCALE', 'APP_LOCALE', 'LOCALE'],
                'APP_LOCALE',
                self::DEFAULT_LOCALE
            ) ?? self::DEFAULT_LOCALE);
        }

        $normalized = strtolower(str_replace(['-', '_'], '', $candidate));

        return match ($normalized) {
            'en', 'enus', 'engb' => 'en',
            'zhtw', 'tw', 'zhhant', 'zhtraditional' => 'zhTW',
            default => self::DEFAULT_LOCALE,
        };
    }

    /**
     * @return array<string, string>
     */
    private static function loadMessages(string $locale): array
    {
        if (isset(self::$catalog[$locale])) {
            return self::$catalog[$locale];
        }

        $path = __DIR__ . DIRECTORY_SEPARATOR . 'Lang' . DIRECTORY_SEPARATOR . $locale . '.php';
        if (!is_file($path)) {
            $path = __DIR__ . DIRECTORY_SEPARATOR . 'Lang' . DIRECTORY_SEPARATOR . self::DEFAULT_LOCALE . '.php';
        }

        $messages = require $path;
        if (!is_array($messages)) {
            $messages = [];
        }

        self::$catalog[$locale] = $messages;

        return $messages;
    }
}
