# SamBooksLib Developer README

這份文件給開發者與維護者使用，記錄目前專案架構、Docker 啟動流程、主要入口與驗證方式。一般部署與使用請先看 `README.md`。

## 技術概覽

- Runtime：Docker。
- Web：PHP 8.4 FPM + nginx。
- Worker：Go 1.22，編譯成 `/usr/local/bin/books-worker`。
- Storage：SQLite。
- PHP autoload：Composer PSR-4，命名空間 `Calibre\` 對應 `site/src/`。
- 前端：原生 PHP view、CSS、JavaScript。
- 主要容器名稱：`sam-books-lib`。

目前沒有正式測試框架；較大改動後請依本文「手動驗證清單」逐項確認。

## 專案結構

```text
.
├─ Dockerfile
├─ docker-compose.yml
├─ docker-compose.template.yml
├─ docker/
│  ├─ bookslib-entrypoint.sh
│  └─ nginx/
├─ site/
│  ├─ *.php
│  ├─ assets/
│  ├─ data/
│  ├─ src/
│  └─ views/
├─ worker/
│  ├─ go.mod
│  └─ cmd/books-worker/
├─ site-enable/
├─ scripts/
└─ read/
```

`docker-compose.template.yml` 是文件與部署範例的來源；`docker-compose.yml` 可能含本機私密設定，不要拿來當公開範例。`site-enable/` 保留給舊版或本機 nginx 實驗，模板預設不掛載它，容器會使用 `docker/nginx/default.conf`。

## 常用指令

建立 image：

```bash
docker build -t sam/bookslib:latest .
```

啟動或重建：

```bash
docker compose up -d --build --force-recreate
```

看容器狀態：

```bash
docker compose ps
```

看 log：

```bash
docker compose logs --tail 200 sam-books-lib
```

進容器：

```bash
docker compose exec -T sam-books-lib sh
```

跑 migration 狀態：

```bash
docker compose exec -T sam-books-lib php /var/www/html/migrate.php status
```

手動執行 migration：

```bash
docker compose exec -T sam-books-lib php /var/www/html/migrate.php migrate all
```

手動跑 worker 一輪：

```bash
docker compose exec -T sam-books-lib books-worker --app-root /var/www/html --once
```

手動重建索引：

```bash
docker compose exec -T sam-books-lib php /var/www/html/scan.php
```

手動重建非 Calibre 書目封面：

```bash
docker compose exec -T sam-books-lib php /var/www/html/cover_rebuild.php
```

驗證模板可被 Compose 解析：

```bash
docker compose -f docker-compose.template.yml config
```

## Docker 啟動流程

Image build 分兩階段：

1. `golang:1.22-alpine` 編譯 `worker/cmd/books-worker`。
2. `php:8.4-fpm-alpine` 安裝 PHP extension、nginx，複製 `site/`、nginx 設定與 worker binary。

容器啟動後由 `docker/bookslib-entrypoint.sh` 負責：

1. 建立 `data/`、`data/opds-cache/`、`thumb/`。
2. 建立或沿用符合 `PUID` / `PGID` 的 runtime 使用者。
3. 依 `PUID` / `PGID` 調整資料目錄與 runtime 檔案權限。
4. 寫入 PHP 與 PHP-FPM runtime 設定。
5. 執行 `site/init_runtime.php`。
6. 建立 crontab，每分鐘執行一次 `books-worker --once`。
7. 啟動 `crond`、`php-fpm` 與 nginx。

PHP-FPM pool、`init_runtime.php` 和 cron worker 必須使用同一組 runtime UID/GID；不要把 pool user 寫死為 `www-data`，否則正式 Linux 主機上 SQLite 可能會在初始化成功後被網頁請求判定為不可寫。

`site/data` 是 bind mount。若舊檔案 owner 或權限錯誤，entrypoint 會盡量修正；如果底層檔案系統拒絕 chmod/chown，最乾淨的處理方式通常是停容器後清空 `site/data` 再重建。

## 設定來源

Docker Compose 以 environment 注入設定。程式也會讀取 `site/config.env` 與 `site/config.docker.env` 作為預設或本機開發參考。常見設定集中在：

- 站台：`SITE_TITLE`、`SITE_BASE_URL`、`APP_LOCALE`、`SITE_DEFAULT_THEME`、`CATALOG_DEFAULT_SORT_FIELD`、`CATALOG_DEFAULT_SORT_DIRECTION`、`OPDS_PAGE_SIZE`。
- 書庫：`CALIBRE_LIBRARY_PATH`、`SQLITE_INDEX_PATH`。
- 掃描：`SCAN_INTERVAL_MINUTES`、`SCAN_BATCH_SIZE`、`SCAN_MAX_BOOKS_PER_RUN`、`SCAN_TMP_SQLITE_PATH`、`SCAN_WATCHDOG_TIMEOUT_SECONDS`。
- 帳號：`AUTH_ENABLED`、`AUTH_USERNAME`、`AUTH_PASSWORD`、`AUTH_EMAIL`、`AUTH_SETTINGS_DB_PATH`、`MIGRATIONS_DB_PATH`、`AUTH_SECRET_KEY`。
- SMTP：`SMTP_HOST`、`SMTP_PORT`、`SMTP_ENCRYPTION`、`SMTP_USERNAME`、`SMTP_PASSWORD`。
- 容器：`PUID`、`PGID`、`TZ`、`PHP_PM_*`、`PHP_MEMORY_LIMIT`、`PHP_OPCACHE_ENABLE`、`BOOKSLIB_X_ACCEL_REDIRECT`、`COMPOSER_ROOT_VERSION`。

模板預設值偏向低記憶體與安全測試：

- `mem_limit=256m`
- `PHP_PM=ondemand`
- `PHP_PM_MAX_CHILDREN=1`
- `PHP_MEMORY_LIMIT=64M`
- `PHP_OPCACHE_ENABLE=0`
- `SCAN_INTERVAL_MINUTES=5`
- `SCAN_BATCH_SIZE=10`
- `SCAN_MAX_BOOKS_PER_RUN=100`

本機正式部署可依書庫大小調整掃描批次與 PHP-FPM 設定。

## 主要資料庫

目前使用三個 SQLite 檔案：

- `site/data/auth_settings.sqlite`
  - users
  - app settings
  - SMTP settings
  - OPDS token
  - magic login token
  - scan jobs / background jobs

- `site/data/library_index.sqlite`
  - 書目索引
  - 搜尋資料
  - 格式、作者、標籤、系列
  - 已讀 / 未讀狀態
  - 封面路徑與快照資料

- `site/data/migrations.sqlite`
  - migration 套用紀錄與 checksum。

`AuthService` 和 `LibraryIndex` 都會在啟動或使用時確保需要的 schema 存在。`MigrationRunner` 負責 migration 記錄、checksum 檢查與 mismatch recovery。

## 乾淨重建資料

乾淨重建會移除使用者、SMTP 設定、OPDS token、書庫索引、log 與背景工作紀錄。只在你確定要重新初始化資料時使用：

```bash
docker compose stop sam-books-lib
rm -rf site/data/*
docker compose up -d --build --force-recreate
```

重建後確認：

```bash
docker compose ps
docker compose logs --tail 80 sam-books-lib
find site/data -maxdepth 1 -printf '%M %u %g %p\n'
```

正常情況會看到容器 `healthy`，log 會出現 `runtime initialization completed.`，`site/data` 內的新檔案 owner 應與 `PUID` / `PGID` 對應。

## Web 入口

前台：

- `site/index.php`：書庫列表。
- `site/book.php`：書籍詳情。
- `site/download.php`：下載原始檔。
- `site/send.php`：排程寄送書籍。
- `site/read.php`：已讀狀態 API。
- `site/theme.php`：主題偏好。

帳號與管理：

- `site/login.php`
- `site/logout.php`
- `site/magic_login.php`
- `site/settings.php`
- `site/admin_settings.php`

閱讀器：

- `site/reader.php`
- `site/reader_manifest.php`
- `site/reader_page.php`
- `site/reader_asset.php`

OPDS：

- `site/opds.php`

背景 / CLI：

- `site/init_runtime.php`
- `site/migrate.php`
- `site/scan.php`
- `site/cover_rebuild.php`
- `site/job.php`

`site/job.php` 是舊 PHP job runner 入口，目前容器排程主要使用 Go worker。

## 主要 PHP 模組

Catalog：

- `Calibre\Controllers\CatalogController`
- `Calibre\Controllers\BookDetailsController`
- `Calibre\Support\CatalogRequest`
- `Calibre\Support\CatalogUrlGenerator`
- `Calibre\Support\Pagination`

Index / Scan：

- `Calibre\CalibreLibrary`
- `Calibre\LibraryIndex`
- `Calibre\ScanService`
- `Calibre\ScanLauncher`
- `Calibre\Services\ScanScheduleService`

Auth：

- `Calibre\Services\AuthService`
- `Calibre\Services\LoginCaptchaService`
- `Calibre\Services\MagicLoginService`
- `Calibre\Services\QrPngService`
- `Calibre\Controllers\AuthLoginController`
- `Calibre\Controllers\AuthLogoutController`
- `Calibre\Controllers\AuthSettingsController`
- `Calibre\Controllers\AdminSettingsController`
- `Calibre\Controllers\MagicLoginController`

Reader：

- `Calibre\Controllers\ReaderController`
- `Calibre\Controllers\ReaderManifestController`
- `Calibre\Controllers\ReaderPageController`
- `Calibre\Controllers\ReaderAssetController`
- `Calibre\Services\ReaderAccessService`
- `Calibre\Services\ReaderEpubService`
- `Calibre\Services\ReaderPdfService`
- `Calibre\Services\ReaderComicService`

OPDS：

- `Calibre\Controllers\OpdsController`
- `Calibre\Services\OpdsService`
- `Calibre\Services\OpdsAssetService`
- `Calibre\Services\OpdsCacheService`
- `Calibre\Support\OpdsUrlGenerator`

Email / Download / Status：

- `Calibre\Services\DownloadService`
- `Calibre\Http\AccelRedirect`
- `Calibre\Services\SmtpMailer`
- `Calibre\Services\ReadStatusService`
- `Calibre\Controllers\SendBookController`
- `Calibre\Controllers\DownloadController`
- `Calibre\Controllers\ReadStatusController`

## Worker 與背景工作

Go worker 位於 `worker/cmd/books-worker/`。容器內每分鐘由 cron 執行：

```text
books-worker --app-root /var/www/html --once
```

worker 主要負責：

- reconcile stale running jobs。
- fail expired pending jobs。
- 建立下一個自動掃描排程。
- reserve due job。
- 執行 scan、cover rebuild、send book 等背景工作。
- 更新 job status、heartbeat、progress 與 error。

背景工作資料存在 `auth_settings.sqlite` 的 job tables。PHP 管理頁會透過 `ScanScheduleService` 佇列化手動掃描、封面重建與寄送工作。

## 掃描策略

掃描由 `ScanService` 驅動，核心資料來源在 `CalibreLibrary`：

- 優先讀取 Calibre `metadata.db`。
- 對一般檔案系統書目做補充掃描。
- 支援 `EPUB`、`PDF`、`CBZ`；格式搜尋中 `pdb` 會視為 `pdf` 相容詞。
- 掃描會寫入暫存 SQLite，完成後再更新正式索引。
- `SCAN_MAX_BOOKS_PER_RUN` 可限制每輪處理的非 Calibre DB 書目數。
- 掃描過程會保留 resume flag，避免中斷後完全重頭處理。
- 封面可來自 Calibre cover、同目錄封面檔、EPUB 內封面或 CBZ 第一張圖。

## 搜尋規則

搜尋入口在 `CatalogRequest` / `LibraryIndex`。目前支援：

- 書名與作者關鍵字。
- 格式關鍵字：`pdf`、`epub`、`cbz`，以及 `pdb` 對應 `pdf`。
- 基本布林語法：`and`、`or`、`+`、`-`、`||`、括號。
- 依環境能力使用 SQLite FTS 或 fallback 查詢。

排序欄位目前包含：

- `is_read`
- `title`
- `author`
- `series`
- `added_at`

## OPDS 注意事項

`site/opds.php` 處理 OPDS 驗證與路由，`OpdsController` / `OpdsService` 負責 feed 內容。

支援的 feed 包含：

- index
- books
- new
- authors / author
- tags / tag
- series / series_books
- read
- unread
- search
- cover
- download
- OpenSearch description

登入啟用時，OPDS 支援：

- Basic Auth。
- 使用者在設定頁取得的 OPDS token，路徑形式為 `/opds/{token}`。

OPDS URL 生成優先使用 `SITE_BASE_URL`。修改相關邏輯時，請確認 token URL、cover URL、download URL、pagination link 與 OpenSearch template 都正常。

`BOOKSLIB_X_ACCEL_REDIRECT=1` 時，OPDS cover/download 與一般下載會在 PHP 完成授權與路徑檢查後交給 nginx internal location 傳送。相關 internal route 定義在 `docker/nginx/default.conf`，PHP 端入口為 `Calibre\Http\AccelRedirect`。修改下載或封面邏輯時，需同時確認 X-Accel 與 PHP fallback 都能正常回應。

## 登入與魔術登入

一般登入：

- 入口：`site/login.php`。
- 驗證碼：`LoginCaptchaService`。
- 帳密與使用者狀態：`AuthService`。
- 密碼與敏感設定依 `AUTH_SECRET_KEY` 加密；未設定時會建立 `data/auth.key`，舊版 `data/auth.secret` 會作為相容 fallback。

登入失敗規則：

- 密碼錯誤與驗證碼錯誤都會累計失敗次數。
- 一般使用者達上限後停用。
- 管理員達上限後重設密碼並寫入 `site/data/new_pass.txt`。
- 管理員成功登入後會清除 `new_pass.txt`。

魔術登入：

- 入口：`site/magic_login.php`。
- token 10 分鐘有效。
- token 明文只回前端一次，資料庫只保存 hash。
- token 綁定原設備 session nonce。
- 授權 POST 檢查 CSRF 與同源。
- 可在管理員後台停用；停用後登入頁不顯示按鈕，API 回 404。

## 前端檔案

- 共用樣式：`site/assets/css/app.css`。
- 閱讀器樣式：`site/assets/css/reader.css`。
- 書庫列表互動：`site/assets/js/catalog.js`。
- 閱讀器互動：`site/assets/js/reader.js`。
- View：`site/views/`。
- 語系：`site/src/Support/Lang/zhTW.php`、`site/src/Support/Lang/en.php`。

新增 UI 文字時，請同步補 `zhTW` 與 `en`。

## 版本核對

管理員頁底部會顯示版本驗證文字。重建 Docker 後可用下面指令核對容器內實際版本：

```bash
docker compose exec -T sam-books-lib sh -lc 'php -r '\''require "/var/www/html/bootstrap.php"; $c = new Calibre\Controllers\AdminSettingsController("/var/www/html"); $r = new ReflectionClass($c); $m = $r->getMethod("buildVersionSignature"); $m->setAccessible(true); echo $m->invoke($c), PHP_EOL;'\'''
```

## 開發注意事項

- 修改已套用 migration 的行為時，新增下一個 migration 或在服務初始化中補 schema，不要直接改既有 migration checksum。
- 修改登入流程時，確認 OPDS Basic Auth 與 OPDS token 沒被 web session 邏輯影響。
- 修改掃描流程時，確認 `scan.resume.json`、tmp SQLite、正式索引與封面快取的互動。
- 修改寄信流程時，確認 SMTP 設定未完成時 UI 與 job 都會安全失敗。
- 修改 OPDS 時，確認 XML error 仍可被閱讀器解析，不要輸出 HTML 錯誤頁。
- 修改 PHP / CSS / JS 後如果畫面沒變，先強制重新整理；模板預設 `PHP_OPCACHE_ENABLE=0`，本機正式部署若改成 `1` 要留意 timestamp 驗證設定。
- `docker-compose.yml` 可能含本機私密設定；文件與範例請以 `docker-compose.template.yml` 為準。

## 手動驗證清單

較大改動後至少確認：

1. `docker compose -f docker-compose.template.yml config` 可解析。
2. `docker compose up -d --build --force-recreate` 可啟動。
3. `docker compose ps` healthcheck 正常。
4. `migrate.php status` 無失敗。
5. `login.php` 可登入，驗證碼錯誤與密碼錯誤都會累計失敗次數。
6. 魔術登入啟用時可建立 QR code，停用時按鈕消失且 API 回 404。
7. `index.php` 可搜尋、排序、分頁與切換已讀狀態。
8. `book.php` 可顯示書籍詳情。
9. `reader.php` 可讀 EPUB、PDF、CBZ。
10. `download.php` 只能下載書庫根目錄內的檔案。
11. SMTP 設定完整時可寄測試信與排程寄書。
12. `opds.php` 可產生 index、books、authors、tags、series、read、unread、search feed。
13. OPDS Basic Auth 與 `/opds/{token}` 都可用。
14. 手動重建索引與封面重建可以排程並完成。
15. `docker compose logs --tail 200 sam-books-lib` 沒有新的 fatal error。

## 故障排查入口

- 容器 log：`docker compose logs --tail 200 sam-books-lib`。
- Cron / worker log：`site/data/cron.log`。
- 掃描 log：`site/data/scan.log`。
- 封面重建 log：`site/data/cover_rebuild.log`。
- 管理員重設密碼：`site/data/new_pass.txt`。
- SQLite 檔案：`site/data/*.sqlite`。
- OPDS cache：`site/data/opds-cache/`。
- 模板解析：`docker compose -f docker-compose.template.yml config`。
