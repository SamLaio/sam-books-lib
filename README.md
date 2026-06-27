# SamBooksLib

SamBooksLib 是一個可自行架設的電子書書庫網站，適合把 Calibre 書庫或一般資料夾中的電子書放到瀏覽器中管理、搜尋、閱讀與透過 OPDS 分享給閱讀器。專案以 Docker 執行，使用 SQLite 將設定、使用者與索引保存在本機 `site/data`。

## 功能

- 瀏覽書庫、搜尋、排序、分頁與已讀 / 未讀篩選。
- 顯示書名、作者、系列、標籤、格式、封面與閱讀狀態。
- 站內閱讀 `EPUB`、`PDF`、`CBZ`。
- 下載原始書籍檔案。
- 標記已讀 / 未讀。
- OPDS 書庫，支援 Basic Auth 與個人 token。
- 個人設定頁可一鍵複製 OPDS token URL。
- 帳號密碼登入、魔術登入 QR code / 一次性連結。
- 管理員後台可管理使用者、SMTP、使用者可見範圍、書庫維護與背景工作。
- SMTP 寄書排程。
- 自動或手動重建索引，並可重建非 Calibre 書目的封面。
- 介面語系支援 `zhTW` 與 `en`，主題支援淺色與深色。

## 書庫需求

最建議掛載 Calibre 書庫資料夾，也就是包含 `metadata.db` 的那個目錄。SamBooksLib 也會嘗試掃描一般資料夾中的 `epub`、`pdf`、`cbz` 檔案，但 Calibre 書庫能提供比較完整的作者、標籤、系列與封面資料。

## 快速開始

1. 複製 Docker Compose 樣板：

```bash
cp docker-compose.template.yml docker-compose.yml
```

2. 編輯 `docker-compose.yml`，至少修改：

- `SITE_BASE_URL`：站台對外網址，例如 `http://127.0.0.1:8083` 或區網 IP。不要加結尾 `/`。
- `AUTH_USERNAME` / `AUTH_PASSWORD`：初始管理員帳號與密碼。模板密碼 `password123` 只適合第一次測試。
- `PUID` / `PGID` / `TZ`：容器寫入檔案的使用者、群組與時區。
- `volumes` 中的 Calibre 書庫來源路徑：把主機上的書庫掛到容器內 `/books`。

範例：

```yml
volumes:
  - type: bind
    source: ./site
    target: /var/www/html
  - type: bind
    source: ./site/data
    target: /var/www/html/data
  - type: bind
    source: /path/to/your/CalibreLib
    target: /books
```

3. 建立並啟動：

```bash
docker compose up -d --build
```

4. 開啟網站：

```text
http://127.0.0.1:8083
```

如果你修改了 `published` port 或 `SITE_BASE_URL`，請用修改後的網址開啟。

## 第一次使用

首次啟動時，系統會在 `site/data` 自動建立必要的 SQLite 資料庫、設定檔與快取目錄。初始管理員帳號只會在使用者資料庫為空時建立，因此上線後請到管理員後台確認密碼與 email。

如果管理員登入失敗達上限，系統會重設管理員密碼並寫入：

```text
site/data/new_pass.txt
```

管理員成功登入後，這個檔案會自動清除。

## 常用網址

- `/index.php`：書庫首頁。
- `/login.php`：登入頁。
- `/settings.php`：個人設定與 OPDS token。
- `/admin_settings.php`：管理員後台。
- `/opds.php` 或 `/opds`：OPDS 入口。
- `/reader.php?id=書籍ID`：站內閱讀器。

## OPDS

一般入口：

```text
http://你的站台/opds.php
```

登入啟用時，OPDS 支援帳號密碼 Basic Auth。使用者也可以在 `/settings.php` 取得 OPDS API Token，設定頁會顯示並可一鍵複製類似下列的直接存取網址：

```text
http://你的站台/opds/你的Token
```

請把 `SITE_BASE_URL` 設成閱讀器能連到的網址，否則 OPDS、封面、下載連結和魔術登入 QR code 可能會指到錯誤位置。

## 重要設定

### 站台

- `SITE_TITLE`：網站標題，也會用在 OPDS feed 名稱。
- `SITE_BASE_URL`：對外網址，建議不要加結尾 `/`。
- `APP_LOCALE`：介面語系，支援 `zhTW` / `en`。
- `SITE_DEFAULT_THEME`：預設主題，支援 `light` / `dark`。
- `CATALOG_DEFAULT_SORT_FIELD`：書庫預設排序欄位，支援 `is_read` / `title` / `author` / `series` / `added_at`。
- `CATALOG_DEFAULT_SORT_DIRECTION`：書庫預設排序方向，支援 `asc` / `desc`。
- `OPDS_PAGE_SIZE`：OPDS 單頁顯示上限。

