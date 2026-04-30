# SamBooksLib

以 PHP + SQLite + Docker 建立的電子書書庫網站，目標是用資料夾書庫或 Calibre 書庫提供：

- Web 書目列表
- OPDS 訂閱
- 背景掃描與封面重建
- 帳號登入與管理員後台
- 寄送書籍到使用者信箱 / Kindle

目前專案已經是以 Docker 為主要執行方式設計。

## 功能總覽

- 支援讀取 Calibre `metadata.db`
- 若未命中 Calibre 資料，會補掃描資料夾中的電子書
- 支援常見格式：`epub`、`pdf`、`mobi`、`azw3`、`txt`、`html`、`rtf`
- 會建立 SQLite 書庫索引，前台列表不直接掃書庫
- 支援封面快取到 `data/thumb/`
- 支援書名 / 作者 / 系列 / 標籤 / ISBN 搜尋
- 搜尋語法支援：`+`、`-`、`||`、`()`
- 支援已讀狀態、排序、分頁、每頁筆數切換
- 支援書籍簡介彈窗、封面預覽、下載、寄送
- 支援 OPDS、Basic Auth、Token OPDS 路徑
- 支援登入制度、管理員頁、SMTP 設定、排程列表
- 支援 migration runner 與 CLI

## 專案結構

```text
.
├─ Dockerfile
├─ docker-compose.yml
├─ docker-composer-online.yml
├─ pushDocker.bat
├─ site/
│  ├─ admin_settings.php
│  ├─ index.php
│  ├─ login.php
│  ├─ opds.php
│  ├─ scan.php
│  ├─ job.php
│  ├─ migrate.php
│  ├─ config.env
│  ├─ config.docker.env
│  ├─ src/
│  ├─ views/
│  ├─ assets/
│  └─ data/
├─ docker/
│  ├─ cont-init.d/
│  ├─ cron/
│  └─ s6-overlay/
└─ site-enable/
```

## 目前的資料庫設計

目前固定使用 3 顆 SQLite：

1. `auth_settings.sqlite`
- 帳號
- SMTP 設定
- 使用者偏好
- 預設語系
- `scan_jobs`

2. `library_index.sqlite`
- 書目資料
- 路徑索引
- FTS 搜尋索引

3. `migrations.sqlite`
- 專門記錄 `auth` / `library` 兩顆資料庫的 migration 歷程

## 主要網站入口

### 頁面入口

- `index.php`：主列表頁
- `login.php`：登入頁
- `logout.php`：登出
- `settings.php`：帳號設定
- `admin_settings.php`：管理員設定
- `opds.php`：OPDS 入口

### API / 動作入口

- `book.php`：書籍詳細資料 API
- `download.php`：下載書籍
- `send.php`：寄送書籍
- `read.php`：更新已讀狀態
- `theme.php`：儲存主題
- `scan_request.php`：手動重建索引排程請求
- `captcha.php`：登入驗證碼圖片

### CLI / 背景入口

- `scan.php`：真正執行掃描
- `cover_rebuild.php`：真正執行封面重建
- `job.php`：cron / job dispatcher
- `migrate.php`：migration CLI
- `init_runtime.php`：容器啟動初始化

## 設定來源優先順序

程式會讀取：

1. 容器 / 系統環境變數
2. `site/config.env`

如果是 Docker 啟動：
- `docker-compose.yml` 的 `environment` 會覆蓋 `site/config.env`

語系目前優先順序：

- 使用者設定
- DB 預設語系
- `compose` / 環境變數
- `config.env`

## 重要設定

以下是目前最常用的設定範例：

```env
CALIBRE_LIBRARY_PATH=/books
SITE_TITLE=SamBooksLib
APP_LOCALE=zhTW
SITE_BASE_URL=http://127.0.0.1:8083
SITE_DEFAULT_THEME=light

CATALOG_DEFAULT_SORT_FIELD=added_at
CATALOG_DEFAULT_SORT_DIRECTION=desc
OPDS_PAGE_SIZE=30

SMTP_HOST=
SMTP_PORT=
SMTP_ENCRYPTION=none
SMTP_USERNAME=
SMTP_PASSWORD=

AUTH_ENABLED=1
AUTH_USERNAME=admin
AUTH_PASSWORD=password123
AUTH_EMAIL=
AUTH_SETTINGS_DB_PATH=data/auth_settings.sqlite
AUTH_SECRET_KEY=
MIGRATIONS_DB_PATH=data/migrations.sqlite

SQLITE_INDEX_PATH=data/library_index.sqlite
SCAN_INTERVAL_MINUTES=5
SCAN_BATCH_SIZE=50
SCAN_MAX_BOOKS_PER_RUN=500
SCAN_TMP_SQLITE_PATH=/tmp/books-scan.tmp.sqlite
SCAN_WATCHDOG_TIMEOUT_SECONDS=180
```

