package main

import (
	"context"
	"crypto/tls"
	"database/sql"
	"encoding/base64"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"mime"
	"mime/multipart"
	"net"
	"net/mail"
	"net/smtp"
	"net/textproto"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	_ "github.com/mattn/go-sqlite3"
)

const pendingExpireSeconds = 600

type config map[string]string

type job struct {
	ID      int64
	Action  string
	RunAt   string
	Source  string
	Payload map[string]any
}

type worker struct {
	appRoot  string
	config   config
	authDB   string
	indexDB  string
	workerID string
}

func main() {
	var appRoot string
	var daemon bool
	var once bool
	var poll string
	flag.StringVar(&appRoot, "app-root", "/var/www/html", "application root")
	flag.BoolVar(&daemon, "daemon", false, "run continuously")
	flag.BoolVar(&once, "once", false, "run one tick")
	flag.StringVar(&poll, "poll-interval", "5s", "daemon poll interval")
	flag.Parse()

	if !daemon && !once {
		once = true
	}

	w, err := newWorker(appRoot)
	if err != nil {
		fatal(err)
	}

	if once {
		worked, err := w.tick(context.Background())
		if err != nil {
			fatal(err)
		}
		if !worked {
			fmt.Println("books-worker: no due job")
		}
		return
	}

	interval, err := time.ParseDuration(poll)
	if err != nil || interval <= 0 {
		interval = 5 * time.Second
	}

	fmt.Printf("books-worker: daemon started app_root=%s worker_id=%s\n", w.appRoot, w.workerID)
	for {
		if _, err := w.tick(context.Background()); err != nil {
			fmt.Fprintf(os.Stderr, "books-worker tick failed: %v\n", err)
		}
		time.Sleep(interval)
	}
}

func fatal(err error) {
	fmt.Fprintln(os.Stderr, "books-worker:", err)
	os.Exit(1)
}

func newWorker(appRoot string) (*worker, error) {
	root, err := filepath.Abs(appRoot)
	if err != nil {
		return nil, err
	}
	cfg, err := loadConfig(root)
	if err != nil {
		return nil, err
	}
	w := &worker{
		appRoot:  root,
		config:   cfg,
		workerID: fmt.Sprintf("%s:%d", hostname(), os.Getpid()),
	}
	w.authDB = resolvePath(root, firstSetting(cfg, []string{"BOOKS_AUTH_SETTINGS_DB_PATH", "AUTH_SETTINGS_DB_PATH"}, "AUTH_SETTINGS_DB_PATH", "data/auth_settings.sqlite"))
	w.indexDB = resolvePath(root, firstSetting(cfg, []string{"BOOKS_SQLITE_INDEX_PATH", "SQLITE_INDEX_PATH"}, "SQLITE_INDEX_PATH", "data/library_index.sqlite"))
	return w, nil
}

func (w *worker) tick(ctx context.Context) (bool, error) {
	db, err := openSQLite(w.authDB)
	if err != nil {
		return false, err
	}
	defer db.Close()

	if err := ensureJobColumns(db); err != nil {
		return false, err
	}
	if err := w.reconcileRunningJobs(db); err != nil {
		return false, err
	}
	if err := failExpiredPending(db); err != nil {
		return false, err
	}
	if err := w.ensureNextAutoSchedule(db); err != nil {
		return false, err
	}

	j, err := w.reserveDueJob(db)
	if err != nil {
		return false, err
	}
	if j == nil {
		return false, nil
	}

	fmt.Printf("books-worker: reserved job id=%d action=%s source=%s\n", j.ID, j.Action, j.Source)
	result, runErr := w.runJob(ctx, db, *j)
	if runErr != nil {
		_ = markFailed(db, j.ID, runErr)
		return true, runErr
	}
	if err := markDone(db, j.ID, result); err != nil {
		return true, err
	}
	fmt.Printf("books-worker: done job id=%d action=%s\n", j.ID, j.Action)
	return true, nil
}

