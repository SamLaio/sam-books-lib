<?php

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$currentUser = is_array($currentUser ?? null) ? $currentUser : null;
$isPending = ($status ?? '') === 'pending';
?>
<div class="wrap">
  <div class="panel">
    <h1><?= $escape($t('magic_login.heading')) ?></h1>
    <p class="meta"><?= $escape($statusMessage ?? '') ?></p>

    <?php if (($error ?? null) !== null): ?>
      <div class="error"><?= $escape($error) ?></div>
    <?php endif; ?>

    <?php if (($status ?? '') === 'authenticated'): ?>
      <div class="message"><?= $escape($t('magic_login.authorized_hint')) ?></div>
    <?php elseif (!$isPending): ?>
      <div class="error"><?= $escape($statusMessage ?? $t('magic_login.invalid')) ?></div>
    <?php elseif ($currentUser !== null): ?>
      <form method="post" action="magic_login.php" class="search-form login-form">
        <input type="hidden" name="action" value="authorize">
        <input type="hidden" name="token" value="<?= $escape($token ?? '') ?>">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
        <div class="magic-authorize-user">
          <?= $escape($t('settings.currently_logged_in', [
              'username' => (string) ($currentUser['username'] ?? ''),
              'role' => (string) ($currentUser['role'] ?? ''),
          ])) ?>
        </div>
        <button type="submit" class="login-form__submit"><?= $escape($t('magic_login.authorize_submit')) ?></button>
      </form>
    <?php else: ?>
      <form method="post" action="magic_login.php" class="search-form login-form">
        <input type="hidden" name="action" value="authorize">
        <input type="hidden" name="token" value="<?= $escape($token ?? '') ?>">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
        <div class="login-form__field login-form__field--half">
          <input type="text" name="username" placeholder="<?= $escape($t('login.username_placeholder')) ?>" required>
        </div>
        <div class="login-form__field login-form__field--half">
          <input type="password" name="password" placeholder="<?= $escape($t('login.password_placeholder')) ?>" required>
        </div>
        <div class="captcha-group">
          <div class="login-form__field captcha-group__input">
            <input
              type="text"
              name="captcha"
              placeholder="<?= $escape($t('login.captcha_placeholder')) ?>"
              maxlength="8"
              autocomplete="off"
              required
            >
          </div>
          <div class="captcha-group__visuals">
            <img
              src="<?= $escape($captchaImageUrl ?? 'captcha.php') ?>"
              alt="<?= $escape($t('login.captcha_alt')) ?>"
              class="captcha-image"
              data-login-captcha-image
              role="button"
              tabindex="0"
              title="<?= $escape($t('login.captcha_refresh')) ?>"
              aria-label="<?= $escape($t('login.captcha_refresh')) ?>"
              width="160"
              height="52"
            >
          </div>
        </div>
        <button type="submit" class="login-form__submit"><?= $escape($t('magic_login.login_and_authorize_submit')) ?></button>
      </form>
    <?php endif; ?>
  </div>
</div>

<script>
  (function () {
    var image = document.querySelector("[data-login-captcha-image]");
    var captchaInput = document.querySelector("input[name=\"captcha\"]");
    if (!image) {
      return;
    }

    var refreshCaptcha = function () {
      image.src = "captcha.php?refresh=1&v=" + Date.now();
      if (captchaInput) {
        captchaInput.value = "";
        captchaInput.focus();
      }
    };

    image.addEventListener("click", refreshCaptcha);
    image.addEventListener("keydown", function (event) {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        refreshCaptcha();
      }
    });
  })();
</script>
