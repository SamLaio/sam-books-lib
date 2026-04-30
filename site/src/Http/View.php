<?php

namespace Calibre\Http;

use Calibre\Support\Lang;

final class View
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
    }

    public function render(string $template, array $data = []): string
    {
        $templatePath = $this->resolvePath($template);
        $t = static fn(string $key, array $replace = []): string => Lang::instance()->get($key, $replace);

        extract($data, EXTR_SKIP);

        ob_start();
        require $templatePath;

        return (string) ob_get_clean();
    }

    public function renderPage(string $template, array $data = [], string $layout = 'layout'): string
    {
        $content = $this->render($template, $data);

        return $this->render($layout, $data + ['content' => $content]);
    }

    private function resolvePath(string $template): string
    {
        $path = $this->basePath
            . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $template)
            . '.php';

        if (!is_file($path)) {
            throw new \RuntimeException("View template not found: {$template}");
        }

        return $path;
    }
}