func (w *worker) reconcileRunningJobs(db *sql.DB) error {
	rows, err := db.Query(`SELECT id, COALESCE(worker_id, ''), COALESCE(heartbeat_at, '') FROM scan_jobs WHERE status = 'running'`)
	if err != nil {
		return err
	}
	defer rows.Close()
	type runningJob struct {
		id        int64
		workerID  string
		heartbeat string
	}
	var jobs []runningJob
	for rows.Next() {
		var j runningJob
		if err := rows.Scan(&j.id, &j.workerID, &j.heartbeat); err != nil {
			return err
		}
		jobs = append(jobs, j)
	}
	if err := rows.Err(); err != nil {
		return err
	}
	for _, j := range jobs {
		if pid, ok := pidFromWorkerID(j.workerID); ok && processAlive(pid) {
			continue
		}
		_, err := db.Exec(`UPDATE scan_jobs
SET status = 'failed', finished_at = ?, heartbeat_at = ?, error_message = ?
WHERE id = ? AND status = 'running'`,
			time.Now().Format(time.RFC3339),
			time.Now().Format(time.RFC3339),
			"running job worker process is no longer alive",
			j.id,
		)
		if err != nil {
			return err
		}
		fmt.Printf("books-worker: marked stale running job failed id=%d worker_id=%s\n", j.id, j.workerID)
	}
	return nil
}

func pidFromWorkerID(workerID string) (int, bool) {
	parts := strings.Split(workerID, ":")
	if len(parts) < 2 {
		return 0, false
	}
	pid, err := strconv.Atoi(parts[len(parts)-1])
	return pid, err == nil && pid > 0
}

func processAlive(pid int) bool {
	if pid <= 0 {
		return false
	}
	_, err := os.Stat(filepath.Join("/proc", strconv.Itoa(pid)))
	return err == nil
}

func ensureJobColumns(db *sql.DB) error {
	columns := map[string]string{
		"worker_id":     "TEXT",
		"heartbeat_at":  "TEXT",
		"progress_json": "TEXT",
		"result_json":   "TEXT",
		"error_message": "TEXT",
		"attempts":      "INTEGER NOT NULL DEFAULT 0",
	}
	for name, def := range columns {
		if _, err := db.Exec("ALTER TABLE scan_jobs ADD COLUMN " + name + " " + def); err != nil {
			if !strings.Contains(strings.ToLower(err.Error()), "duplicate column name") {
				return err
			}
		}
	}
	return nil
}

func failExpiredPending(db *sql.DB) error {
	_, err := db.Exec(`UPDATE scan_jobs
SET status = 'failed', finished_at = ?, error_message = 'pending job expired before worker reservation'
WHERE status = 'pending'
  AND CAST(COALESCE(strftime('%s', run_at), '0') AS INTEGER) < ?`,
		time.Now().Format(time.RFC3339),
		time.Now().Add(-pendingExpireSeconds*time.Second).Unix(),
	)
	return err
}

func (w *worker) ensureNextAutoSchedule(db *sql.DB) error {
	interval := intSetting(w.config, []string{"BOOKS_SCAN_INTERVAL_MINUTES", "SCAN_INTERVAL_MINUTES"}, "SCAN_INTERVAL_MINUTES", 5)
	if interval <= 0 {
		return nil
	}
	var existing int
	err := db.QueryRow(`SELECT 1 FROM scan_jobs WHERE source = 'auto' AND status = 'pending' LIMIT 1`).Scan(&existing)
	if err == nil {
		return nil
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return err
	}
	runAt := time.Now().Add(time.Duration(interval) * time.Minute).Format(time.RFC3339)
	_, err = db.Exec(`INSERT INTO scan_jobs(action, run_at, source, status, created_at, payload)
VALUES('rebuild', ?, 'auto', 'pending', ?, NULL)`, runAt, time.Now().Format(time.RFC3339))
	return err
}

