# SamBooksLib Developer README

這份文件給開發者使用，記錄目前專案的開發流程與常用入口。

## 開發環境

- 專案根目錄：`/mnt/d/project/books`
- 主要執行方式：`Docker`
- 主站程式：`site/`
- 容器名稱：`sam-books-lib`

## 常用指令

### 建立與重建

```bash
docker build -t sam/bookslib:latest .
docker compose up -d --force-recreate
```

也可以：

```bash
docker compose up -d --build
```

### 查看 log

```bash
docker compose logs --tail 200 sam-books-lib
```

### 進容器

```bash
docker compose exec -T sam-books-lib sh
```

## 版本核對

每次重建 Docker 後，請核對目前實際版本：

```bash
docker compose exec -T sam-books-lib sh -lc 'php -r '\''require "/var/www/html/bootstrap.php"; $c = new Calibre\Controllers\AdminSettingsController("/var/www/html"); $r = new ReflectionClass($c); $m = $r->getMethod("buildVersionSignature"); $m->setAccessible(true); echo $m->invoke($c), PHP_EOL;'\'''
```

管理員頁底部也會顯示同一串版本驗證文字。

## 專案結構

```text
.
├─ Dockerfile
├─ docker-compose.yml
├─ docker-compose.template.yml
├─ docker/
├─ site-enable/
├─ site/
│  ├─ index.php
│  ├─ login.php
│  ├─ magic_login.php
│  ├─ admin_settings.php
│  ├─ settings.php
│  ├─ opds.php
│  ├─ reader.php
│  ├─ scan.php
│  ├─ cover_rebuild.php
│  ├─ job.php
│  ├─ migrate.php
│  ├─ init_runtime.php
│  ├─ config.env
│  ├─ data/
│  ├─ assets/
│  ├─ views/
│  └─ src/
└─ read/
```

## 重要資料庫

目前固定使用 3 顆 SQLite：

- `data/auth_settings.sqlite`
  - 帳號
  - SMTP
  - 使用者偏好
  - 管理設定
  - 登入失敗次數
  - 魔術登入設定與短效 token

- `data/library_index.sqlite`
  - 書目索引
  - 搜尋資料
  - 排程資料

- `data/migrations.sqlite`
  - migration 套用紀錄

## Migration

### 查看狀態

```bash
docker compose exec -T sam-books-lib php /var/www/html/migrate.php status
```

### 查看失敗紀錄

```bash
docker compose exec -T sam-books-lib php /var/www/html/migrate.php failures
```

### 手動執行 migration

```bash
docker compose exec -T sam-books-lib php /var/www/html/migrate.php migrate all
```

## 初始化流程

容器啟動時會先跑：

1. `docker/cont-init.d/10-fix-data-perms.sh`
2. `docker/cont-init.d/20-init-runtime.sh`
3. `site/init_runtime.php`

初始化完成後，才會進入：

- nginx
- php-fpm
- cron

## 主要功能入口

### 前台

- `site/index.php`
- `site/book.php`
- `site/download.php`
- `site/send.php`
- `site/read.php`
- `site/theme.php`

### 帳號 / 管理

- `site/login.php`
- `site/magic_login.php`
- `site/logout.php`
- `site/settings.php`
- `site/admin_settings.php`

### 閱讀器

- `site/reader.php`
- `site/reader_manifest.php`
- `site/reader_page.php`
- `site/reader_asset.php`

### 背景工作

- `site/scan.php`
- `site/cover_rebuild.php`
- `site/job.php`

## 主要模組

### 掃描

- `site/src/ScanService.php`
- `site/src/ScanLauncher.php`
- `site/src/CalibreLibrary.php`
- `site/src/Services/ScanScheduleService.php`

### 書庫索引

- `site/src/LibraryIndex.php`

### 帳號

- `site/src/Services/AuthService.php`
- `site/src/Services/MagicLoginService.php`
- `site/src/Services/LoginCaptchaService.php`
- `site/src/Services/QrPngService.php`
- `site/src/Controllers/AuthLoginController.php`
- `site/src/Controllers/MagicLoginController.php`
- `site/src/Controllers/AuthSettingsController.php`
- `site/src/Controllers/AdminSettingsController.php`

## 登入與魔術登入

### 一般登入

- 入口：`site/login.php`
- Controller：`Calibre\Controllers\AuthLoginController`
- 驗證服務：`Calibre\Services\AuthService`
- 圖形驗證碼：`Calibre\Services\LoginCaptchaService`

登入失敗計數規則：

- 密碼錯誤會計入 `users.failed_login_attempts`。
- 圖形驗證碼錯誤也會計入同一欄位。
- 一般使用者達 `app_settings.login_max_attempts` 後會被停用。
- 管理員達上限時會重設密碼，並寫入 `data/new_pass.txt`。
- 管理員正確登入後，或已登入管理員進入管理員設定頁時，會清除 `data/new_pass.txt`。

