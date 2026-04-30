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

以下是目前最常用的設定：

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

### 補充

- `AUTH_USERNAME` / `AUTH_PASSWORD`
  - 只在預設管理員 seed 時使用
  - 目前不會在每次請求都覆寫 DB 中的管理員資料
- `AUTH_EMAIL`
  - 只有明確設定時才會覆寫預設管理員 email
- `SMTP_*`
  - 若 DB 尚未設定 SMTP，啟動時會把 env/compose 的值初始化進 DB
  - 之後管理員在 UI 修改後，不會被每次啟動覆蓋

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
  - `README.md`
  - `site/config.env`
  - `site/config.docker.env`
  - `docker-compose.yml`
  - `docker-composer-online.yml`