func (w *worker) reserveDueJob(db *sql.DB) (*job, error) {
	tx, err := db.Begin()
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()

	row := tx.QueryRow(`SELECT id, action, run_at, source, COALESCE(payload, '')
FROM scan_jobs
WHERE status = 'pending'
  AND CAST(COALESCE(strftime('%s', run_at), '0') AS INTEGER) <= ?
  AND CAST(COALESCE(strftime('%s', run_at), '0') AS INTEGER) >= ?
ORDER BY CASE WHEN source = 'manual' THEN 0 ELSE 1 END ASC, run_at ASC, id ASC
LIMIT 1`, time.Now().Unix(), time.Now().Add(-pendingExpireSeconds*time.Second).Unix())

	var j job
	var payloadRaw string
	if err := row.Scan(&j.ID, &j.Action, &j.RunAt, &j.Source, &payloadRaw); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, nil
		}
		return nil, err
	}
	j.Payload = map[string]any{}
	if strings.TrimSpace(payloadRaw) != "" {
		_ = json.Unmarshal([]byte(payloadRaw), &j.Payload)
	}

	res, err := tx.Exec(`UPDATE scan_jobs
SET status = 'running',
    started_at = ?,
    heartbeat_at = ?,
    worker_id = ?,
    attempts = COALESCE(attempts, 0) + 1,
    error_message = NULL,
    result_json = NULL
WHERE id = ? AND status = 'pending'`,
		time.Now().Format(time.RFC3339),
		time.Now().Format(time.RFC3339),
		w.workerID,
		j.ID,
	)
	if err != nil {
		return nil, err
	}
	affected, err := res.RowsAffected()
	if err != nil {
		return nil, err
	}
	if affected != 1 {
		return nil, nil
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return &j, nil
}

func (w *worker) runJob(ctx context.Context, db *sql.DB, j job) (map[string]any, error) {
	started := time.Now()
	switch strings.ToLower(strings.TrimSpace(j.Action)) {
	case "rebuild":
		return w.runRebuild(ctx, db, j, started)
	case "rebuild_cover":
		return w.runCoverRebuild(ctx, db, j, started)
	case "send_book":
		return w.runSendBook(j, started)
	default:
		return nil, fmt.Errorf("unsupported job action: %s", j.Action)
	}
}

func (w *worker) runSendBook(j job, started time.Time) (map[string]any, error) {
	bookID := int64FromPayload(j.Payload, "book_id")
	recipient := strings.TrimSpace(stringFromPayload(j.Payload, "recipient_email"))
	if bookID < 1 {
		return nil, errors.New("send_book payload missing valid book_id")
	}
	if _, err := mail.ParseAddress(recipient); err != nil {
		return nil, fmt.Errorf("send_book payload has invalid recipient_email: %w", err)
	}

	book, err := w.loadBook(bookID)
	if err != nil {
		return nil, err
	}
	attachment, name, mimeType, err := resolveBookAttachment(book)
	if err != nil {
		return nil, err
	}
	settings, err := w.loadSMTPSettings()
	if err != nil {
		return nil, err
	}
	if settings.Host == "" {
		return nil, errors.New("smtp_host is empty")
	}
	from := settings.Username
	if _, err := mail.ParseAddress(from); err != nil {
		from = "noreply@localhost"
	}

	subject := fmt.Sprintf("%s - %s", book.Title, book.Author)
	body := subject + filepath.Ext(attachment)
	if err := sendMail(settings, from, recipient, subject, body, attachment, name, mimeType); err != nil {
		return nil, err
	}

	return map[string]any{
		"action":           j.Action,
		"book_id":          bookID,
		"recipient_email":  recipient,
		"attachment_name":  name,
		"duration_seconds": int(time.Since(started).Seconds()),
	}, nil
}

type bookRow struct {
	Title       string
	Author      string
	Path        string
	FormatsJSON string
}

