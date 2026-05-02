<?php

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<div class="wrap reader-page">
  <div class="panel reader-panel">
    <div class="reader-shell" data-reader-app
      data-reader-book-id="<?= (int) ($readerBookId ?? 0) ?>"
      data-reader-title="<?= $escape($readerBookTitle ?? '') ?>"
      data-reader-format="<?= $escape($readerBookFormat ?? '') ?>"
      data-reader-manifest-url="<?= $escape($readerManifestUrl ?? '') ?>"
      data-reader-page-url="<?= $escape($readerInitialPageUrl ?? '') ?>"
      data-reader-pdf-worker-url="<?= $escape('assets/vendor/pdfjs/pdf.worker.min.js') ?>"
      data-reader-loading-manifest="<?= $escape($t('reader.loading_manifest')) ?>"
      data-reader-loading-section="<?= $escape($t('reader.loading_section')) ?>"
      data-reader-manifest-error="<?= $escape($t('reader.manifest_load_failed')) ?>"
      data-reader-open-toc-label="<?= $escape($t('reader.toc')) ?>"
      data-reader-close-toc-label="<?= $escape($t('common.close')) ?>"
    >
      <header class="reader-toolbar">
        <div class="reader-toolbar__title-row">
          <div class="reader-toolbar__title-group">
            <h1 class="reader-toolbar__title"><?= $escape(($readerBookTitle ?? '') !== '' ? $readerBookTitle : $t('common.read')) ?></h1>
            <p class="reader-toolbar__status" data-reader-status hidden><?= $escape($readerError ?? $t('reader.loading_manifest')) ?></p>
          </div>
          <a class="btn secondary reader-toolbar__back" href="<?= $escape($backUrl ?? 'index.php') ?>"><?= $escape($t('common.back_home')) ?></a>
        </div>
      </header>

      <div class="reader-layout" data-reader-layout>
        <aside class="reader-toc" data-reader-toc-panel hidden>
          <div class="reader-toc__header">
            <h2><?= $escape($t('reader.toc')) ?></h2>
          </div>
          <nav class="reader-toc__list" data-reader-toc-list></nav>
        </aside>

        <section class="reader-viewport">
          <div class="reader-viewport__content" data-reader-content aria-label="<?= $escape($readerBookTitle ?? $t('common.read')) ?>"></div>
          <div class="reader-empty" data-reader-empty <?= empty($readerError) ? 'hidden' : '' ?>>
            <p><?= $escape($readerError ?? '') ?></p>
          </div>
        </section>
      </div>

      <div class="reader-toolbar__actions">
        <button type="button" class="btn secondary reader-toolbar__icon-btn reader-toolbar__icon-btn--nav" data-reader-prev disabled title="<?= $escape($t('reader.previous_section')) ?>" aria-label="<?= $escape($t('reader.previous_section')) ?>">
          <span aria-hidden="true">←</span>
        </button>
        <button type="button" class="btn secondary reader-toolbar__icon-btn reader-toolbar__icon-btn--toc" data-reader-open-toc title="<?= $escape($t('reader.toc')) ?>" aria-label="<?= $escape($t('reader.toc')) ?>">
          <span data-reader-open-toc-text><?= $escape($t('reader.toc')) ?></span>
        </button>
        <button type="button" class="btn secondary reader-toolbar__icon-btn reader-toolbar__icon-btn--nav" data-reader-next disabled title="<?= $escape($t('reader.next_section')) ?>" aria-label="<?= $escape($t('reader.next_section')) ?>">
          <span aria-hidden="true">→</span>
        </button>
      </div>
    </div>
  </div>
</div>