### 魔術登入

- 入口：`site/magic_login.php`
- Controller：`Calibre\Controllers\MagicLoginController`
- Service：`Calibre\Services\MagicLoginService`
- QR PNG：`Calibre\Services\QrPngService`
- View：`site/views/auth/magic_login.php`

流程：

1. 登入頁 AJAX 呼叫 `POST magic_login.php?action=create`。
2. 後端建立或重用 10 分鐘有效的 token。
3. 前端顯示後端 PNG QR code 與登入連結。
4. 原設備每秒呼叫 `GET magic_login.php?action=status&token=...`。
5. 另一台設備開啟 `magic_login.php?token=...` 並登入授權。
6. 原設備輪詢到 `authenticated` 後，透過 `AuthService::loginByUserId()` 建立 web session。
7. token 標記為 `consumed`，不可重複使用。

安全約束：

- 魔術登入開關存在 `app_settings.magic_login_enabled`，預設 `1`。
- 停用時 `MagicLoginController` 直接回 `404` 空內容，登入頁也不顯示按鈕。
- token 明文只回前端一次；DB 只存 `token_hash`。
- token 綁定原設備 session 的 `browser_nonce`。
- 授權 POST 必須通過 CSRF token 與 `Origin` / `Referer` 同源檢查。
- 建立 token 有簡單 IP rate limit：10 分鐘最多 20 次。
- 建立 token 時會標記過期 pending token，並刪除 expired / consumed 超過一天的記錄。
- magic login URL 優先使用 `SITE_BASE_URL`，避免 Host header poisoning。
- 魔術登入與 OPDS 驗證分離；OPDS 仍在 `site/opds.php` 中處理 Basic Auth / OPDS token。

相關資料表：

- `users.failed_login_attempts`
- `app_settings.login_max_attempts`
- `app_settings.magic_login_enabled`
- `magic_login_tokens`

`magic_login_tokens` 主要欄位：

- `token_hash`
- `browser_nonce`
- `user_id`
- `status`
- `expires_at`
- `created_ip`
- `authenticated_at`
- `authenticated_ip`
- `consumed_at`

### 閱讀器

- `site/src/Controllers/ReaderController.php`
- `site/src/Controllers/ReaderManifestController.php`
- `site/src/Controllers/ReaderPageController.php`
- `site/src/Controllers/ReaderAssetController.php`
- `site/src/Services/ReaderAccessService.php`
- `site/src/Services/ReaderEpubService.php`
- `site/src/Services/ReaderPdfService.php`
- `site/src/Services/ReaderComicService.php`

## 前端檔案

- 共用樣式：`site/assets/css/app.css`
- 閱讀器樣式：`site/assets/css/reader.css`
- 共用前端：`site/assets/js/catalog.js`
- 閱讀器前端：`site/assets/js/reader.js`

## 搜尋規則

目前搜尋支援：

- `+`
- `-`
- `||`
- `()`

目前 `LIKE` 只用於：

- 書名
- 作者

格式搜尋另外支援：

- `pdf`
- `epub`
- `cbz`
- `pdb` 會視為 `pdf`

## 閱讀器狀態

目前已整合進站內閱讀器的格式：

- `EPUB`
- `PDF`
- `CBZ`

說明：

- `read/` 目錄目前只保留在 repo 中參考
- 不包進 Docker
- 正式閱讀器以 `site/reader.php` 這套為主

## 開發注意事項

- 修改 PHP / CSS / JS 後，前端畫面若看起來沒變，先 `Ctrl + F5`
- 每次改 Docker 相關或 PHP 核心邏輯，重建後都要核對版本號
- 如果掃描 / login / migration 有異常，先看：
  - `docker compose logs --tail 200 sam-books-lib`
- 若 `auth_settings.sqlite` 壞掉，系統會做健檢與備份重建
- 若 migration checksum 不符，系統會重新命名出錯的 target DB，清除該 target 的 migration 記錄後重建
- 已套用的 migration 不要改內容；需要補欄位或索引時新增下一個 migration，避免 checksum mismatch
- 若新增 web 登入流程，確認不要改到 `site/opds.php` 的 OPDS Basic/Auth token 邏輯

## 測試建議

每次較大改動後，至少確認：

1. `login.php` 可正常進入
2. 圖形驗證碼錯誤與密碼錯誤都會累計登入失敗次數
3. 魔術登入啟用時按鈕可見，停用時按鈕消失且 `magic_login.php` 回 `404`
4. `index.php` 可正常搜尋與分頁
5. `admin_settings.php` 可正常開啟
6. `reader.php` 可正常讀：
   - EPUB
   - PDF
   - CBZ
7. `scan.php` 可正常跑完
8. `migrate.php status` 無失敗