### 書庫與索引

- `CALIBRE_LIBRARY_PATH`：容器內書庫路徑，通常維持 `/books`。
- `SQLITE_INDEX_PATH`：書目索引 SQLite，預設 `data/library_index.sqlite`。
- `SCAN_INTERVAL_MINUTES`：自動掃描間隔，`0` 代表停用自動掃描。
- `SCAN_BATCH_SIZE`：掃描批次寫入數量。
- `SCAN_MAX_BOOKS_PER_RUN`：單次掃描最多處理多少本非 Calibre DB 書目，`0` 代表不限制。
- `SCAN_TMP_SQLITE_PATH`：掃描暫存 SQLite，建議放 `/tmp`。
- `SCAN_WATCHDOG_TIMEOUT_SECONDS`：掃描逾時保護秒數，`0` 代表停用。

### 登入

- `AUTH_ENABLED`：`1` 啟用登入，`0` 停用。
- `AUTH_USERNAME`：初始管理員帳號。
- `AUTH_PASSWORD`：初始管理員密碼。
- `AUTH_EMAIL`：初始管理員 email。
- `AUTH_SETTINGS_DB_PATH`：帳號設定 SQLite，預設 `data/auth_settings.sqlite`。
- `MIGRATIONS_DB_PATH`：migration 記錄 SQLite，預設 `data/migrations.sqlite`。
- `AUTH_SECRET_KEY`：登入密碼 hash pepper key；未設定時會自動建立 `data/auth.key`，舊版 `data/auth.secret` 只作為相容 fallback。

### SMTP

- `SMTP_HOST`
- `SMTP_PORT`
- `SMTP_ENCRYPTION`：`none` / `tls` / `ssl`
- `SMTP_USERNAME`
- `SMTP_PASSWORD`

SMTP 未完整設定時，寄送書籍功能會停用。

### 容器與下載

- `PUID` / `PGID` / `TZ`：容器寫入檔案的使用者、群組與時區。
- `PHP_PM_*` / `PHP_MEMORY_LIMIT` / `PHP_OPCACHE_ENABLE`：PHP-FPM 與記憶體設定。
- `BOOKSLIB_X_ACCEL_REDIRECT`：設為 `1` 時，下載與 OPDS 封面 / 檔案會優先交給 nginx `X-Accel-Redirect` 傳送，PHP 只負責授權與路徑檢查。
- `COMPOSER_ROOT_VERSION`：容器內 Composer 根套件版本，模板目前預設 `v2.6.6`。

下載路徑會限制在 `CALIBRE_LIBRARY_PATH` 之內；`site/data` 內的 SQLite、key、log、lock 等本機狀態檔也會由 nginx 設定拒絕直接存取。

## 日常維護

查看容器狀態：

```bash
docker compose ps
```

查看 log：

```bash
docker compose logs --tail 200 sam-books-lib
```

重建容器：

```bash
docker compose up -d --build --force-recreate
```

手動查看 migration 狀態：

```bash
docker compose exec -T sam-books-lib php /var/www/html/migrate.php status
```

查看目前容器環境中的套件版號：

```bash
docker compose exec -T sam-books-lib printenv COMPOSER_ROOT_VERSION
```

管理員後台底部也會顯示「版本驗證」字串，用來確認容器內載入的程式檔案是否已更新。

## 乾淨重建資料

如果只是更新程式，通常不需要清空 `site/data`。只有在你想重新初始化帳號、設定、索引和背景工作資料時，才執行乾淨重建。

這會刪除使用者、SMTP 設定、OPDS token、索引、log 與背景工作紀錄：

```bash
docker compose stop sam-books-lib
rm -rf site/data/*
docker compose up -d --build --force-recreate
```

重建後可確認：

```bash
docker compose ps
docker compose logs --tail 80 sam-books-lib
```

正常情況會看到容器 `healthy`，log 會出現 `runtime initialization completed.`。

## 資料保存

請務必備份 `site/data`，這裡會保存：

- 使用者與管理設定。
- SMTP 設定。
- OPDS token 與魔術登入設定。
- 書庫索引。
- migration 紀錄。
- 背景工作紀錄與 log。

電子書原始檔則保存在你掛載到 `/books` 的主機書庫目錄中。

## 故障排查

- 容器一直 `Restarting`：先看 `docker compose logs --tail 200 sam-books-lib`。
- 顯示 SQLite 不可寫：確認 `site/data` 是可寫目錄，並且 `PUID` / `PGID` 對應主機使用者。
- OPDS 或 QR code 連結錯誤：確認 `SITE_BASE_URL` 是閱讀器可連到的網址。
- 無法掃描書庫：確認 Calibre 書庫掛載來源路徑存在，且容器內 `/books` 可讀。
