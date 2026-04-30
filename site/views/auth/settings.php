<?php

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$username = (string) (($user['username'] ?? ''));
$email = (string) (($user['email'] ?? ''));
$availableLocales = is_array($availableLocales ?? null) ? $availableLocales : ['zhTW', 'en'];
$currentLocale = (string) ($currentLocale ?? 'zhTW');
?>
<div class="wrap">
  <div class="panel">
    <h1><?= $escape($t('settings.heading')) ?></h1>
    <p class="meta">
      <?= $escape($t('settings.currently_logged_in', ['username' => $username, 'role' => !empty($isAdmin) ? $t('settings.role_admin') : $t('settings.role_user')])) ?>
      | <a class="title-home-link" href="index.php"><?= $escape($t('common.home')) ?></a>
      <?php if (!empty($isAdmin)): ?>
        | <a class="title-home-link" href="admin_settings.php"><?= $escape($t('layout.admin_settings')) ?></a>
      <?php endif; ?>
      | <a class="title-home-link" href="logout.php"><?= $escape($t('common.logout')) ?></a>
    </p>

    <?php if (($notice ?? null) !== null): ?>
      <div class="message"><?= $escape($notice) ?></div>
    <?php endif; ?>

    <?php if (($error ?? null) !== null): ?>
      <div class="error"><?= $escape($error) ?></div>
    <?php endif; ?>

    <h2><?= $escape($t('settings.profile_heading')) ?></h2>
    <form method="post" action="settings.php" class="search-form">
      <input type="hidden" name="action" value="update_profile">
      <input type="email" name="email" placeholder="<?= $escape($t('settings.email_placeholder')) ?>" value="<?= $escape($email) ?>">
      <button type="submit"><?= $escape($t('settings.update_email')) ?></button>
    </form>

    <h2><?= $escape($t('settings.locale_heading')) ?></h2>
    <form method="post" action="settings.php" class="search-form">
      <input type="hidden" name="action" value="update_locale">
      <select name="locale" aria-label="<?= $escape($t('settings.locale_label')) ?>">
        <?php foreach ($availableLocales as $localeCode): ?>
          <?php
          $normalizedLocaleCode = (string) $localeCode;
          $localeLabelKey = $normalizedLocaleCode === 'en' ? 'settings.locale_en' : 'settings.locale_zhtw';
          ?>
          <option value="<?= $escape($normalizedLocaleCode) ?>" <?= $currentLocale === $normalizedLocaleCode ? 'selected' : '' ?>>
            <?= $escape($t($localeLabelKey)) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit"><?= $escape($t('settings.update_locale')) ?></button>
    </form>

    <h2><?= $escape($t('settings.change_password_heading')) ?></h2>
    <form method="post" action="settings.php" class="search-form">
      <input type="hidden" name="action" value="change_password">
      <input type="password" name="current_password" placeholder="<?= $escape($t('settings.current_password')) ?>" required>
      <input type="password" name="password" placeholder="<?= $escape($t('settings.new_password')) ?>" required>
      <input type="password" name="confirm_password" placeholder="<?= $escape($t('settings.confirm_new_password')) ?>" required>
      <button type="submit"><?= $escape($t('settings.update_password')) ?></button>
    </form>

    <h2><?= $escape($t('settings.opds_token_heading')) ?></h2>
    <p class="meta"><?= $escape($t('settings.opds_token_hint')) ?></p>
    <div class="summary">
      <span><?= $escape($t('common.token')) ?>：<?= $escape($user['api_token'] ?? '') ?></span>
      <?php if (($opdsTokenUrl ?? '') !== ''): ?>
        <span><?= $escape($t('common.url')) ?>：<?= $escape($opdsTokenUrl) ?></span>
      <?php endif; ?>
    </div>
    <form method="post" action="settings.php">
      <input type="hidden" name="action" value="rotate_token">
      <button type="submit"><?= $escape($t('settings.rotate_token')) ?></button>
    </form>
  </div>
</div>