### 站台設定

- `CALIBRE_LIBRARY_PATH`
  - 書庫在容器內的掛載路徑
  - Docker 模式通常固定填 `/books`
  - 掃描與下載都會以這個路徑為根目錄

- `SITE_TITLE`
  - 網站標題
  - 會套用到：
    - 頁面標題
    - OPDS feed 名稱
    - 測試信件 / 寄送信件標題

- `APP_LOCALE`
  - 預設介面語系
  - 目前支援：
    - `zhTW`
    - `en`
  - 這是最低層預設值，實際顯示仍可能被「DB 預設語系」或「使用者個別語系」覆蓋

- `SITE_BASE_URL`
  - 站台對外網址
  - 主要用在：
    - OPDS 連結產生
    - OPDS token URL
  - 建議填可從閱讀器、手機或區網裝置實際連到的完整網址
  - 不要加結尾 `/`

- `SITE_DEFAULT_THEME`
  - 預設主題
  - 可用值：
    - `light`
    - `dark`
  - 當使用者尚未設定個人主題時，會使用這個值

- `CATALOG_DEFAULT_SORT_FIELD`
  - 書目列表預設排序欄位
  - 可用值：
    - `is_read`
    - `title`
    - `author`
    - `series`
    - `added_at`

- `CATALOG_DEFAULT_SORT_DIRECTION`
  - 書目列表預設排序方向
  - 可用值：
    - `asc`
    - `desc`

- `OPDS_PAGE_SIZE`
  - OPDS 單頁輸出上限
  - 建議值：`20`、`30`、`50`
  - 範圍：`1 ~ 500`
  - 太大會讓閱讀器載入變慢

### SMTP 設定

- `SMTP_HOST`
  - SMTP 主機名稱或 IP
  - 例如：
    - `smtp.gmail.com`
    - `mail.example.com`

- `SMTP_PORT`
  - SMTP 連接埠
  - 常見值：
    - `25`
    - `465`
    - `587`

- `SMTP_ENCRYPTION`
  - SMTP 加密方式
  - 可用值：
    - `none`
    - `tls`
    - `ssl`
  - Gmail 常見組合：
    - `587 + tls`
    - `465 + ssl`

- `SMTP_USERNAME`
  - SMTP 登入帳號
  - 若是 email 服務，通常就是寄件者信箱

- `SMTP_PASSWORD`
  - SMTP 登入密碼
  - 若使用 Gmail，通常應填 App Password，不是一般登入密碼

SMTP 補充規則：

- 若 DB 尚未設定 SMTP，啟動時會把 env / compose 的值初始化進 DB
- 一旦管理員在 UI 更新過 SMTP，之後不會在每次啟動時被 env 值覆蓋
- 只有 SMTP 設定完整，且使用者本身有 email 時，前端才會出現寄送功能

### 登入與帳號設定

- `AUTH_ENABLED`
  - 是否啟用登入制度
  - 可用值：
    - `1`
    - `0`
  - `1` 時：
    - Web 需登入
    - OPDS 需 Basic Auth 或 token

- `AUTH_USERNAME`
  - 預設管理員帳號
  - 只用於「建立 / 補 seed 預設管理員」
  - 不再於每次請求覆寫 DB 中使用者資料

- `AUTH_PASSWORD`
  - 預設管理員密碼
  - 與 `AUTH_USERNAME` 一樣，只用於 seed 預設管理員
  - 現在不會在每次頁面請求時把資料庫中的密碼再覆蓋回去

- `AUTH_EMAIL`
  - 預設管理員 email
  - 只有在明確設定非空值時，才會在 seed 管理員時寫入 / 覆寫
  - 若留空，不會把現有 email 洗成空字串

- `AUTH_SECRET_KEY`
  - 密碼 pepper / 加密密鑰
  - 若未設定，系統會在 `data/auth.key` 自動產生
  - 換掉這個值後，舊密碼驗證可能受影響，正式環境不建議隨意變更

### SQLite / Migration 設定

- `AUTH_SETTINGS_DB_PATH`
  - 帳號設定資料庫路徑
  - 目前內容包含：
    - `users`
    - `app_settings`
    - `scan_jobs`

- `MIGRATIONS_DB_PATH`
  - migration 記錄資料庫路徑
  - 專門記錄：
    - `auth`
    - `library`
    兩組 migration 歷程與失敗紀錄

- `SQLITE_INDEX_PATH`
  - 書庫索引資料庫路徑
  - 目前內容包含：
    - `books`
    - `book_paths`
    - `meta`
    - `books_fts`

