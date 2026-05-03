<?php

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$magicLoginEnabled = !empty($magicLoginEnabled);
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
      <div class="login-form__actions">
        <button type="submit" class="login-form__submit"><?= $escape($t('login.submit')) ?></button>
        <?php if ($magicLoginEnabled): ?>
          <button type="button" class="login-form__submit magic-login-button" data-magic-login-button><?= $escape($t('magic_login.button')) ?></button>
        <?php endif; ?>
      </div>
      <?php if ($magicLoginEnabled): ?>
        <div class="magic-login-panel" data-magic-login-panel hidden>
          <div class="message" data-magic-login-status><?= $escape($t('magic_login.loading')) ?></div>
          <div class="magic-login-panel__body" data-magic-login-body hidden>
            <img class="magic-login-panel__qr" data-magic-login-qr alt="<?= $escape($t('magic_login.qr_alt')) ?>" width="294" height="294">
            <div class="magic-login-panel__details">
              <a href="#" target="_blank" rel="noopener" data-magic-login-link></a>
              <small data-magic-login-countdown></small>
            </div>
          </div>
        </div>
      <?php endif; ?>
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

  (function () {
    var button = document.querySelector("[data-magic-login-button]");
    var panel = document.querySelector("[data-magic-login-panel]");
    var body = document.querySelector("[data-magic-login-body]");
    var statusNode = document.querySelector("[data-magic-login-status]");
    var qrImage = document.querySelector("[data-magic-login-qr]");
    var linkNode = document.querySelector("[data-magic-login-link]");
    var countdownNode = document.querySelector("[data-magic-login-countdown]");
    var pollTimer = 0;
    var countdownTimer = 0;
    var activeToken = "";
    var expiresAt = 0;

    if (!button || !panel || !body || !statusNode || !qrImage || !linkNode || !countdownNode) {
      return;
    }

    var setStatus = function (message, isError) {
      statusNode.textContent = message;
      statusNode.className = isError ? "error" : "message";
    };

    var stopTimers = function () {
      if (pollTimer) {
        window.clearInterval(pollTimer);
        pollTimer = 0;
      }
      if (countdownTimer) {
        window.clearInterval(countdownTimer);
        countdownTimer = 0;
      }
    };

    var renderCountdown = function () {
      var seconds = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
      var minutes = Math.floor(seconds / 60);
      var rest = String(seconds % 60).padStart(2, "0");
      countdownNode.textContent = "<?= $escape($t('magic_login.expires_in')) ?>".replace("{time}", minutes + ":" + rest);
      if (seconds <= 0) {
        stopTimers();
        setStatus("<?= $escape($t('magic_login.expired')) ?>", true);
      }
    };

    var poll = function () {
      if (!activeToken) {
        return;
      }
      fetch("magic_login.php?action=status&token=" + encodeURIComponent(activeToken), {
        headers: { "Accept": "application/json" },
        cache: "no-store"
      })
        .then(function (response) { return response.json(); })
        .then(function (result) {
          if (result.status === "authenticated") {
            stopTimers();
            setStatus("<?= $escape($t('magic_login.logged_in')) ?>", false);
            window.location.href = result.redirect || "index.php";
            return;
          }
          if (result.status === "expired") {
            stopTimers();
            setStatus("<?= $escape($t('magic_login.expired')) ?>", true);
            return;
          }
          if (result.status === "consumed" || result.status === "invalid") {
            stopTimers();
            setStatus("<?= $escape($t('magic_login.invalid')) ?>", true);
          }
        })
        .catch(function () {
          setStatus("<?= $escape($t('magic_login.poll_failed')) ?>", true);
        });
    };

    button.addEventListener("click", function () {
      panel.hidden = false;
      body.hidden = true;
      button.disabled = true;
      setStatus("<?= $escape($t('magic_login.loading')) ?>", false);

      fetch("magic_login.php?action=create", {
        method: "POST",
        headers: { "Accept": "application/json" },
        cache: "no-store"
      })
        .then(function (response) { return response.json(); })
        .then(function (result) {
          if (!result.token || !result.login_url || !result.qr_url) {
            throw new Error(result.error || "<?= $escape($t('magic_login.create_failed')) ?>");
          }

          activeToken = result.token;
          expiresAt = Date.now() + Math.max(0, Number(result.expires_in || 0)) * 1000;
          qrImage.src = result.qr_url + "&v=" + Date.now();
          linkNode.href = result.login_url;
          linkNode.textContent = result.login_url;
          body.hidden = false;
          setStatus("<?= $escape($t('magic_login.waiting')) ?>", false);
          stopTimers();
          renderCountdown();
          pollTimer = window.setInterval(poll, 1000);
          countdownTimer = window.setInterval(renderCountdown, 1000);
          poll();
        })
        .catch(function (error) {
          setStatus(error && error.message ? error.message : "<?= $escape($t('magic_login.create_failed')) ?>", true);
        })
        .finally(function () {
          button.disabled = false;
        });
    });
  })();
</script>