func (w *worker) loadBook(bookID int64) (bookRow, error) {
	db, err := openSQLite(w.indexDB)
	if err != nil {
		return bookRow{}, err
	}
	defer db.Close()
	var b bookRow
	err = db.QueryRow(`SELECT title, author, path, COALESCE(formats_json, '') FROM books WHERE id = ? LIMIT 1`, bookID).
		Scan(&b.Title, &b.Author, &b.Path, &b.FormatsJSON)
	if errors.Is(err, sql.ErrNoRows) {
		return bookRow{}, fmt.Errorf("book not found for send_book: id=%d", bookID)
	}
	return b, err
}

func resolveBookAttachment(b bookRow) (string, string, string, error) {
	var formats map[string]string
	if strings.TrimSpace(b.FormatsJSON) != "" {
		_ = json.Unmarshal([]byte(b.FormatsJSON), &formats)
	}
	preferred := []string{"epub", "pdf", "mobi", "azw3", "cbz", "cbr", "txt"}
	for _, ext := range preferred {
		for k, p := range formats {
			if strings.EqualFold(k, ext) && p != "" && fileExists(p) {
				return p, buildAttachmentName(b, p), mime.TypeByExtension(filepath.Ext(p)), nil
			}
		}
	}
	if b.Path != "" && fileExists(b.Path) {
		return b.Path, buildAttachmentName(b, b.Path), mime.TypeByExtension(filepath.Ext(b.Path)), nil
	}
	return "", "", "", errors.New("book attachment file not found")
}

func sanitizeAttachmentPart(value string, fallback string) string {
	normalized := strings.TrimSpace(value)
	if normalized == "" {
		return fallback
	}

	replacer := strings.NewReplacer("\\", " ", "/", " ", ":", " ", "*", " ", "?", " ", "\"", " ", "<", " ", ">", " ", "|", " ")
	normalized = replacer.Replace(normalized)
	normalized = strings.Join(strings.Fields(normalized), " ")
	normalized = strings.Trim(normalized, " .\t\r\n")
	if normalized == "" {
		return fallback
	}
	return normalized
}

func buildAttachmentName(b bookRow, path string) string {
	title := sanitizeAttachmentPart(b.Title, "Unknown Title")
	author := sanitizeAttachmentPart(b.Author, "Unknown Author")
	extension := filepath.Ext(path)

	name := fmt.Sprintf("%s - %s", title, author)
	if extension != "" {
		name += extension
	}
	return name
}

type smtpSettings struct {
	Host       string
	Port       int
	Encryption string
	Username   string
	Password   string
}

func (w *worker) loadSMTPSettings() (smtpSettings, error) {
	s := smtpSettings{
		Host:       firstSetting(w.config, []string{"BOOKS_SMTP_HOST", "SMTP_HOST"}, "SMTP_HOST", ""),
		Encryption: strings.ToLower(firstSetting(w.config, []string{"BOOKS_SMTP_ENCRYPTION", "SMTP_ENCRYPTION"}, "SMTP_ENCRYPTION", "none")),
		Username:   firstSetting(w.config, []string{"BOOKS_SMTP_USERNAME", "SMTP_USERNAME"}, "SMTP_USERNAME", ""),
		Password:   firstSetting(w.config, []string{"BOOKS_SMTP_PASSWORD", "SMTP_PASSWORD"}, "SMTP_PASSWORD", ""),
	}
	s.Port = intSetting(w.config, []string{"BOOKS_SMTP_PORT", "SMTP_PORT"}, "SMTP_PORT", 0)

	db, err := openSQLite(w.authDB)
	if err == nil {
		defer db.Close()
		rows, err := db.Query(`SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('smtp_host','smtp_port','smtp_encryption','smtp_username','smtp_password')`)
		if err == nil {
			defer rows.Close()
			for rows.Next() {
				var k, v string
				if rows.Scan(&k, &v) != nil || strings.TrimSpace(v) == "" {
					continue
				}
				switch k {
				case "smtp_host":
					s.Host = strings.TrimSpace(v)
				case "smtp_port":
					if p, err := strconv.Atoi(strings.TrimSpace(v)); err == nil {
						s.Port = p
					}
				case "smtp_encryption":
					s.Encryption = strings.ToLower(strings.TrimSpace(v))
				case "smtp_username":
					s.Username = strings.TrimSpace(v)
				case "smtp_password":
					s.Password = v
				}
			}
		}
	}

	if s.Port == 0 {
		switch s.Encryption {
		case "ssl":
			s.Port = 465
		case "tls":
			s.Port = 587
		default:
			s.Port = 25
		}
	}
	return s, nil
}