路徑補充：

- 相對路徑一律以 `site/` 為基準
- 若用 Docker，通常會讓這些 DB 都落在 `/var/www/html/data`

### 掃描設定

- `SCAN_INTERVAL_MINUTES`
  - 自動掃描最小間隔，單位為分鐘
  - 容器內 cron 每分鐘都會叫一次 `job.php --cron`
  - 但只有超過這個間隔才會真正執行掃描
  - `0` 代表完全關閉自動掃描，只保留手動重建

- `SCAN_BATCH_SIZE`
  - 單次 transaction / 寫入批次大小
  - 建議值：
    - `20`
    - `50`
    - `100`
  - 太小會增加 transaction 次數，太大則會提高單次 I/O 與記憶體壓力

- `SCAN_MAX_BOOKS_PER_RUN`
  - 每輪掃描最多處理幾本「不在 calibre db 中的 fs 書目」
  - `0` 代表不限制
  - 大書庫建議保留 `500` 左右，避免單次掃描拖太久

- `SCAN_TMP_SQLITE_PATH`
  - 掃描暫存 SQLite 路徑
  - 建議維持 `/tmp/books-scan.tmp.sqlite`
  - 放在容器內 `/tmp`，可以減少 bind mount 磁碟 I/O 問題

- `SCAN_WATCHDOG_TIMEOUT_SECONDS`
  - 掃描 watchdog 逾時秒數
  - 若舊掃描程序長時間卡住，會由 watchdog 嘗試中止
  - `0` 代表停用 watchdog

### 容器 / PHP 設定

- `PUID`
  - 容器對 `data/`、`thumb/` 修權限時使用的 uid
  - 若是 NAS 或 Linux 主機，應改成實際檔案擁有者的 uid

- `PGID`
  - 容器對 `data/`、`thumb/` 修權限時使用的 gid

- `TZ`
  - 容器時區
  - 建議正式使用時改成你的所在地，例如：
    - `Asia/Taipei`

- `WEBHOME`
  - base image 使用的網站根目錄
  - 目前固定為 `/var/www/html`
  - 一般不需要改

- `PHP_PM`
  - PHP-FPM process manager 模式
  - 目前預設：
    - `ondemand`

- `PHP_PM_MAX_CHILDREN`
  - 同時可處理的 PHP worker 數量
  - 目前專案預設壓得比較保守，方便低記憶體環境使用

- `PHP_PM_START_SERVERS`
- `PHP_PM_MIN_SPARE_SERVERS`
- `PHP_PM_MAX_SPARE_SERVERS`
  - PHP-FPM 行為參數
  - 主要影響程序預熱與待命策略

- `PHP_PM_MAX_REQUESTS`
  - 每個 worker 最多處理幾次請求後重建
  - 可降低長時間運行的 memory leak 累積風險

- `PHP_MEMORY_LIMIT`
  - 單個 PHP 程序記憶體上限
  - 目前預設 `64M`
  - 若後續要提高併發或容納更大查詢，可再調整

- `PHP_OPCACHE_ENABLE`
  - 是否啟用 OPcache
  - 目前預設 `0`

- `COMPOSER_ROOT_VERSION`
  - 避免容器內啟動 composer 時出現 root package version warning

## Docker 啟動

### Docker 樣板

請先複製 [docker-compose.template.yml](/mnt/d/project/books/docker-compose.template.yml) 成你自己的 `docker-compose.yml`，再依實際環境調整：

- 對外 Port
- `SITE_BASE_URL`
- `AUTH_*`
- `SMTP_*`
- `PUID / PGID / TZ`
- 書庫掛載路徑
- `./site/data` 是否改到你的持久化目錄

建立 image：

```bash
docker build -t sam/bookslib:latest .
```

再用你自己的 compose 啟動：

```bash
docker compose up -d --force-recreate
```

也可以直接讓 compose 代為 build：

```bash
docker compose up -d --build
```

## 容器啟動流程

目前容器啟動時會先做初始化，再讓 nginx / php-fpm / cron 起來：

1. 修正 `data/` 與 `thumb/` 權限
2. 執行 `init_runtime.php`
3. 建立 / 修復：
   - `auth_settings.sqlite`
   - `library_index.sqlite`
   - `migrations.sqlite`
4. 跑 `auth` 與 `library` migration
5. 視需要建立預設管理員
6. 初始化完成後，才讓 runtime service 啟動

## 掃描與排程

### 掃描

- `scan.php` 會執行真正的書庫掃描
- 掃描時會先寫到暫存 sqlite
- 完成後再替換正式 `library_index.sqlite`
- 已讀狀態會依路徑保留

