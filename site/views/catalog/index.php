<?php

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<div class="wrap">
  <div class="panel">
    <h1><a class="title-home-link" href="index.php"><?= $escape($siteTitle ?? $t('layout.default_title')) ?></a></h1>
    <p class="meta">
      <span class="search-help-text"><?= $t('layout.search_help') ?></span>
      <?php if (!empty($authEnabled)): ?>
        | <?= $escape($t('layout.logged_in', ['username' => (string) ($currentUsername ?? '')])) ?>
        <?php if (!empty($isAdmin)): ?>
          | <a class="title-home-link" href="<?= $escape($adminSettingsUrl ?? 'admin_settings.php') ?>"><?= $escape($t('layout.admin_settings')) ?></a>
        <?php endif; ?>
        | <a class="title-home-link" href="<?= $escape($settingsUrl ?? 'settings.php') ?>"><?= $escape($t('layout.account_settings')) ?></a>
        | <a class="title-home-link" href="<?= $escape($logoutUrl ?? 'logout.php') ?>"><?= $escape($t('common.logout')) ?></a>
      <?php endif; ?>
      <?php if ($lastRebuildAt !== null): ?>
        | <?= $escape($t('layout.last_rebuild', ['time' => (string) $lastRebuildAt])) ?>
      <?php endif; ?>
    </p>

    <div class="toolbar">
      <form method="get" action="<?= $escape($searchAction) ?>" class="search-form">
        <input
          id="search"
          type="search"
          name="q"
          placeholder="<?= $escape($t('catalog.search_placeholder')) ?>"
          value="<?= $escape($query) ?>"
          autocomplete="off"
          autocorrect="off"
          autocapitalize="off"
          spellcheck="false"
          enterkeyhint="search"
        >
        <a class="btn secondary search-clear-link" href="<?= $escape($clearUrl) ?>"><?= $escape($t('common.clear')) ?></a>
        <button type="submit"><?= $escape($t('common.search')) ?></button>
        <input type="hidden" name="per_page" value="<?= $perPage ?>">
        <input type="hidden" name="sort" value="<?= $escape($sortField) ?>">
        <input type="hidden" name="direction" value="<?= $escape($sortDirection) ?>">
      </form>

    </div>

    <?php if ($notice !== null): ?>
      <div class="message"><?= $escape($notice) ?></div>
    <?php endif; ?>

    <?php if ($error !== null): ?>
      <div class="error"><?= $escape($error) ?></div>
    <?php else: ?>
      <?php if ((int) $totalPages > 1): ?>
        <div class="pager pager--top">
          <div class="page-links">
            <?php if ($pagination['previousUrl'] !== null): ?>
              <a class="page-link" data-pager-nav="previous" href="<?= $escape($pagination['previousUrl']) ?>"><?= $escape($t('common.previous_page')) ?></a>
            <?php endif; ?>

            <?php foreach ($pagination['items'] as $item): ?>
              <?php if ($item['type'] === 'ellipsis'): ?>
                <span class="ellipsis">...</span>
              <?php elseif ($item['current']): ?>
                <span class="page-link current"><?= $item['number'] ?></span>
              <?php else: ?>
                <a class="page-link" href="<?= $escape($item['url']) ?>"><?= $item['number'] ?></a>
              <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($pagination['nextUrl'] !== null): ?>
              <a class="page-link" data-pager-nav="next" href="<?= $escape($pagination['nextUrl']) ?>"><?= $escape($t('common.next_page')) ?></a>
            <?php endif; ?>

            <form method="get" action="<?= $escape($searchAction) ?>" class="page-jump-form">
              <input type="hidden" name="q" value="<?= $escape($query) ?>">
              <input type="hidden" name="per_page" value="<?= $perPage ?>">
              <input type="hidden" name="sort" value="<?= $escape($sortField) ?>">
              <input type="hidden" name="direction" value="<?= $escape($sortDirection) ?>">
              <input
                id="jump-page-top"
                class="page-jump-input"
                type="number"
                name="page"
                min="1"
                max="<?= max(1, (int) $totalPages) ?>"
                value="<?= max(1, (int) $currentPage) ?>"
                inputmode="numeric"
                aria-label="<?= $escape($t('common.jump_to_page')) ?>"
              >
              <button type="submit" class="page-jump-btn"><?= $escape($t('common.jump_page')) ?></button>
            </form>
          </div>

          <div class="pager-tools">
            <span class="pager-total"><?= $escape($t('common.total_items', ['count' => (string) $totalRows])) ?></span>
            <div class="page-size-links">
              <?php foreach ($perPageLinks as $perPageLink): ?>
                <?php if ($perPageLink['value'] === $perPage): ?>
                  <span class="page-size-link current"><?= $perPageLink['label'] ?></span>
                <?php else: ?>
                  <a class="page-size-link" href="<?= $escape($perPageLink['url']) ?>"><?= $perPageLink['label'] ?></a>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <table>
        <thead>
          <tr>
            <th class="col-read"><a class="sort-link" href="<?= $escape($sortHeaders['is_read']['url']) ?>"><?= $sortHeaders['is_read']['label'] . $sortHeaders['is_read']['indicator'] ?></a></th>
            <th class="col-title"><a class="sort-link" href="<?= $escape($sortHeaders['title']['url']) ?>"><?= $sortHeaders['title']['label'] . $sortHeaders['title']['indicator'] ?></a></th>
            <th class="col-author"><a class="sort-link" href="<?= $escape($sortHeaders['author']['url']) ?>"><?= $sortHeaders['author']['label'] . $sortHeaders['author']['indicator'] ?></a></th>
            <th class="col-tag"><?= $escape($t('catalog.tags')) ?></th>
            <th class="col-series"><a class="sort-link" href="<?= $escape($sortHeaders['series']['url']) ?>"><?= $sortHeaders['series']['label'] . $sortHeaders['series']['indicator'] ?></a></th>
            <th class="col-added"><a class="sort-link" href="<?= $escape($sortHeaders['added_at']['url']) ?>"><?= $sortHeaders['added_at']['label'] . $sortHeaders['added_at']['indicator'] ?></a></th>
            <th class="col-isbn">isbn</th>
            <th class="col-actions"><?= $escape($t('common.actions')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($rows === []): ?>
            <tr>
              <td colspan="8" class="empty-row"><?= $escape($t('catalog.empty')) ?></td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td class="col-read" data-label="<?= $escape($t('catalog.read')) ?>">
                  <?php if (isset($row['id'])): ?>
                    <label class="read-checkbox">
                      <input
                        type="checkbox"
                        data-read-toggle-url="<?= $escape($readStatusAction) ?>"
                        data-read-book-id="<?= (int) $row['id'] ?>"
                        <?= !empty($row['is_read']) ? 'checked' : '' ?>
                      >
                    </label>
                  <?php endif; ?>
                </td>
                <td class="col-title" data-label="<?= $escape($t('catalog.title')) ?>">
                  <?php if (($row['cover_preview_url'] ?? null) !== null): ?>
                    <?php if (($row['details_url'] ?? null) !== null): ?>
                      <a
                        class="mobile-cover-link"
                        href="#book-dialog"
                        data-book-details-url="<?= $escape($row['details_url']) ?>"
                        data-hover-cover-url="<?= $escape($row['cover_preview_url'] ?? '') ?>"
                        data-book-title="<?= $escape($row['title'] ?? '') ?>"
                        aria-label="<?= $escape($t('catalog.cover_aria', ['title' => (string) ($row['title'] ?? $t('catalog.book_fallback'))])) ?>"
                        aria-haspopup="dialog"
                      >
                        <img
                          class="mobile-cover"
                          src="<?= $escape($row['cover_preview_url']) ?>"
                          alt="<?= $escape($t('catalog.cover_aria', ['title' => (string) ($row['title'] ?? $t('catalog.book_fallback'))])) ?>"
                          loading="lazy"
                        >
                      </a>
                    <?php else: ?>
                      <img
                        class="mobile-cover"
                        src="<?= $escape($row['cover_preview_url']) ?>"
                        alt="<?= $escape($t('catalog.cover_aria', ['title' => (string) ($row['title'] ?? $t('catalog.book_fallback'))])) ?>"
                        loading="lazy"
                      >
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if (($row['details_url'] ?? null) !== null): ?>
                    <a
                      class="title-link"
                      href="#book-dialog"
                      data-book-details-url="<?= $escape($row['details_url']) ?>"
                      data-hover-cover-url="<?= $escape($row['cover_preview_url'] ?? '') ?>"
                      data-book-title="<?= $escape($row['title'] ?? '') ?>"
                      aria-haspopup="dialog"
                    ><?= $escape($row['title'] ?? '') ?></a>
                  <?php else: ?>
                    <?= $escape($row['title'] ?? '') ?>
                  <?php endif; ?>
                  <?php if (trim((string) ($row['version_label'] ?? '')) !== ''): ?>
                    <div class="book-version-note"><?= $escape($t('catalog.version', ['label' => (string) $row['version_label']])) ?></div>
                  <?php endif; ?>
                </td>
                <td class="col-author" data-label="<?= $escape($t('catalog.author')) ?>">
                  <?php if (($row['author_links'] ?? []) !== []): ?>
                    <?php foreach ($row['author_links'] as $index => $authorLink): ?>
                      <a class="author-link" href="<?= $escape($authorLink['url']) ?>"><?= $escape($authorLink['label']) ?></a><?= $index < count($row['author_links']) - 1 ? '<span class="author-divider">, </span>' : '' ?>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <?= $escape($row['author'] ?? '') ?>
                  <?php endif; ?>
                </td>
                <td class="col-tag" data-label="<?= $escape($t('catalog.tags')) ?>">
                  <?php if (($row['tag_links'] ?? []) !== []): ?>
                    <?php foreach ($row['tag_links'] as $index => $tagLink): ?>
                      <a class="tag-link" href="#search" data-fill-search="<?= $escape($tagLink['label']) ?>"><?= $escape($tagLink['label']) ?></a><?= $index < count($row['tag_links']) - 1 ? '<span class="tag-divider">, </span>' : '' ?>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <?= $escape($row['tag'] ?? '') ?>
                  <?php endif; ?>
                </td>
                <td class="col-series" data-label="<?= $escape($t('catalog.series')) ?>">
                  <?php if (($row['series_link'] ?? null) !== null): ?>
                    <a class="series-link" href="<?= $escape($row['series_link']['url']) ?>"><?= $escape($row['series_link']['label']) ?></a>
                  <?php else: ?>
                    <?= $escape($row['series'] ?? '') ?>
                  <?php endif; ?>
                </td>
                <td class="col-added" data-label="<?= $escape($t('catalog.added_at')) ?>"><?= $escape($row['added_at'] ?? '') ?></td>
                <td class="col-isbn" data-label="<?= $escape($t('catalog.isbn')) ?>"><?= $escape($row['isbn'] ?? '') ?></td>
                <td class="actions col-actions" data-label="<?= $escape($t('common.actions')) ?>">
                  <div class="action-buttons">
                    <?php if (($row['read_url'] ?? null) !== null): ?>
                      <a class="btn read-btn action-text-btn" href="<?= $escape($row['read_url']) ?>"><?= $escape($t('common.read')) ?></a>
                    <?php endif; ?>
                    <?php if (($row['download_url'] ?? null) !== null): ?>
                      <a class="btn download-btn action-text-btn" href="<?= $escape($row['download_url']) ?>"><?= $escape($t('common.download')) ?></a>
                    <?php endif; ?>
                    <?php if (($row['send_url'] ?? null) !== null): ?>
                      <a class="btn send-btn action-text-btn" href="<?= $escape($row['send_url']) ?>"><?= $escape($t('common.send')) ?></a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

      <?php if ((int) $totalPages > 1): ?>
        <div class="pager pager--bottom">
          <div class="page-links">
            <?php if ($pagination['previousUrl'] !== null): ?>
              <a class="page-link" data-pager-nav="previous" href="<?= $escape($pagination['previousUrl']) ?>"><?= $escape($t('common.previous_page')) ?></a>
            <?php endif; ?>

            <?php foreach ($pagination['items'] as $item): ?>
              <?php if ($item['type'] === 'ellipsis'): ?>
                <span class="ellipsis">...</span>
              <?php elseif ($item['current']): ?>
                <span class="page-link current"><?= $item['number'] ?></span>
              <?php else: ?>
                <a class="page-link" href="<?= $escape($item['url']) ?>"><?= $item['number'] ?></a>
              <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($pagination['nextUrl'] !== null): ?>
              <a class="page-link" data-pager-nav="next" href="<?= $escape($pagination['nextUrl']) ?>"><?= $escape($t('common.next_page')) ?></a>
            <?php endif; ?>

            <form method="get" action="<?= $escape($searchAction) ?>" class="page-jump-form">
              <input type="hidden" name="q" value="<?= $escape($query) ?>">
              <input type="hidden" name="per_page" value="<?= $perPage ?>">
              <input type="hidden" name="sort" value="<?= $escape($sortField) ?>">
              <input type="hidden" name="direction" value="<?= $escape($sortDirection) ?>">
              <input
                id="jump-page"
                class="page-jump-input"
                type="number"
                name="page"
                min="1"
                max="<?= max(1, (int) $totalPages) ?>"
                value="<?= max(1, (int) $currentPage) ?>"
                inputmode="numeric"
                aria-label="<?= $escape($t('common.jump_to_page')) ?>"
              >
              <button type="submit" class="page-jump-btn"><?= $escape($t('common.jump_page')) ?></button>
            </form>
          </div>

          <div class="pager-tools">
            <span class="pager-total"><?= $escape($t('common.total_items', ['count' => (string) $totalRows])) ?></span>
            <div class="page-size-links">
              <?php foreach ($perPageLinks as $perPageLink): ?>
                <?php if ($perPageLink['value'] === $perPage): ?>
                  <span class="page-size-link current"><?= $perPageLink['label'] ?></span>
                <?php else: ?>
                  <a class="page-size-link" href="<?= $escape($perPageLink['url']) ?>"><?= $perPageLink['label'] ?></a>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <dialog id="book-dialog" class="book-dialog" data-book-dialog data-can-send-book="<?= !empty($canSendBookByEmail) ? '1' : '0' ?>">
        <div class="book-dialog__header">
          <div class="book-dialog__heading">
            <h2 class="book-dialog__title" data-book-detail-title>書籍簡介</h2>
          </div>
          <div class="book-dialog__actions">
            <a class="btn secondary book-dialog__read action-text-btn" data-book-detail-read href="#" hidden title="<?= $escape($t('common.read')) ?>" aria-label="<?= $escape($t('common.read')) ?>">
              <?= $escape($t('common.read')) ?>
            </a>
            <button type="button" class="book-dialog__close" data-book-dialog-close aria-label="<?= $escape($t('common.close')) ?>"><?= $escape($t('common.close')) ?></button>
            <a class="btn book-dialog__download action-text-btn" data-book-detail-download href="#" hidden title="<?= $escape($t('common.download')) ?>" aria-label="<?= $escape($t('common.download')) ?>">
              <?= $escape($t('common.download')) ?>
            </a>
            <a class="btn send-btn book-dialog__send action-text-btn" data-book-detail-send href="#" hidden title="<?= $escape($t('common.send')) ?>" aria-label="<?= $escape($t('common.send')) ?>">
              <?= $escape($t('common.send')) ?>
            </a>
          </div>
        </div>

        <div class="book-dialog__body">
          <aside class="book-dialog__aside">
            <div class="book-dialog__cover" data-book-detail-cover hidden>
              <img class="book-dialog__cover-image" data-book-detail-cover-image alt="">
            </div>
          </aside>

          <div class="book-dialog__content">
            <div class="book-dialog__meta">
              <p class="book-dialog__subtitle book-dialog__meta-hint"><?= $escape($t('catalog.dialog_subtitle')) ?></p>
              <div class="book-dialog__meta-item">
                <span class="book-dialog__meta-label"><?= $escape($t('catalog.author')) ?></span>
                <span data-book-detail-author>未提供</span>
              </div>
              <div class="book-dialog__meta-item">
                <span class="book-dialog__meta-label"><?= $escape($t('catalog.tags')) ?></span>
                <span data-book-detail-tag>未提供</span>
              </div>
              <div class="book-dialog__meta-item">
                <span class="book-dialog__meta-label"><?= $escape($t('catalog.series')) ?></span>
                <span data-book-detail-series>未提供</span>
              </div>
              <div class="book-dialog__meta-item">
                <span class="book-dialog__meta-label"><?= $escape($t('catalog.isbn')) ?></span>
                <span data-book-detail-isbn>未提供</span>
              </div>
              <div class="book-dialog__meta-item">
                <span class="book-dialog__meta-label">出版社</span>
                <span data-book-detail-publisher>未提供</span>
              </div>
              <div class="book-dialog__meta-item">
                <span class="book-dialog__meta-label">語系</span>
                <span data-book-detail-language>未提供</span>
              </div>
            </div>

            <p class="book-dialog__status" data-book-detail-status hidden></p>
            <div class="book-dialog__description" data-book-detail-description>載入中...</div>
          </div>
        </div>
      </dialog>
    <?php endif; ?>
  </div>

  <div class="fab-stack">
    <button
      type="button"
      class="scroll-fab scroll-fab--top"
      data-scroll-top
      aria-label="回到最上"
      title="回到最上"
    >↑</button>
    <button
      type="button"
      class="scroll-fab scroll-fab--bottom"
      data-scroll-bottom
      aria-label="前往最底"
      title="前往最底"
    >↓</button>
  </div>
</div>