func sendMail(s smtpSettings, from string, to string, subject string, body string, attachment string, attachmentName string, mimeType string) error {
	if mimeType == "" {
		mimeType = "application/octet-stream"
	}
	var msg strings.Builder
	writer := multipart.NewWriter(&msg)
	fmt.Fprintf(&msg, "From: %s\r\n", from)
	fmt.Fprintf(&msg, "To: %s\r\n", to)
	fmt.Fprintf(&msg, "Subject: %s\r\n", mime.QEncoding.Encode("utf-8", subject))
	fmt.Fprintf(&msg, "MIME-Version: 1.0\r\n")
	fmt.Fprintf(&msg, "Content-Type: multipart/mixed; boundary=%q\r\n\r\n", writer.Boundary())

	textPart, err := writer.CreatePart(textproto.MIMEHeader{
		"Content-Type":              {"text/plain; charset=utf-8"},
		"Content-Transfer-Encoding": {"8bit"},
	})
	if err != nil {
		return err
	}
	_, _ = textPart.Write([]byte(body))

	filePart, err := writer.CreatePart(textproto.MIMEHeader{
		"Content-Type":              {mimeType},
		"Content-Transfer-Encoding": {"base64"},
		"Content-Disposition":       {fmt.Sprintf(`attachment; filename="%s"`, strings.ReplaceAll(attachmentName, `"`, ""))},
	})
	if err != nil {
		return err
	}
	data, err := os.ReadFile(attachment)
	if err != nil {
		return err
	}
	encoded := make([]byte, base64.StdEncoding.EncodedLen(len(data)))
	base64.StdEncoding.Encode(encoded, data)
	for len(encoded) > 76 {
		_, _ = filePart.Write(encoded[:76])
		_, _ = filePart.Write([]byte("\r\n"))
		encoded = encoded[76:]
	}
	_, _ = filePart.Write(encoded)
	_ = writer.Close()

	addr := net.JoinHostPort(s.Host, strconv.Itoa(s.Port))
	auth := smtp.Auth(nil)
	if s.Username != "" {
		auth = smtp.PlainAuth("", s.Username, s.Password, s.Host)
	}
	if s.Encryption == "ssl" {
		conn, err := tls.Dial("tcp", addr, &tls.Config{ServerName: s.Host})
		if err != nil {
			return err
		}
		defer conn.Close()
		client, err := smtp.NewClient(conn, s.Host)
		if err != nil {
			return err
		}
		defer client.Quit()
		if s.Username != "" {
			if err := client.Auth(auth); err != nil {
				return err
			}
		}
		return smtpSend(client, from, to, []byte(msg.String()))
	}
	client, err := smtp.Dial(addr)
	if err != nil {
		return err
	}
	defer client.Quit()
	if s.Encryption == "tls" {
		if err := client.StartTLS(&tls.Config{ServerName: s.Host}); err != nil {
			return err
		}
	}
	if s.Username != "" {
		if err := client.Auth(auth); err != nil {
			return err
		}
	}
	return smtpSend(client, from, to, []byte(msg.String()))
}

func smtpSend(client *smtp.Client, from string, to string, msg []byte) error {
	if err := client.Mail(from); err != nil {
		return err
	}
	if err := client.Rcpt(to); err != nil {
		return err
	}
	w, err := client.Data()
	if err != nil {
		return err
	}
	if _, err := w.Write(msg); err != nil {
		return err
	}
	return w.Close()
}

func markDone(db *sql.DB, jobID int64, result map[string]any) error {
	encoded, _ := json.Marshal(result)
	_, err := db.Exec(`UPDATE scan_jobs
SET status = 'done', finished_at = ?, heartbeat_at = ?, result_json = ?, progress_json = NULL
WHERE id = ?`, time.Now().Format(time.RFC3339), time.Now().Format(time.RFC3339), string(encoded), jobID)
	return err
}