### 排程

- `job.php --cron` 由容器內 cron 每分鐘觸發
- `scan_jobs` 存在 `auth_settings.sqlite`
- 支援 job：
  - `rebuild`
  - `rebuild_cover`
  - `send_book`

### 逾時規則

- `pending` job 若超過應執行時間 10 分鐘未執行，會標記 `failed`
- `scan` 與 `rebuild_cover` 使用不同 lock，不會共用同一個程序狀態

## Migration

### CLI

```bash
php site/migrate.php status
php site/migrate.php status auth
php site/migrate.php status library
php site/migrate.php migrate all
php site/migrate.php migrate auth
php site/migrate.php migrate library
php site/migrate.php failures
php site/migrate.php failures auth 20
```

### mismatch recovery

若 migration checksum mismatch：

- 只會處理出錯的那顆 DB
- 舊 DB 會被重新命名成：
  - `{YmdHis}-原檔名.sqlite`
- `migrations.sqlite` 裡只刪除該 target 的 migration 記錄
- 不會刪除 `migrations.sqlite`
- 然後重新建立該 DB 並重跑該 target 的 migration

## Web 功能說明

### 列表頁

- 搜尋欄支援：
  - `+`：AND
  - `-`：排除
  - `||`：OR
  - `()`：優先順序
- 可排序欄位：
  - `已讀`
  - `書名`
  - `作者`
  - `系列`
- 可切換每頁：
  - `20`
  - `50`
  - `100`
  - `500`
- 支援直接輸入頁碼跳頁
- 支援鍵盤左右鍵上一頁 / 下一頁
  - 焦點在輸入框時不觸發

### 書籍彈窗

- 顯示封面、作者、系列、標籤、ISBN、出版社、語系、簡介
- `作者 / 系列 / 標籤` 可回填搜尋
- 提供：
  - `下載`
  - `寄送`
  - `關閉`

### OPDS

- `opds.php`
- 支援 root feed、作者、標籤、系列、新書、已讀、未讀、搜尋、封面、下載
- 未登入時：
  - 若啟用登入制度，會要求 Basic Auth 或 token
- token 路徑格式：
  - `/opds/{token}/...`

## 管理員功能

管理員頁目前分頁籤：

- `使用者列表`
- `SMTP`
- `書庫維護`
- `排程`

### 使用者列表

- 新增使用者
- 更新使用者
- 書庫設定
- 刪除使用者

### SMTP

- 更新 SMTP 主機、埠、加密、帳號、密碼
- 發送測試信件

### 書庫維護

- 更新預設語系
- 手動重建索引
- 重建封面

### 排程

- 查看 job 狀態
- 分頁
- 清除歷史紀錄

## 帳號設定

一般使用者可設定：

- email
- 密碼
- 語系
- OPDS token

## SMTP / 寄信

寄信功能會使用管理員設定頁中的 SMTP 設定。  
只有在以下條件成立時才會啟用寄送：

1. SMTP 設定完整
2. 當前登入使用者有 email

目前寄送使用背景 job，不在 HTTP 請求中直接寄信。

## 版本驗證

管理員頁底部會顯示：

```text
版本驗證：YYYY-mm-dd HH:ii:ss / xxxxxxxxxxxx
```

這是目前最快確認容器是否真的吃到最新版的方法。

## 常見問題

### 1. 為什麼容器啟動後還是舊版？

請確認順序一定是：

```bash
docker build -t sam/bookslib:latest .
docker compose up -d --force-recreate
```

不要把 build 和 recreate 平行執行。

### 2. 為什麼 NAS 上看到 `33:33` 或未知使用者？

base image 的 php-fpm 主程序仍是 `www-data (33:33)`，但目前 `data/` 與 `thumb/` 已改成吃 `PUID / PGID`。  
資料檔 ownership 以 `data/` 內實際檔案為準。

### 3. 掃描為什麼有時候會失敗？

若書庫很大，請優先檢查：

- `SCAN_TMP_SQLITE_PATH`
- `data/` 權限
- `scan.log`
- `cron.log`

目前已改為：
- 掃描使用暫存 DB
- migration runner 可在暫存 DB 上重播 schema

### 4. auth_settings.sqlite 壞掉怎麼辦？

程式目前會先做 integrity check。  
若偵測設定庫損壞：

- 會備份成 `.broken.YmdHis`
- 再重建新的設定庫

## 開發備註

- 本專案目前以 Docker 開發為主
- README 內容以目前 `site/` 內程式為準
- 若新增新設定、入口或 DB 行為，請同步更新：
  - `site/config.env`
  - `site/config.docker.env`
  - `docker-compose.yml`
