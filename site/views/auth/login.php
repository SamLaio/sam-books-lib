<?php

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<div class="wrap">
  <div class="panel">
    <h1><?= $escape($t('login.heading')) ?></h1>
    <p class="meta"><?= $escape($t('login.description')) ?></p>

    <?php if (($error ?? null) !== null): ?>
      <div class="error"><?= $escape($error) ?></div>
    <?php endif; ?>

    <form method="post" action="login.php" class="search-form login-form">
      <input type="hidden" name="next" value="<?= $escape($next ?? 'index.php') ?>">
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
      <button type="submit" class="login-form__submit"><?= $escape($t('login.submit')) ?></button>
    </form>
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
      var base = "captcha.php?refresh=1";
      var glue = base.indexOf("?") === -1 ? "?" : "&";
      image.src = base + glue + "v=" + Date.now();
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
