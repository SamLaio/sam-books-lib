<?php

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$assetUrl = static function (string $relativePath): string {
    $relativePath = ltrim($relativePath, '/');
    $assetPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $version = is_file($assetPath) ? (string) filemtime($assetPath) : (string) time();

    return $relativePath . '?v=' . rawurlencode($version);
};

$resolvedTheme = 'light';
$themePersistToServer = false;
$resolvedLocale = \Calibre\Support\Lang::currentLocale();
$htmlLang = $resolvedLocale === 'en' ? 'en' : 'zh-Hant';
$themeCookieName = \Calibre\Services\AuthService::THEME_COOKIE_NAME;
$themeCookieTsName = \Calibre\Services\AuthService::THEME_COOKIE_TS_NAME;
$catalogStateCookieName = \Calibre\Services\AuthService::CATALOG_STATE_COOKIE_NAME;
$requestedTheme = isset($_GET['theme']) ? (string) $_GET['theme'] : null;

try {
    $authService = new \Calibre\Services\AuthService(dirname(__DIR__));
    $themePersistToServer = is_array($authService->getCurrentUser());
    $resolvedTheme = $authService->resolvePreferredTheme($requestedTheme);
} catch (\Throwable) {
    $themePersistToServer = false;
    if (is_string($requestedTheme)) {
        $normalizedRequestedTheme = strtolower(trim($requestedTheme));
        if (in_array($normalizedRequestedTheme, ['light', 'dark'], true)) {
            $resolvedTheme = $normalizedRequestedTheme;
        }
    } elseif (isset($_COOKIE[$themeCookieName])) {
        $cookieTheme = strtolower(trim((string) $_COOKIE[$themeCookieName]));
        if (in_array($cookieTheme, ['light', 'dark'], true)) {
            $resolvedTheme = $cookieTheme;
        }
    }
}
?>
<!doctype html>
<html lang="<?= $escape($htmlLang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $escape($pageTitle ?? $siteTitle ?? $t('layout.default_title')) ?></title>
  <link rel="icon" href="<?= $escape($assetUrl('favicon.ico')) ?>" sizes="any">
  <link rel="shortcut icon" href="<?= $escape($assetUrl('favicon.ico')) ?>">
  <link rel="stylesheet" href="<?= $escape($assetUrl('assets/css/app.css')) ?>">
  <?php if (isset($pageStyles) && is_array($pageStyles)): ?>
    <?php foreach ($pageStyles as $stylePath): ?>
      <?php if (is_string($stylePath) && trim($stylePath) !== ''): ?>
        <link rel="stylesheet" href="<?= $escape($assetUrl($stylePath)) ?>">
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>
  <script src="<?= $escape($assetUrl('assets/js/catalog.js')) ?>" defer></script>
  <?php if (isset($pageScripts) && is_array($pageScripts)): ?>
    <?php foreach ($pageScripts as $scriptPath): ?>
      <?php if (is_string($scriptPath) && trim($scriptPath) !== ''): ?>
        <script src="<?= $escape($assetUrl($scriptPath)) ?>" defer></script>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>
</head>
<body data-theme="<?= $escape($resolvedTheme) ?>">
<?= $content ?? '' ?>
<button
  type="button"
  class="theme-fab theme-fab--global"
  data-theme-toggle
  data-theme-update-url="<?= $escape((isset($themeUpdateAction) && is_string($themeUpdateAction) && trim($themeUpdateAction) !== '') ? $themeUpdateAction : 'theme.php') ?>"
  data-theme-persist="<?= $themePersistToServer ? '1' : '0' ?>"
  data-theme-cookie-enabled="1"
  data-theme-cookie-name="<?= $escape($themeCookieName) ?>"
  data-theme-cookie-ts-name="<?= $escape($themeCookieTsName) ?>"
  data-catalog-state-cookie-name="<?= $escape($catalogStateCookieName) ?>"
  aria-label="<?= $escape($t('layout.toggle_theme')) ?>"
  title="<?= $escape($t('layout.toggle_theme')) ?>"
>
  <span class="theme-fab__icon" data-theme-toggle-icon>☾</span>
</button>
</body>
</html>
