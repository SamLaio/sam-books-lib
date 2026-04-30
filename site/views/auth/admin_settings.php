<?php

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$username = (string) (($user['username'] ?? ''));
$managedUsers = is_array($managedUsers ?? null) ? $managedUsers : [];
$appSettings = is_array($appSettings ?? null) ? $appSettings : [];
$jobs = is_array($jobs ?? null) ? $jobs : [];
$activeTab = is_string($activeTab ?? null) ? $activeTab : 'users';
$jobsPagination = is_array($jobsPagination ?? null) ? $jobsPagination : ['previousUrl' => null, 'nextUrl' => null, 'items' => []];
$jobsPerPageLinks = is_array($jobsPerPageLinks ?? null) ? $jobsPerPageLinks : [];
$jobsTotalRows = (int) ($jobsTotalRows ?? 0);
$jobsTotalPages = (int) ($jobsTotalPages ?? 1);
$jobsCurrentPage = (int) ($jobsCurrentPage ?? 1);
$jobsPerPage = (int) ($jobsPerPage ?? 20);
$showUserManagement = !empty($showUserManagement);
$smtpConfigured = !empty($smtpConfigured);
$coverRebuildBusy = !empty($coverRebuildBusy);
$availableLocales = is_array($availableLocales ?? null) ? $availableLocales : ['zhTW', 'en'];
$defaultLocale = (string) ($appSettings['default_locale'] ?? 'zhTW');
?>
<div class="wrap">
  <div class="panel">
    <h1><?= $escape($t('admin.heading')) ?></h1>
    <p class="meta">
      <?= $escape($t('admin.currently_logged_in', ['username' => $username])) ?>
      | <a class="title-home-link" href="index.php"><?= $escape($t('common.home')) ?></a>
      | <a class="title-home-link" href="settings.php"><?= $escape($t('layout.account_settings')) ?></a>
      | <a class="title-home-link" href="logout.php"><?= $escape($t('common.logout')) ?></a>
      <?php if (!empty($scanRunning)): ?>
        | <span class="status"><?= $escape($t('admin.background_rebuilding')) ?></span>
      <?php endif; ?>
    </p>

    <?php if (($notice ?? null) !== null): ?>
      <div class="message"><?= $escape($notice) ?></div>
    <?php endif; ?>

    <?php if (($error ?? null) !== null): ?>
      <div class="error"><?= $escape($error) ?></div>
    <?php endif; ?>

    <hr>
    <div class="admin-tabs" data-admin-tabs>
      <div class="admin-tabs__nav" role="tablist" aria-label="<?= $escape($t('admin.tablist_label')) ?>">
        <?php if ($showUserManagement): ?>
          <button
            type="button"
            class="admin-tabs__tab is-active"
            role="tab"
            aria-selected="true"
            aria-controls="admin-tab-users"
            data-tab-target="users"
          >
            <?= $escape($t('admin.users_tab')) ?>
          </button>
        <?php endif; ?>
        <button
          type="button"
          class="admin-tabs__tab"
          role="tab"
          aria-selected="false"
          aria-controls="admin-tab-smtp"
          data-tab-target="smtp"
        >
          <?= $escape($t('admin.smtp_tab')) ?>
        </button>
        <button
          type="button"
          class="admin-tabs__tab"
          role="tab"
          aria-selected="false"
          aria-controls="admin-tab-maintenance"
          data-tab-target="maintenance"
        >
          <?= $escape($t('admin.maintenance_tab')) ?>
        </button>
        <button
          type="button"
          class="admin-tabs__tab"
          role="tab"
          aria-selected="false"
          aria-controls="admin-tab-jobs"
          data-tab-target="jobs"
        >
          <?= $escape($t('admin.jobs_tab')) ?>
        </button>
      </div>

      <?php if ($showUserManagement): ?>
        <section id="admin-tab-users" class="admin-tabs__panel" role="tabpanel" data-tab-panel="users" <?= $activeTab === 'users' ? '' : 'hidden' ?>>
          <h2><?= $escape($t('admin.create_user_heading')) ?></h2>
          <form method="post" action="admin_settings.php" class="search-form">
            <input type="hidden" name="action" value="admin_create_user">
            <input type="hidden" name="active_tab" value="users">
            <input type="text" name="username" placeholder="<?= $escape($t('admin.username_placeholder')) ?>" required>
            <input type="password" name="password" placeholder="<?= $escape($t('admin.password_placeholder')) ?>" required>
            <input type="email" name="email" placeholder="<?= $escape($t('settings.email_placeholder')) ?>">
            <label>
              <input type="checkbox" name="enabled" value="1" checked>
              <?= $escape($t('common.enabled')) ?>
            </label>
            <button type="submit"><?= $escape($t('admin.create_user')) ?></button>
          </form>

          <h2><?= $escape($t('admin.user_list_heading')) ?></h2>
          <div class="admin-user-mobile-list" aria-label="<?= $escape($t('admin.mobile_user_list_label')) ?>">
            <?php foreach ($managedUsers as $managed): ?>
              <?php
              $managedId = (int) ($managed['id'] ?? 0);
              $managedUsername = (string) ($managed['username'] ?? '');
              $managedEmail = (string) ($managed['email'] ?? '');
              $managedEnabled = (int) ($managed['is_enabled'] ?? 1) === 1;
              $managedIsDefault = (int) ($managed['is_default'] ?? 0) === 1;
              $managedHiddenAuthors = is_array($managed['hidden_authors_list'] ?? null) ? $managed['hidden_authors_list'] : [];
              $managedHiddenTags = is_array($managed['hidden_tags_list'] ?? null) ? $managed['hidden_tags_list'] : [];
              $managedHiddenAuthorsValue = implode('; ', $managedHiddenAuthors);
              $managedHiddenTagsValue = implode('; ', $managedHiddenTags);
              $mobileDialogId = 'admin-user-mobile-dialog-' . $managedId;
              $libraryDialogId = 'admin-user-library-dialog-' . $managedId;
              ?>
              <button type="button" class="admin-user-mobile-link" data-user-dialog-open="<?= $escape($mobileDialogId) ?>">
                <?= $escape($managedUsername) ?>
              </button>

              <dialog id="<?= $escape($mobileDialogId) ?>" class="admin-user-mobile-dialog">
                <form method="post" action="admin_settings.php" class="admin-user-mobile-dialog__form">
                  <input type="hidden" name="action" value="admin_update_user">
                  <input type="hidden" name="active_tab" value="users">
                  <input type="hidden" name="target_user_id" value="<?= $managedId ?>">

                  <header class="admin-user-mobile-dialog__header">
                    <h3><?= $escape($managedUsername) ?></h3>
                    <button type="button" class="admin-user-mobile-dialog__close" data-user-dialog-close><?= $escape($t('common.close')) ?></button>
                  </header>

                  <label class="admin-user-mobile-dialog__field">
                    <span><?= $escape($t('admin.account_name')) ?></span>
                    <input type="text" name="target_username" value="<?= $escape($managedUsername) ?>" required>
                  </label>

                  <label class="admin-user-mobile-dialog__field">
                    <span><?= $escape($t('admin.update_password')) ?></span>
                    <input type="password" name="target_password" placeholder="<?= $escape($t('admin.update_password_placeholder')) ?>">
                  </label>

                  <label class="admin-user-mobile-dialog__field">
                    <span><?= $escape($t('common.email')) ?></span>
                    <input type="email" name="target_email" value="<?= $escape($managedEmail) ?>" placeholder="<?= $escape($t('settings.email_placeholder')) ?>">
                  </label>

                  <div class="admin-user-mobile-dialog__field">
                    <span><?= $escape($t('admin.enabled_status')) ?></span>
                    <?php if ($managedIsDefault): ?>
                      <div class="admin-user-mobile-dialog__toggle-row">
                        <span><?= $escape($t('admin.fixed_enabled')) ?></span>
                        <input type="hidden" name="target_enabled" value="1">
                      </div>
                    <?php else: ?>
                      <input type="hidden" name="target_enabled" value="0">
                      <label class="admin-user-mobile-dialog__toggle-row">
                        <input type="checkbox" name="target_enabled" value="1" <?= $managedEnabled ? 'checked' : '' ?>>
                        <span><?= $escape($t('common.enabled')) ?></span>
                      </label>
                    <?php endif; ?>
                  </div>

                  <div class="admin-user-mobile-dialog__actions">
                    <button type="submit"><?= $escape($t('common.update')) ?></button>
                    <button type="button" data-library-dialog-open="<?= $escape($libraryDialogId) ?>"><?= $escape($t('admin.library_settings')) ?></button>
                    <?php if (!$managedIsDefault): ?>
                      <button type="submit" form="admin-user-mobile-delete-<?= $managedId ?>" class="button-danger"><?= $escape($t('common.delete')) ?></button>
                    <?php endif; ?>
                  </div>
                </form>

                <?php if (!$managedIsDefault): ?>
                  <form
                    id="admin-user-mobile-delete-<?= $managedId ?>"
                    method="post"
                    action="admin_settings.php"
                    class="admin-user-mobile-dialog__delete"
                    onsubmit="return confirm('<?= $escape($t('admin.confirm_delete_user')) ?>');"
                  >
                    <input type="hidden" name="action" value="admin_delete_user">
                    <input type="hidden" name="active_tab" value="users">
                    <input type="hidden" name="target_user_id" value="<?= $managedId ?>">
                    <button type="submit"><?= $escape($t('admin.delete_user')) ?></button>
                  </form>
                <?php endif; ?>
              </dialog>

              <dialog id="<?= $escape($libraryDialogId) ?>" class="admin-user-library-dialog">
                <form method="post" action="admin_settings.php" class="admin-user-library-dialog__form">
                  <input type="hidden" name="action" value="admin_update_user_library">
                  <input type="hidden" name="active_tab" value="users">
                  <input type="hidden" name="target_user_id" value="<?= $managedId ?>">

                  <header class="admin-user-library-dialog__header">
                    <h3><?= $escape($t('admin.user_library_settings', ['username' => $managedUsername])) ?></h3>
                    <button type="button" class="admin-user-library-dialog__close" data-library-dialog-close><?= $escape($t('common.close')) ?></button>
                  </header>

                  <label class="admin-user-library-dialog__field">
                    <span><?= $escape($t('admin.hidden_authors')) ?></span>
                    <input
                      type="text"
                      name="hidden_authors"
                      value="<?= $escape($managedHiddenAuthorsValue) ?>"
                      placeholder="<?= $escape($t('admin.hidden_authors_placeholder')) ?>"
                    >
                    <small><?= $escape($t('admin.hidden_authors_hint')) ?></small>
                  </label>

                  <label class="admin-user-library-dialog__field">
                    <span><?= $escape($t('admin.hidden_tags')) ?></span>
                    <input
                      type="text"
                      name="hidden_tags"
                      value="<?= $escape($managedHiddenTagsValue) ?>"
                      placeholder="<?= $escape($t('admin.hidden_tags_placeholder')) ?>"
                    >
                    <small><?= $escape($t('admin.hidden_tags_hint')) ?></small>
                  </label>

                  <div class="admin-user-library-dialog__actions">
                    <button type="submit"><?= $escape($t('common.update')) ?></button>
                  </div>
                </form>
              </dialog>
            <?php endforeach; ?>
          </div>

          <div class="admin-users-table-wrap">
            <table class="admin-users-table">
              <thead>
                <tr>
                  <th><?= $escape($t('admin.account')) ?></th>
                  <th><?= $escape($t('admin.new_password_optional')) ?></th>
                  <th><?= $escape($t('common.email')) ?></th>
                  <th><?= $escape($t('common.enabled')) ?></th>
                  <th><?= $escape($t('common.actions')) ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($managedUsers as $managed): ?>
                  <?php
                  $managedId = (int) ($managed['id'] ?? 0);
                  $managedUsername = (string) ($managed['username'] ?? '');
                  $managedEmail = (string) ($managed['email'] ?? '');
                  $managedEnabled = (int) ($managed['is_enabled'] ?? 1) === 1;
                  $managedIsDefault = (int) ($managed['is_default'] ?? 0) === 1;
                  $managedHiddenAuthors = is_array($managed['hidden_authors_list'] ?? null) ? $managed['hidden_authors_list'] : [];
                  $managedHiddenTags = is_array($managed['hidden_tags_list'] ?? null) ? $managed['hidden_tags_list'] : [];
                  $managedHiddenAuthorsValue = implode('; ', $managedHiddenAuthors);
                  $managedHiddenTagsValue = implode('; ', $managedHiddenTags);
                  $formId = 'admin-user-form-' . $managedId;
                  $libraryDialogId = 'admin-user-library-dialog-table-' . $managedId;
                  ?>
                  <tr>
                    <td>
                      <input form="<?= $escape($formId) ?>" type="text" name="target_username" value="<?= $escape($managedUsername) ?>" required>
                      <?php if ($managedIsDefault): ?>
                        <div class="book-version-note"><?= $escape($t('admin.default_admin')) ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <input form="<?= $escape($formId) ?>" type="password" name="target_password" placeholder="<?= $escape($t('admin.update_password')) ?>">
                    </td>
                    <td>
                      <input form="<?= $escape($formId) ?>" type="email" name="target_email" value="<?= $escape($managedEmail) ?>" placeholder="<?= $escape($t('settings.email_placeholder')) ?>">
                    </td>
                    <td>
                      <?php if ($managedIsDefault): ?>
                        <span><?= $escape($t('admin.fixed_enabled')) ?></span>
                        <input form="<?= $escape($formId) ?>" type="hidden" name="target_enabled" value="1">
                      <?php else: ?>
                        <input form="<?= $escape($formId) ?>" type="hidden" name="target_enabled" value="0">
                        <input form="<?= $escape($formId) ?>" type="checkbox" name="target_enabled" value="1" <?= $managedEnabled ? 'checked' : '' ?>>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="admin-user-actions">
                        <button form="<?= $escape($formId) ?>" type="submit"><?= $escape($t('common.update')) ?></button>
                        <button type="button" data-library-dialog-open="<?= $escape($libraryDialogId) ?>"><?= $escape($t('admin.library_settings')) ?></button>
                        <?php if ($managedIsDefault): ?>
                          <span class="admin-user-actions__note"><?= $escape($t('admin.not_deletable')) ?></span>
                        <?php else: ?>
                          <button type="submit" form="admin-user-delete-<?= $managedId ?>" class="button-danger"><?= $escape($t('common.delete')) ?></button>
                        <?php endif; ?>
                      </div>
                      <form id="<?= $escape($formId) ?>" method="post" action="admin_settings.php">
                        <input type="hidden" name="action" value="admin_update_user">
                        <input type="hidden" name="active_tab" value="users">
                        <input type="hidden" name="target_user_id" value="<?= $managedId ?>">
                      </form>
                      <?php if (!$managedIsDefault): ?>
                        <form id="admin-user-delete-<?= $managedId ?>" method="post" action="admin_settings.php" onsubmit="return confirm('<?= $escape($t('admin.confirm_delete_user')) ?>');">
                          <input type="hidden" name="action" value="admin_delete_user">
                          <input type="hidden" name="active_tab" value="users">
                          <input type="hidden" name="target_user_id" value="<?= $managedId ?>">
                        </form>
                      <?php endif; ?>

                      <dialog id="<?= $escape($libraryDialogId) ?>" class="admin-user-library-dialog">
                        <form method="post" action="admin_settings.php" class="admin-user-library-dialog__form">
                          <input type="hidden" name="action" value="admin_update_user_library">
                          <input type="hidden" name="active_tab" value="users">
                          <input type="hidden" name="target_user_id" value="<?= $managedId ?>">

                          <header class="admin-user-library-dialog__header">
                            <h3><?= $escape($t('admin.user_library_settings', ['username' => $managedUsername])) ?></h3>
                            <button type="button" class="admin-user-library-dialog__close" data-library-dialog-close><?= $escape($t('common.close')) ?></button>
                          </header>

                          <label class="admin-user-library-dialog__field">
                            <span><?= $escape($t('admin.hidden_authors')) ?></span>
                            <input
                              type="text"
                              name="hidden_authors"
                              value="<?= $escape($managedHiddenAuthorsValue) ?>"
                              placeholder="<?= $escape($t('admin.hidden_authors_placeholder')) ?>"
                            >
                            <small><?= $escape($t('admin.hidden_authors_hint')) ?></small>
                          </label>

                          <label class="admin-user-library-dialog__field">
                            <span><?= $escape($t('admin.hidden_tags')) ?></span>
                            <input
                              type="text"
                              name="hidden_tags"
                              value="<?= $escape($managedHiddenTagsValue) ?>"
                              placeholder="<?= $escape($t('admin.hidden_tags_placeholder')) ?>"
                            >
                            <small><?= $escape($t('admin.hidden_tags_hint')) ?></small>
                          </label>

                          <div class="admin-user-library-dialog__actions">
                            <button type="submit"><?= $escape($t('common.update')) ?></button>
                          </div>
                        </form>
                      </dialog>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>

      <section id="admin-tab-smtp" class="admin-tabs__panel" role="tabpanel" data-tab-panel="smtp" <?= $activeTab === 'smtp' ? '' : 'hidden' ?>>
        <h2><?= $escape($t('admin.smtp_heading')) ?></h2>
        <?php if (!$smtpConfigured): ?>
          <p class="meta"><?= $escape($t('admin.smtp_incomplete')) ?></p>
        <?php endif; ?>
        <form method="post" action="admin_settings.php" class="search-form">
          <input type="hidden" name="action" value="admin_update_smtp">
          <input type="hidden" name="active_tab" value="smtp">
          <input type="text" name="smtp_host" placeholder="<?= $escape($t('admin.smtp_host')) ?>" value="<?= $escape($appSettings['smtp_host'] ?? '') ?>">
          <input type="number" name="smtp_port" placeholder="<?= $escape($t('admin.smtp_port')) ?>" value="<?= $escape($appSettings['smtp_port'] ?? '') ?>" min="1" max="65535">
          <select name="smtp_encryption" aria-label="<?= $escape($t('admin.smtp_encryption')) ?>">
            <?php $smtpEncryption = strtolower((string) ($appSettings['smtp_encryption'] ?? 'none')); ?>
            <option value="none" <?= $smtpEncryption === 'none' ? 'selected' : '' ?>><?= $escape($t('admin.smtp_none')) ?></option>
            <option value="tls" <?= $smtpEncryption === 'tls' ? 'selected' : '' ?>>TLS</option>
            <option value="ssl" <?= $smtpEncryption === 'ssl' ? 'selected' : '' ?>>SSL</option>
          </select>
          <input type="text" name="smtp_username" placeholder="<?= $escape($t('admin.smtp_username')) ?>" value="<?= $escape($appSettings['smtp_username'] ?? '') ?>">
          <input type="password" name="smtp_password" placeholder="<?= $escape($t('admin.smtp_password')) ?>" value="<?= $escape($appSettings['smtp_password'] ?? '') ?>">
          <button type="submit"><?= $escape($t('admin.update_smtp')) ?></button>
        </form>

        <h3><?= $escape($t('admin.test_email_heading')) ?></h3>
        <form method="post" action="admin_settings.php" class="search-form smtp-test-form">
          <input type="hidden" name="action" value="admin_send_test_email">
          <input type="hidden" name="active_tab" value="smtp">
          <input
            type="email"
            name="test_email"
            placeholder="<?= $escape($t('admin.test_email_placeholder')) ?>"
            value=""
            required
          >
          <button type="submit" <?= !$smtpConfigured ? 'disabled' : '' ?>><?= $escape($t('admin.send_test')) ?></button>
        </form>
        <p class="meta"><?= $escape($t('admin.test_subject', ['siteTitle' => (string) ($siteTitle ?? '')])) ?></p>
        <p class="meta"><?= $escape($t('admin.test_body', ['siteTitle' => (string) ($siteTitle ?? '')])) ?></p>
      </section>

      <section id="admin-tab-maintenance" class="admin-tabs__panel" role="tabpanel" data-tab-panel="maintenance" <?= $activeTab === 'maintenance' ? '' : 'hidden' ?>>
        <h2><?= $escape($t('admin.maintenance_heading')) ?></h2>
        <div class="maintenance-sections">
          <div class="maintenance-section">
            <h3><?= $escape($t('admin.default_locale_heading')) ?></h3>
            <form method="post" action="admin_settings.php" class="search-form">
              <input type="hidden" name="action" value="admin_update_default_locale">
              <input type="hidden" name="active_tab" value="maintenance">
              <select name="default_locale" aria-label="<?= $escape($t('admin.default_locale_label')) ?>">
                <?php foreach ($availableLocales as $localeCode): ?>
                  <?php
                  $normalizedLocaleCode = (string) $localeCode;
                  $localeLabelKey = $normalizedLocaleCode === 'en' ? 'settings.locale_en' : 'settings.locale_zhtw';
                  ?>
                  <option value="<?= $escape($normalizedLocaleCode) ?>" <?= $defaultLocale === $normalizedLocaleCode ? 'selected' : '' ?>>
                    <?= $escape($t($localeLabelKey)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <button type="submit"><?= $escape($t('admin.update_default_locale')) ?></button>
            </form>
          </div>

          <div class="maintenance-section">
            <h3><?= $escape($t('admin.manual_rebuild')) ?></h3>
            <form method="post" action="admin_settings.php" class="search-form">
              <input type="hidden" name="action" value="admin_rebuild_index">
              <input type="hidden" name="active_tab" value="maintenance">
              <button type="submit"><?= $escape($t('admin.manual_rebuild')) ?></button>
            </form>
          </div>

          <div class="maintenance-section">
            <h3><?= $escape($t('admin.rebuild_cover')) ?></h3>
            <form method="post" action="admin_settings.php" class="search-form">
              <input type="hidden" name="action" value="rebuild_cover">
              <input type="hidden" name="active_tab" value="maintenance">
              <button type="submit" <?= $coverRebuildBusy ? 'disabled' : '' ?>>
                <?= $escape($coverRebuildBusy ? $t('admin.rebuild_cover_busy') : $t('admin.rebuild_cover')) ?>
              </button>
            </form>
          </div>
        </div>
      </section>

      <section
        id="admin-tab-jobs"
        class="admin-tabs__panel"
        role="tabpanel"
        data-tab-panel="jobs"
        data-jobs-previous-url="<?= $escape((string) ($jobsPagination['previousUrl'] ?? '')) ?>"
        data-jobs-next-url="<?= $escape((string) ($jobsPagination['nextUrl'] ?? '')) ?>"
        <?= $activeTab === 'jobs' ? '' : 'hidden' ?>
      >
        <h2><?= $escape($t('admin.jobs_heading')) ?></h2>
        <form method="post" action="admin_settings.php" class="search-form">
          <input type="hidden" name="action" value="admin_clear_jobs">
          <input type="hidden" name="active_tab" value="jobs">
          <input type="hidden" name="jobs_page" value="<?= max(1, $jobsCurrentPage) ?>">
          <input type="hidden" name="jobs_per_page" value="<?= $jobsPerPage ?>">
          <select name="clear_count" aria-label="<?= $escape($t('admin.clear_job_logs_label')) ?>">
            <option value="50">50</option>
            <option value="100">100</option>
            <option value="200">200</option>
            <option value="500">500</option>
            <option value="all"><?= $escape($t('common.all')) ?></option>
          </select>
          <button type="submit"><?= $escape($t('admin.clear_logs')) ?></button>
        </form>

        <?php if ($jobsTotalPages > 1): ?>
          <div class="pager pager--top">
            <div class="page-links">
              <?php if (($jobsPagination['previousUrl'] ?? null) !== null): ?>
                <a class="page-link" data-pager-nav="previous" href="<?= $escape($jobsPagination['previousUrl']) ?>"><?= $escape($t('common.previous_page')) ?></a>
              <?php endif; ?>

              <?php foreach (($jobsPagination['items'] ?? []) as $item): ?>
                <?php if (($item['type'] ?? '') === 'ellipsis'): ?>
                  <span class="ellipsis">...</span>
                <?php elseif (!empty($item['current'])): ?>
                  <span class="page-link current"><?= (int) ($item['number'] ?? 1) ?></span>
                <?php else: ?>
                  <a class="page-link" href="<?= $escape((string) ($item['url'] ?? '#')) ?>"><?= (int) ($item['number'] ?? 1) ?></a>
                <?php endif; ?>
              <?php endforeach; ?>

              <?php if (($jobsPagination['nextUrl'] ?? null) !== null): ?>
                <a class="page-link" data-pager-nav="next" href="<?= $escape($jobsPagination['nextUrl']) ?>"><?= $escape($t('common.next_page')) ?></a>
              <?php endif; ?>

              <form method="get" action="admin_settings.php" class="page-jump-form">
                <input type="hidden" name="tab" value="jobs">
                <input type="hidden" name="jobs_per_page" value="<?= $jobsPerPage ?>">
                <input
                  class="page-jump-input"
                  type="number"
                  name="jobs_page"
                  min="1"
                  max="<?= max(1, $jobsTotalPages) ?>"
                  value="<?= max(1, $jobsCurrentPage) ?>"
                  inputmode="numeric"
                  aria-label="<?= $escape($t('common.jump_to_page')) ?>"
                >
                <button type="submit" class="page-jump-btn"><?= $escape($t('common.jump_page')) ?></button>
              </form>
            </div>
            <div class="pager-tools">
              <span class="pager-total"><?= $escape($t('common.total_items', ['count' => (string) $jobsTotalRows])) ?></span>
              <div class="page-size-links">
                <?php foreach ($jobsPerPageLinks as $perPageLink): ?>
                  <?php if (!empty($perPageLink['current'])): ?>
                    <span class="page-size-link current"><?= $escape((string) ($perPageLink['label'] ?? '')) ?></span>
                  <?php else: ?>
                    <a class="page-size-link" href="<?= $escape((string) ($perPageLink['url'] ?? '#')) ?>"><?= $escape((string) ($perPageLink['label'] ?? '')) ?></a>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <div class="admin-job-mobile-list" aria-label="<?= $escape($t('admin.mobile_jobs_list_label')) ?>">
          <?php if ($jobs === []): ?>
            <div class="admin-job-mobile-card">
              <div class="empty-row"><?= $escape($t('admin.no_jobs')) ?></div>
            </div>
          <?php else: ?>
            <?php foreach ($jobs as $job): ?>
              <?php
              $jobId = (int) ($job['id'] ?? 0);
              $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
              $payloadText = $payload === [] ? '-' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
              $jobDialogId = 'admin-job-mobile-dialog-' . $jobId;
              ?>
              <div class="admin-job-mobile-card">
                <div class="admin-job-mobile-row"><span class="admin-job-mobile-label"><?= $escape($t('common.id')) ?></span><span class="admin-job-mobile-value"><?= $jobId ?></span></div>
                <div class="admin-job-mobile-row"><span class="admin-job-mobile-label"><?= $escape($t('admin.action')) ?></span><span class="admin-job-mobile-value"><?= $escape($job['action'] ?? '') ?></span></div>
                <div class="admin-job-mobile-row"><span class="admin-job-mobile-label"><?= $escape($t('admin.source')) ?></span><span class="admin-job-mobile-value"><?= $escape($job['source'] ?? '') ?></span></div>
                <div class="admin-job-mobile-row"><span class="admin-job-mobile-label"><?= $escape($t('common.status')) ?></span><span class="admin-job-mobile-value"><?= $escape($job['status'] ?? '') ?></span></div>
                <div class="admin-job-mobile-row"><span class="admin-job-mobile-label"><?= $escape($t('common.schedule_time')) ?></span><span class="admin-job-mobile-value"><?= $escape($job['run_at'] ?? '') ?></span></div>
                <button type="button" class="admin-job-mobile-detail-btn" data-job-dialog-open="<?= $escape($jobDialogId) ?>"><?= $escape($t('admin.detail')) ?></button>
              </div>

              <dialog id="<?= $escape($jobDialogId) ?>" class="admin-job-mobile-dialog">
                <div class="admin-job-mobile-dialog__header">
                  <h3><?= $escape($t('admin.job_detail_heading')) ?></h3>
                  <button type="button" class="admin-job-mobile-dialog__close" data-job-dialog-close><?= $escape($t('common.close')) ?></button>
                </div>
                <div class="admin-job-mobile-dialog__body">
                  <table class="admin-job-mobile-detail-table">
                    <tbody>
                      <tr><th><?= $escape($t('common.id')) ?></th><td><?= $jobId ?></td></tr>
                      <tr><th><?= $escape($t('admin.action')) ?></th><td><?= $escape($job['action'] ?? '') ?></td></tr>
                      <tr><th><?= $escape($t('admin.source')) ?></th><td><?= $escape($job['source'] ?? '') ?></td></tr>
                      <tr><th><?= $escape($t('common.status')) ?></th><td><?= $escape($job['status'] ?? '') ?></td></tr>
                      <tr><th><?= $escape($t('common.schedule_time')) ?></th><td><?= $escape($job['run_at'] ?? '') ?></td></tr>
                      <tr><th><?= $escape($t('common.start_time')) ?></th><td><?= $escape($job['started_at'] ?? '') ?></td></tr>
                      <tr><th><?= $escape($t('common.finish_time')) ?></th><td><?= $escape($job['finished_at'] ?? '') ?></td></tr>
                      <tr><th><?= $escape($t('common.payload')) ?></th><td><code><?= $escape($payloadText) ?></code></td></tr>
                    </tbody>
                  </table>
                </div>
              </dialog>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <div class="admin-users-table-wrap admin-jobs-table-wrap">
          <table class="admin-users-table">
            <thead>
              <tr>
                <th><?= $escape($t('common.id')) ?></th>
                <th><?= $escape($t('admin.action')) ?></th>
                <th><?= $escape($t('admin.source')) ?></th>
                <th><?= $escape($t('common.status')) ?></th>
                <th><?= $escape($t('common.schedule_time')) ?></th>
                <th><?= $escape($t('common.start_time')) ?></th>
                <th><?= $escape($t('common.finish_time')) ?></th>
                <th><?= $escape($t('admin.payload_header')) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php if ($jobs === []): ?>
                <tr>
                  <td colspan="8" class="empty-row"><?= $escape($t('admin.no_jobs')) ?></td>
                </tr>
              <?php else: ?>
                <?php foreach ($jobs as $job): ?>
                  <?php
                  $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
                  $payloadText = $payload === [] ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                  ?>
                  <tr>
                    <td><?= (int) ($job['id'] ?? 0) ?></td>
                    <td><?= $escape($job['action'] ?? '') ?></td>
                    <td><?= $escape($job['source'] ?? '') ?></td>
                    <td><?= $escape($job['status'] ?? '') ?></td>
                    <td><?= $escape($job['run_at'] ?? '') ?></td>
                    <td><?= $escape($job['started_at'] ?? '') ?></td>
                    <td><?= $escape($job['finished_at'] ?? '') ?></td>
                    <td><code><?= $escape($payloadText ?: '-') ?></code></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <?php if ($jobsTotalPages > 1): ?>
          <div class="pager pager--bottom">
            <div class="page-links">
              <?php if (($jobsPagination['previousUrl'] ?? null) !== null): ?>
                <a class="page-link" data-pager-nav="previous" href="<?= $escape($jobsPagination['previousUrl']) ?>"><?= $escape($t('common.previous_page')) ?></a>
              <?php endif; ?>

              <?php foreach (($jobsPagination['items'] ?? []) as $item): ?>
                <?php if (($item['type'] ?? '') === 'ellipsis'): ?>
                  <span class="ellipsis">...</span>
                <?php elseif (!empty($item['current'])): ?>
                  <span class="page-link current"><?= (int) ($item['number'] ?? 1) ?></span>
                <?php else: ?>
                  <a class="page-link" href="<?= $escape((string) ($item['url'] ?? '#')) ?>"><?= (int) ($item['number'] ?? 1) ?></a>
                <?php endif; ?>
              <?php endforeach; ?>

              <?php if (($jobsPagination['nextUrl'] ?? null) !== null): ?>
                <a class="page-link" data-pager-nav="next" href="<?= $escape($jobsPagination['nextUrl']) ?>"><?= $escape($t('common.next_page')) ?></a>
              <?php endif; ?>

              <form method="get" action="admin_settings.php" class="page-jump-form">
                <input type="hidden" name="tab" value="jobs">
                <input type="hidden" name="jobs_per_page" value="<?= $jobsPerPage ?>">
                <input
                  class="page-jump-input"
                  type="number"
                  name="jobs_page"
                  min="1"
                  max="<?= max(1, $jobsTotalPages) ?>"
                  value="<?= max(1, $jobsCurrentPage) ?>"
                  inputmode="numeric"
                  aria-label="<?= $escape($t('common.jump_to_page')) ?>"
                >
                <button type="submit" class="page-jump-btn"><?= $escape($t('common.jump_page')) ?></button>
              </form>
            </div>
            <div class="pager-tools">
              <span class="pager-total"><?= $escape($t('common.total_items', ['count' => (string) $jobsTotalRows])) ?></span>
              <div class="page-size-links">
                <?php foreach ($jobsPerPageLinks as $perPageLink): ?>
                  <?php if (!empty($perPageLink['current'])): ?>
                    <span class="page-size-link current"><?= $escape((string) ($perPageLink['label'] ?? '')) ?></span>
                  <?php else: ?>
                    <a class="page-size-link" href="<?= $escape((string) ($perPageLink['url'] ?? '#')) ?>"><?= $escape((string) ($perPageLink['label'] ?? '')) ?></a>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </section>
    </div>

    <script>
      (function () {
        var root = document.querySelector("[data-admin-tabs]");
        if (!root) return;
        var tabs = Array.prototype.slice.call(root.querySelectorAll("[data-tab-target]"));
        var panels = Array.prototype.slice.call(root.querySelectorAll("[data-tab-panel]"));
        var activate = function (name) {
          tabs.forEach(function (tab) {
            var selected = tab.getAttribute("data-tab-target") === name;
            tab.classList.toggle("is-active", selected);
            tab.setAttribute("aria-selected", selected ? "true" : "false");
          });
          panels.forEach(function (panel) {
            panel.hidden = panel.getAttribute("data-tab-panel") !== name;
          });
        };
        tabs.forEach(function (tab) {
          tab.addEventListener("click", function () {
            activate(tab.getAttribute("data-tab-target") || "smtp");
          });
        });

        root.addEventListener("click", function (event) {
          var openBtn = event.target.closest("[data-user-dialog-open]");
          if (openBtn) {
            var targetId = openBtn.getAttribute("data-user-dialog-open");
            if (targetId) {
              var dialog = document.getElementById(targetId);
              if (dialog && typeof dialog.showModal === "function") {
                dialog.showModal();
              }
            }
            return;
          }

          var closeBtn = event.target.closest("[data-user-dialog-close]");
          if (closeBtn) {
            var closeDialog = closeBtn.closest("dialog");
            if (closeDialog && typeof closeDialog.close === "function") {
              closeDialog.close();
            }
            return;
          }

          var libraryOpenBtn = event.target.closest("[data-library-dialog-open]");
          if (libraryOpenBtn) {
            var libraryTargetId = libraryOpenBtn.getAttribute("data-library-dialog-open");
            if (libraryTargetId) {
              var libraryDialog = document.getElementById(libraryTargetId);
              if (libraryDialog && typeof libraryDialog.showModal === "function") {
                libraryDialog.showModal();
              }
            }
            return;
          }

          var libraryCloseBtn = event.target.closest("[data-library-dialog-close]");
          if (libraryCloseBtn) {
            var closingLibraryDialog = libraryCloseBtn.closest("dialog");
            if (closingLibraryDialog && typeof closingLibraryDialog.close === "function") {
              closingLibraryDialog.close();
            }
            return;
          }

          var jobOpenBtn = event.target.closest("[data-job-dialog-open]");
          if (jobOpenBtn) {
            var jobTargetId = jobOpenBtn.getAttribute("data-job-dialog-open");
            if (jobTargetId) {
              var jobDialog = document.getElementById(jobTargetId);
              if (jobDialog && typeof jobDialog.showModal === "function") {
                jobDialog.showModal();
              }
            }
            return;
          }

          var jobCloseBtn = event.target.closest("[data-job-dialog-close]");
          if (jobCloseBtn) {
            var closingJobDialog = jobCloseBtn.closest("dialog");
            if (closingJobDialog && typeof closingJobDialog.close === "function") {
              closingJobDialog.close();
            }
          }
        });

        root.querySelectorAll(".admin-user-mobile-dialog").forEach(function (dialog) {
          dialog.addEventListener("click", function (event) {
            if (event.target === dialog && typeof dialog.close === "function") {
              dialog.close();
            }
          });
        });

        root.querySelectorAll(".admin-job-mobile-dialog").forEach(function (dialog) {
          dialog.addEventListener("click", function (event) {
            if (event.target === dialog && typeof dialog.close === "function") {
              dialog.close();
            }
          });
        });

        root.querySelectorAll(".admin-user-library-dialog").forEach(function (dialog) {
          dialog.addEventListener("click", function (event) {
            if (event.target === dialog && typeof dialog.close === "function") {
              dialog.close();
            }
          });
        });

        document.addEventListener("keydown", function (event) {
          if (event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) {
            return;
          }

          var target = event.target;
          if (target instanceof HTMLElement) {
            var tagName = target.tagName.toLowerCase();
            if (
              tagName === "input" ||
              tagName === "textarea" ||
              tagName === "select" ||
              target.isContentEditable ||
              target.closest('[contenteditable="true"]')
            ) {
              return;
            }
          }

          if (document.querySelector("dialog[open]")) {
            return;
          }

          var activePanel = root.querySelector('.admin-tabs__panel[data-tab-panel="jobs"]:not([hidden])');
          if (!activePanel) {
            return;
          }

          if (event.key === "ArrowLeft") {
            var previousUrl = (activePanel.getAttribute("data-jobs-previous-url") || "").trim();
            if (previousUrl !== "") {
              event.preventDefault();
              window.location.href = previousUrl;
            }
            return;
          }

          if (event.key === "ArrowRight") {
            var nextUrl = (activePanel.getAttribute("data-jobs-next-url") || "").trim();
            if (nextUrl !== "") {
              event.preventDefault();
              window.location.href = nextUrl;
            }
          }
        });

        activate("<?= $escape($activeTab) ?>");
      })();
    </script>
    <p class="meta" style="margin-top: 1.5rem; text-align: right;">
      <?= $escape($versionSignature ?? '版本驗證：unknown') ?>
    </p>
  </div>
</div>
