# SamBooksLib

以 `PHP + SQLite + Docker` 建立的電子書書庫網站，支援：

- 書目列表
- 搜尋 / 排序 / 分頁
- 閱讀 `EPUB / PDF / CBZ`
- OPDS
- 帳號登入與管理員後台
- 魔術登入（短效 QR code / 一次性連結）
- 背景掃描與封面重建
- 寄送書籍到使用者信箱

## 快速開始

1. 複製 Docker 樣板：

```bash
cp docker-compose.template.yml docker-compose.yml
```

2. 修改 `docker-compose.yml`
   至少確認：

- `SITE_BASE_URL`
- `CALIBRE_LIBRARY_PATH`
- `AUTH_*`
- `PUID / PGID / TZ`
- `volumes`

3. 建立 image：

```bash
docker build -t sam/bookslib:latest .
```

4. 啟動容器：

```bash
docker compose up -d --force-recreate
```

也可以直接：

```bash
docker compose up -d --build
```

## 必要掛載

`docker-compose.yml` 內至少要有兩個掛載：

- 書庫目錄掛到 `/books`
- 持久化資料掛到 `/var/www/html/data`

例如：

```yml
volumes:
  - /your/books/path:/books
  - /your/data/path:/var/www/html/data
```

## 重要設定

以下是最常用的參數。

### 站台

- `SITE_TITLE`
  - 網站標題

- `SITE_BASE_URL`
  - 對外網址
  - 例如：`http://127.0.0.1:8083`
  - OPDS、魔術登入 QR code、部分站內連結會用到

- `APP_LOCALE`
  - 介面語系
  - 目前支援：
    - `zhTW`
    - `en`
  - 預設：`zhTW`

- `SITE_DEFAULT_THEME`
  - 預設主題
  - 可用值：
    - `light`
    - `dark`

### 書庫

- `CALIBRE_LIBRARY_PATH`
  - 容器內書庫路徑
  - 通常固定填 `/books`

- `SQLITE_INDEX_PATH`
  - 書庫索引資料庫位置
  - 預設：`data/library_index.sqlite`

- `SCAN_INTERVAL_MINUTES`
  - 自動掃描間隔，單位分鐘
  - 例如：`360` 代表每 6 小時

- `SCAN_BATCH_SIZE`
  - 單批掃描處理數量

- `SCAN_MAX_BOOKS_PER_RUN`
  - 每輪掃描最多處理幾本

- `SCAN_TMP_SQLITE_PATH`
  - 掃描暫存資料庫位置

- `SCAN_WATCHDOG_TIMEOUT_SECONDS`
  - 掃描 watchdog 秒數

### 登入

- `AUTH_ENABLED`
  - 是否啟用登入
  - `1`：啟用
  - `0`：停用

- `AUTH_USERNAME`
  - 預設管理員帳號

- `AUTH_PASSWORD`
  - 預設管理員密碼

- `AUTH_EMAIL`
  - 預設管理員 email

- `AUTH_SETTINGS_DB_PATH`
  - 帳號設定資料庫位置
  - 預設：`data/auth_settings.sqlite`

- `AUTH_SECRET_KEY`
  - 帳號密碼加密用 key
  - 未提供時系統會自動建立並寫入 `data/auth.key`

登入相關補充：

- 登入頁固定使用圖形驗證碼。
- 帳號密碼錯誤與圖形驗證碼錯誤都會計入使用者登入失敗次數。
- 管理員可在後台調整登入重試上限；一般使用者達上限後會被停用。
- 管理員帳號達上限時，系統會重設管理員密碼並寫入 `data/new_pass.txt`；管理員正確登入後會自動清除這個檔案。
- 魔術登入可在管理員後台「書庫維護」中啟用或停用。停用時登入頁不顯示魔術登入按鈕，相關 API 也不回應。

### 魔術登入

魔術登入流程：

1. 在登入頁按「魔術登入」。
2. 系統產生 10 分鐘有效的一次性 token，並顯示登入網址與 QR code。
3. 同一台原設備在 token 有效時間內重複按按鈕，會重用同一組 token。
4. 使用者用另一台設備開啟連結，完成登入並授權原設備。
5. 原設備每秒確認 token 狀態；授權完成後會自動登入並跳回首頁。
6. token 被原設備接收後會立即消耗，不能重複使用。

安全限制：

- magic token 僅儲存雜湊值，不以明文寫入資料庫。
- token 綁定原設備 session，第三方不能只靠連結接收登入。
- 授權表單有 CSRF 與同源檢查。
- QR code 由後端產生 PNG，不依賴外部服務。
- 魔術登入與 OPDS token / Basic Auth 邏輯分離。

### SMTP

- `SMTP_HOST`
  - SMTP 主機

- `SMTP_PORT`
  - SMTP 連接埠

- `SMTP_ENCRYPTION`
  - 加密方式
  - 可用值：
    - `none`
    - `tls`
    - `ssl`

- `SMTP_USERNAME`
  - SMTP 帳號

- `SMTP_PASSWORD`
  - SMTP 密碼

### 其他

- `MIGRATIONS_DB_PATH`
  - migration 記錄資料庫位置
  - 預設：`data/migrations.sqlite`

- `OPDS_PAGE_SIZE`
  - OPDS 每頁筆數

- `CATALOG_DEFAULT_SORT_FIELD`
  - 書目列表預設排序欄位

- `CATALOG_DEFAULT_SORT_DIRECTION`
  - 書目列表預設排序方向

- `PUID`
  - 容器寫入檔案使用的使用者 UID

- `PGID`
  - 容器寫入檔案使用的群組 GID

- `TZ`
  - 時區

## 主要網址

- `/index.php`
  - 書目列表

- `/login.php`
  - 登入頁

- `/magic_login.php`
  - 魔術登入連結、QR code 與輪詢 API

- `/settings.php`
  - 帳號設定

- `/admin_settings.php`
  - 管理員設定

- `/opds.php`
  - OPDS

## 常用維護

### 重新掃描

管理員頁可直接手動重建索引。

### 重建封面

管理員頁可手動建立封面重建 job。

### 登入與魔術登入設定

管理員頁可調整：

- 登入重試次數上限
- 是否啟用魔術登入
- 預設介面語系

### 查看 migration 狀態

```bash
docker compose exec -T sam-books-lib php /var/www/html/migrate.php status
```

### 查看容器 log

```bash
docker compose logs --tail 200 sam-books-lib
```

### 重建容器

```bash
docker build -t sam/bookslib:latest .
docker compose up -d --force-recreate
```

## 備註

- 首次啟動會自動初始化：
  - `auth_settings.sqlite`
  - `library_index.sqlite`
  - `migrations.sqlite`
- 管理員設定、使用者、SMTP、魔術登入設定會存在 `auth_settings.sqlite`