func markFailed(db *sql.DB, jobID int64, runErr error) error {
	_, err := db.Exec(`UPDATE scan_jobs
SET status = 'failed', finished_at = ?, heartbeat_at = ?, error_message = ?
WHERE id = ?`, time.Now().Format(time.RFC3339), time.Now().Format(time.RFC3339), runErr.Error(), jobID)
	return err
}

func updateProgress(db *sql.DB, jobID int64, progress map[string]any) error {
	encoded, _ := json.Marshal(progress)
	_, err := db.Exec(`UPDATE scan_jobs SET heartbeat_at = ?, progress_json = ? WHERE id = ?`,
		time.Now().Format(time.RFC3339), string(encoded), jobID)
	return err
}

func loadConfig(appRoot string) (config, error) {
	cfg := config{}
	raw, err := os.ReadFile(filepath.Join(appRoot, "config.env"))
	if err != nil {
		if os.IsNotExist(err) {
			return cfg, nil
		}
		return nil, err
	}
	for _, line := range strings.Split(string(raw), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, ";") || strings.HasPrefix(line, "#") {
			continue
		}
		parts := strings.SplitN(line, "=", 2)
		if len(parts) != 2 {
			continue
		}
		cfg[strings.TrimSpace(parts[0])] = strings.Trim(strings.TrimSpace(parts[1]), `"'`)
	}
	return cfg, nil
}

func openSQLite(path string) (*sql.DB, error) {
	escaped := strings.NewReplacer("?", "%3F", "#", "%23").Replace(path)
	return sql.Open("sqlite3", "file:"+escaped+"?_busy_timeout=5000&_txlock=immediate")
}

func openSQLiteReadOnly(path string) (*sql.DB, error) {
	escaped := strings.NewReplacer("?", "%3F", "#", "%23").Replace(path)
	return sql.Open("sqlite3", "file:"+escaped+"?mode=ro&immutable=1&_busy_timeout=5000")
}

func firstSetting(cfg config, envKeys []string, configKey string, fallback string) string {
	for _, key := range envKeys {
		if value := strings.TrimSpace(os.Getenv(key)); value != "" {
			return value
		}
	}
	if value := strings.TrimSpace(cfg[configKey]); value != "" {
		return value
	}
	return fallback
}

func intSetting(cfg config, envKeys []string, configKey string, fallback int) int {
	raw := firstSetting(cfg, envKeys, configKey, "")
	if raw == "" {
		return fallback
	}
	value, err := strconv.Atoi(raw)
	if err != nil {
		return fallback
	}
	return value
}

func resolvePath(appRoot string, raw string) string {
	if filepath.IsAbs(raw) || isWindowsAbs(raw) {
		return raw
	}
	return filepath.Join(appRoot, filepath.FromSlash(raw))
}

func isWindowsAbs(path string) bool {
	return len(path) >= 3 && path[1] == ':' && (path[2] == '\\' || path[2] == '/')
}

func int64FromPayload(payload map[string]any, key string) int64 {
	switch v := payload[key].(type) {
	case float64:
		return int64(v)
	case int:
		return int64(v)
	case int64:
		return v
	case string:
		n, _ := strconv.ParseInt(strings.TrimSpace(v), 10, 64)
		return n
	default:
		return 0
	}
}

func stringFromPayload(payload map[string]any, key string) string {
	switch v := payload[key].(type) {
	case string:
		return v
	default:
		return ""
	}
}

func hostname() string {
	name, err := os.Hostname()
	if err != nil || strings.TrimSpace(name) == "" {
		return "books-worker"
	}
	return name
}

func fileExists(path string) bool {
	info, err := os.Stat(path)
	return err == nil && !info.IsDir()
}

func scanLogPath(appRoot string) string {
	return filepath.Join(appRoot, "data", "scan.log")
}
