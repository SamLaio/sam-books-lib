package main

import (
	"archive/zip"
	"context"
	"crypto/sha1"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"encoding/xml"
	"errors"
	"fmt"
	"html"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

type scanBook struct {
	Title       string
	Author      string
	Path        string
	Formats     map[string]string
	CoverPath   string
	SourceMtime sql.NullInt64
	Metadata    map[string]any
}

type existingSnapshot struct {
	IsRead    bool
	CoverPath string
}

func (w *worker) runRebuild(ctx context.Context, db *sql.DB, j job, started time.Time) (map[string]any, error) {
	libraryPath := w.libraryPath()
	sqlitePath := w.indexDB
	tmpPath := w.scanTmpPath(sqlitePath)
	thumbDir := w.thumbDir()
	logPath := scanLogPath(w.appRoot)

	if err := os.MkdirAll(filepath.Dir(sqlitePath), 0o777); err != nil {
		return nil, err
	}
	if err := os.MkdirAll(filepath.Dir(tmpPath), 0o777); err != nil {
		return nil, err
	}
	if err := os.MkdirAll(thumbDir, 0o777); err != nil {
		return nil, err
	}
	w.appendLog(logPath, fmt.Sprintf("[%s] Go scan started", time.Now().Format(time.RFC3339)))

	snapshots := loadExistingSnapshots(sqlitePath)
	cleanupSQLiteArtifacts(tmpPath)

	index, err := openSQLite(tmpPath)
	if err != nil {
		return nil, err
	}
	defer index.Close()
	if err := ensureLibrarySchema(index); err != nil {
		return nil, fmt.Errorf("ensure temp library schema: %w", err)
	}

	stats, saved, err := w.streamRebuild(ctx, index, db, j.ID, libraryPath, thumbDir, snapshots)
	if err != nil {
		return nil, fmt.Errorf("stream rebuild: %w", err)
	}
	if err := index.Close(); err != nil {
		return nil, err
	}
	if err := promoteSQLite(sqlitePath, tmpPath); err != nil {
		return nil, fmt.Errorf("promote temp index: %w", err)
	}
	w.clearOPDSCache()
	w.appendLog(logPath, fmt.Sprintf("[%s] Go scan completed saved=%d", time.Now().Format(time.RFC3339), saved))

	return map[string]any{
		"action":           j.Action,
		"engine":           "go",
		"library_path":     libraryPath,
		"sqlite_path":      sqlitePath,
		"thumb_dir":        thumbDir,
		"scanned_books":    stats.Scanned,
		"saved_books":      saved,
		"backfilled_covers": stats.BackfilledCovers,
		"duration_seconds": int(time.Since(started).Seconds()),
	}, nil
}

func (w *worker) runCoverRebuild(ctx context.Context, db *sql.DB, j job, started time.Time) (map[string]any, error) {
	libraryPath := w.libraryPath()
	thumbDir := w.thumbDir()
	logPath := filepath.Join(w.appRoot, "data", "cover_rebuild.log")
	w.appendLog(logPath, fmt.Sprintf("[%s] Go cover rebuild started", time.Now().Format(time.RFC3339)))

	index, err := openSQLite(w.indexDB)
	if err != nil {
		return nil, err
	}
	defer index.Close()
	if err := ensureLibrarySchema(index); err != nil {
		return nil, err
	}
	if err := os.MkdirAll(thumbDir, 0o777); err != nil {
		return nil, err
	}

	rows, err := index.Query(`SELECT id, path, COALESCE(cover_path, ''), COALESCE(formats_json, ''), COALESCE(metadata_json, '') FROM books ORDER BY id ASC`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	update, err := index.Prepare(`UPDATE books SET cover_path = ? WHERE id = ?`)
	if err != nil {
		return nil, err
	}
	defer update.Close()

	processed := 0
	updated := 0
	skippedExisting := 0
	skippedCalibre := 0
	failed := 0
	for rows.Next() {
		select {
		case <-ctx.Done():
			return nil, ctx.Err()
		default:
		}
		var id int64
		var path, coverPath, formatsRaw, metadataRaw string
		if err := rows.Scan(&id, &path, &coverPath, &formatsRaw, &metadataRaw); err != nil {
			return nil, err
		}
		processed++
		if coverPath != "" && fileExists(coverPath) {
			skippedExisting++
			continue
		}
		metadata := map[string]any{}
		_ = json.Unmarshal([]byte(metadataRaw), &metadata)
		if strings.EqualFold(fmt.Sprint(metadata["source_type"]), "db") {
			skippedCalibre++
			continue
		}
		formats := map[string]string{}
		_ = json.Unmarshal([]byte(formatsRaw), &formats)
		if len(formats) == 0 && path != "" {
			formats[strings.ToLower(filepath.Ext(path))] = path
		}
		cover := findExistingCover(filepath.Dir(path))
		if cover == "" {
			cover = extractCoverFromFormats(formats, thumbDir)
		}
		if cover == "" {
			failed++
			continue
		}
		if _, err := update.Exec(cover, id); err != nil {
			return nil, err
		}
		updated++
		if processed%100 == 0 {
			_ = updateProgress(db, j.ID, map[string]any{"phase": "cover_rebuild", "processed_books": processed, "updated_covers": updated})
		}
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	w.appendLog(logPath, fmt.Sprintf("[%s] Go cover rebuild completed updated=%d", time.Now().Format(time.RFC3339), updated))

	_ = libraryPath
	return map[string]any{
		"action":                  j.Action,
		"engine":                  "go",
		"processed_books":         processed,
		"updated_covers":          updated,
		"skipped_calibre_books":   skippedCalibre,
		"skipped_existing_covers": skippedExisting,
		"failed_books":            failed,
		"duration_seconds":        int(time.Since(started).Seconds()),
	}, nil
}

type scanStats struct {
	Scanned          int
	BackfilledCovers int
}

func (w *worker) streamRebuild(ctx context.Context, index *sql.DB, scheduleDB *sql.DB, jobID int64, libraryPath string, thumbDir string, snapshots map[string]existingSnapshot) (scanStats, int, error) {
	if info, err := os.Stat(libraryPath); err != nil || !info.IsDir() {
		return scanStats{}, 0, fmt.Errorf("calibre library path does not exist: %s", libraryPath)
	}
	stats := scanStats{}
	dbKnown := map[string]bool{}
	writer, err := newBookWriter(index, snapshots)
	if err != nil {
		return stats, 0, err
	}
	defer writer.rollback()

	onBook := func(book scanBook) error {
		stats.Scanned++
		if book.CoverPath != "" {
			stats.BackfilledCovers++
		}
		if err := writer.add(book); err != nil {
			return err
		}
		if stats.Scanned%500 == 0 {
			_ = updateProgress(scheduleDB, jobID, map[string]any{
				"phase":         "streaming",
				"scanned_books": stats.Scanned,
				"saved_books":   writer.count,
			})
		}
		return nil
	}

	metadataDB := filepath.Join(libraryPath, "metadata.db")
	if fileExists(metadataDB) {
		known, err := scanCalibreMetadataDB(metadataDB, libraryPath, thumbDir, snapshots, onBook)
		if err != nil {
			return stats, 0, err
		}
		for path := range known {
			dbKnown[normalizePath(path)] = true
		}
	}

	if err := scanFilesystemBooks(ctx, libraryPath, thumbDir, dbKnown, snapshots, onBook); err != nil {
		return stats, 0, err
	}
	if err := writer.finish(); err != nil {
		return stats, 0, err
	}
	return stats, writer.count, nil
}

func scanCalibreMetadataDB(dbPath string, libraryPath string, thumbDir string, snapshots map[string]existingSnapshot, onBook func(scanBook) error) (map[string]bool, error) {
	db, err := openSQLiteReadOnly(dbPath)
	if err != nil {
		return nil, err
	}
	defer db.Close()
	rows, err := db.Query(`
SELECT
  b.id, b.title, b.path, b.timestamp, b.pubdate, b.series_index, b.author_sort, b.uuid, b.last_modified, b.has_cover,
  GROUP_CONCAT(DISTINCT a.name) AS authors,
  GROUP_CONCAT(DISTINCT t.name) AS tags,
  s.name AS series,
  MAX(c.text) AS description,
  GROUP_CONCAT(DISTINCT p.name) AS publishers,
  GROUP_CONCAT(DISTINCT l.lang_code) AS languages,
	(SELECT i.val FROM identifiers i WHERE i.book = b.id AND LOWER(i.type) = 'isbn' LIMIT 1) AS isbn
FROM books b
LEFT JOIN books_authors_link bal ON b.id = bal.book
LEFT JOIN authors a ON bal.author = a.id
LEFT JOIN books_tags_link btl ON b.id = btl.book
LEFT JOIN tags t ON btl.tag = t.id
LEFT JOIN books_series_link bsl ON b.id = bsl.book
LEFT JOIN series s ON bsl.series = s.id
LEFT JOIN books_publishers_link bpl ON b.id = bpl.book
LEFT JOIN publishers p ON bpl.publisher = p.id
LEFT JOIN books_languages_link bll ON b.id = bll.book
LEFT JOIN languages l ON bll.lang_code = l.id
LEFT JOIN comments c ON b.id = c.book
GROUP BY b.id
ORDER BY b.title`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	known := map[string]bool{}
	for rows.Next() {
		var id int64
		var hasCover int
		var title, relPath, timestamp, pubdate, authorSort, uuid, lastModified sql.NullString
		var seriesIndex sql.NullFloat64
		var authors, tags, series, description, publishers, languages, isbn sql.NullString
		if err := rows.Scan(&id, &title, &relPath, &timestamp, &pubdate, &seriesIndex, &authorSort, &uuid, &lastModified, &hasCover, &authors, &tags, &series, &description, &publishers, &languages, &isbn); err != nil {
			return nil, err
		}
		if strings.Contains(normalizePath(relPath.String), "/.caltrash/") {
			continue
		}
		bookDir := filepath.Join(libraryPath, filepath.FromSlash(relPath.String))
		formats := findFormats(bookDir)
		if len(formats) == 0 {
			continue
		}
		cover := findExistingCover(bookDir)
		if cover == "" {
			cover = existingCoverForFormats(formats, snapshots)
		}
		if cover == "" && hasCover != 0 {
			cover = extractCoverFromFormats(formats, thumbDir)
		}
		sourceMtime := sourceMtime(bookDir)
		metadata := map[string]any{
			"title":                 title.String,
			"author":                coalesce(authors.String, "Unknown"),
			"source_type":           "db",
			"tag":                   normalizeCSV(tags.String),
			"series":                series.String,
			"isbn":                  isbn.String,
			"publisher":             normalizeCSV(publishers.String),
			"language":              normalizeCSV(languages.String),
			"description":           normalizeDescription(description.String),
			"pubdate":               normalizeDate(pubdate.String),
			"published_at":          normalizeDate(pubdate.String),
			"uuid":                  uuid.String,
			"author_sort":           authorSort.String,
			"library_timestamp":     normalizeDate(timestamp.String),
			"library_last_modified": normalizeDate(lastModified.String),
			"has_cover":             hasCover != 0,
		}
		if seriesIndex.Valid {
			metadata["series_index"] = seriesIndex.Float64
		}
		if sourceMtime.Valid {
			metadata["source_mtime"] = sourceMtime.Int64
		}
		for _, path := range formats {
			known[normalizePath(path)] = true
		}
		if err := onBook(scanBook{
			Title:       coalesce(title.String, "Untitled"),
			Author:      coalesce(authors.String, "Unknown"),
			Path:        pickPrimaryFormat(formats),
			Formats:     formats,
			CoverPath:   cover,
			SourceMtime: sourceMtime,
			Metadata:    metadata,
		}); err != nil {
			return nil, err
		}
	}
	return known, rows.Err()
}

func scanFilesystemBooks(ctx context.Context, libraryPath string, thumbDir string, dbKnown map[string]bool, snapshots map[string]existingSnapshot, onBook func(scanBook) error) error {
	formatsSet := ebookFormats()
	err := filepath.WalkDir(libraryPath, func(path string, d os.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		default:
		}
		if !d.IsDir() {
			return nil
		}
		if isExcludedPath(path) {
			if path != libraryPath {
				return filepath.SkipDir
			}
			return nil
		}
		groups := collectBookGroups(path, formatsSet)
		for _, group := range groups {
			primary := pickPrimaryFormat(group.Formats)
			if primary == "" || dbKnown[normalizePath(primary)] {
				continue
			}
			metadata := readSidecarMetadata(path)
			if epubPath := group.Formats["epub"]; epubPath != "" {
				for k, v := range readEpubMetadata(epubPath) {
					if _, ok := metadata[k]; !ok {
						metadata[k] = v
					}
				}
			}
			metadata["source_type"] = "fs"
			mt := sourceMtime(path)
			if mt.Valid {
				metadata["source_mtime"] = mt.Int64
			}
			title := stringValue(metadata["title"])
			if title == "" {
				title = strings.TrimSpace(strings.NewReplacer("_", " ", "-", " ").Replace(group.Stem))
			}
			author := stringValue(metadata["author"])
			if author == "" {
				author = "Unknown"
			}
			cover := findExistingCover(path)
			if cover == "" {
				cover = existingCoverForFormats(group.Formats, snapshots)
			}
			if cover == "" {
				cover = extractCoverFromFormats(group.Formats, thumbDir)
			}
			if err := onBook(scanBook{
				Title:       title,
				Author:      author,
				Path:        primary,
				Formats:     group.Formats,
				CoverPath:   cover,
				SourceMtime: mt,
				Metadata:    metadata,
			}); err != nil {
				return err
			}
		}
		return nil
	})
	return err
}

type bookGroup struct {
	Stem    string
	Formats map[string]string
}

func collectBookGroups(dir string, formatsSet map[string]bool) []bookGroup {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil
	}
	groups := map[string]*bookGroup{}
	for _, entry := range entries {
		if entry.IsDir() {
			continue
		}
		ext := strings.TrimPrefix(strings.ToLower(filepath.Ext(entry.Name())), ".")
		if !formatsSet[ext] {
			continue
		}
		stem := strings.TrimSuffix(entry.Name(), filepath.Ext(entry.Name()))
		key := strings.ToLower(stem)
		if groups[key] == nil {
			groups[key] = &bookGroup{Stem: stem, Formats: map[string]string{}}
		}
		groups[key].Formats[ext] = filepath.Join(dir, entry.Name())
	}
	out := make([]bookGroup, 0, len(groups))
	for _, g := range groups {
		out = append(out, *g)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Stem < out[j].Stem })
	return out
}

type bookWriter struct {
	tx         *sql.Tx
	snapshots  map[string]existingSnapshot
	insertBook *sql.Stmt
	insertPath *sql.Stmt
	insertFTS  *sql.Stmt
	count      int
	closed     bool
}

func newBookWriter(db *sql.DB, snapshots map[string]existingSnapshot) (*bookWriter, error) {
	tx, err := db.Begin()
	if err != nil {
		return nil, err
	}
	cleanup := func(returnErr error) (*bookWriter, error) {
		_ = tx.Rollback()
		return nil, returnErr
	}
	if _, err := tx.Exec(`DELETE FROM books`); err != nil {
		return cleanup(err)
	}
	if _, err := tx.Exec(`DELETE FROM book_paths`); err != nil {
		return cleanup(err)
	}
	_, _ = tx.Exec(`DELETE FROM books_fts`)
	insertBook, err := tx.Prepare(`INSERT INTO books
(title, author, tag, series, isbn, publisher, language, description, published_at, series_index,
 uuid, author_sort, library_timestamp, library_last_modified, path, cover_path, source_mtime, formats_json, metadata_json, is_read)
VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`)
	if err != nil {
		return cleanup(err)
	}
	insertPath, err := tx.Prepare(`INSERT INTO book_paths(path, title, author, source_mtime, updated_at) VALUES(?, ?, ?, ?, CURRENT_TIMESTAMP)`)
	if err != nil {
		_ = insertBook.Close()
		return cleanup(err)
	}
	insertFTS, _ := tx.Prepare(`INSERT INTO books_fts(path, title, author, tag, series, isbn) VALUES(?, ?, ?, ?, ?, ?)`)
	return &bookWriter{
		tx:         tx,
		snapshots:  snapshots,
		insertBook: insertBook,
		insertPath: insertPath,
		insertFTS:  insertFTS,
	}, nil
}

func (w *bookWriter) add(book scanBook) error {
	for _, entry := range expandFormatEntries(book) {
		meta := cloneMap(book.Metadata)
		meta["format"] = entry.Format
		formatsJSON, _ := json.Marshal(map[string]string{entry.Format: entry.Path})
		metaJSON, _ := json.Marshal(meta)
		isRead := 0
		if w.snapshots[normalizePath(entry.Path)].IsRead || w.snapshots[normalizePath(book.Path)].IsRead {
			isRead = 1
		}
		seriesIndex := sql.NullFloat64{}
		switch v := meta["series_index"].(type) {
		case float64:
			seriesIndex = sql.NullFloat64{Float64: v, Valid: true}
		case string:
			if f, err := strconv.ParseFloat(v, 64); err == nil {
				seriesIndex = sql.NullFloat64{Float64: f, Valid: true}
			}
		}
		var mtime any
		if book.SourceMtime.Valid {
			mtime = book.SourceMtime.Int64
		}
		if _, err := w.insertBook.Exec(
			book.Title,
			book.Author,
			stringValue(meta["tag"]),
			stringValue(meta["series"]),
			stringValue(meta["isbn"]),
			stringValue(meta["publisher"]),
			stringValue(meta["language"]),
			stringValue(meta["description"]),
			coalesce(stringValue(meta["published_at"]), stringValue(meta["pubdate"])),
			nullFloatValue(seriesIndex),
			stringValue(meta["uuid"]),
			stringValue(meta["author_sort"]),
			stringValue(meta["library_timestamp"]),
			stringValue(meta["library_last_modified"]),
			normalizePath(entry.Path),
			normalizePath(book.CoverPath),
			mtime,
			string(formatsJSON),
			string(metaJSON),
			isRead,
		); err != nil {
			return err
		}
		if _, err := w.insertPath.Exec(normalizePath(entry.Path), book.Title, book.Author, mtime); err != nil {
			return err
		}
		if w.insertFTS != nil {
			_, _ = w.insertFTS.Exec(normalizePath(entry.Path), book.Title, book.Author, stringValue(meta["tag"]), stringValue(meta["series"]), stringValue(meta["isbn"]))
		}
		w.count++
	}
	return nil
}

func (w *bookWriter) finish() error {
	if w.closed {
		return nil
	}
	if _, err := w.tx.Exec(`INSERT INTO meta(key, value) VALUES('last_rebuild_at', ?)
ON CONFLICT(key) DO UPDATE SET value = excluded.value`, time.Now().Format(time.RFC3339)); err != nil {
		w.closeStatements()
		return err
	}
	w.closeStatements()
	w.closed = true
	return w.tx.Commit()
}

func (w *bookWriter) rollback() {
	if w == nil || w.closed {
		return
	}
	w.closeStatements()
	w.closed = true
	_ = w.tx.Rollback()
}

func (w *bookWriter) closeStatements() {
	if w.insertBook != nil {
		_ = w.insertBook.Close()
	}
	if w.insertPath != nil {
		_ = w.insertPath.Close()
	}
	if w.insertFTS != nil {
		_ = w.insertFTS.Close()
	}
}

func replaceBooks(db *sql.DB, books []scanBook, snapshots map[string]existingSnapshot) (int, error) {
	tx, err := db.Begin()
	if err != nil {
		return 0, err
	}
	defer tx.Rollback()
	if _, err := tx.Exec(`DELETE FROM books`); err != nil {
		return 0, err
	}
	if _, err := tx.Exec(`DELETE FROM book_paths`); err != nil {
		return 0, err
	}
	_, _ = tx.Exec(`DELETE FROM books_fts`)
	insertBook, err := tx.Prepare(`INSERT INTO books
(title, author, tag, series, isbn, publisher, language, description, published_at, series_index,
 uuid, author_sort, library_timestamp, library_last_modified, path, cover_path, source_mtime, formats_json, metadata_json, is_read)
VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`)
	if err != nil {
		return 0, err
	}
	defer insertBook.Close()
	insertPath, err := tx.Prepare(`INSERT INTO book_paths(path, title, author, source_mtime, updated_at) VALUES(?, ?, ?, ?, CURRENT_TIMESTAMP)`)
	if err != nil {
		return 0, err
	}
	defer insertPath.Close()
	insertFTS, _ := tx.Prepare(`INSERT INTO books_fts(path, title, author, tag, series, isbn) VALUES(?, ?, ?, ?, ?, ?)`)
	if insertFTS != nil {
		defer insertFTS.Close()
	}
	count := 0
	for _, book := range books {
		entries := expandFormatEntries(book)
		for _, entry := range entries {
			meta := cloneMap(book.Metadata)
			meta["format"] = entry.Format
			formatsJSON, _ := json.Marshal(map[string]string{entry.Format: entry.Path})
			metaJSON, _ := json.Marshal(meta)
			isRead := 0
			if snapshots[normalizePath(entry.Path)].IsRead || snapshots[normalizePath(book.Path)].IsRead {
				isRead = 1
			}
			seriesIndex := sql.NullFloat64{}
			switch v := meta["series_index"].(type) {
			case float64:
				seriesIndex = sql.NullFloat64{Float64: v, Valid: true}
			case string:
				if f, err := strconv.ParseFloat(v, 64); err == nil {
					seriesIndex = sql.NullFloat64{Float64: f, Valid: true}
				}
			}
			var mtime any
			if book.SourceMtime.Valid {
				mtime = book.SourceMtime.Int64
			}
			if _, err := insertBook.Exec(
				book.Title,
				book.Author,
				stringValue(meta["tag"]),
				stringValue(meta["series"]),
				stringValue(meta["isbn"]),
				stringValue(meta["publisher"]),
				stringValue(meta["language"]),
				stringValue(meta["description"]),
				coalesce(stringValue(meta["published_at"]), stringValue(meta["pubdate"])),
				nullFloatValue(seriesIndex),
				stringValue(meta["uuid"]),
				stringValue(meta["author_sort"]),
				stringValue(meta["library_timestamp"]),
				stringValue(meta["library_last_modified"]),
				normalizePath(entry.Path),
				normalizePath(book.CoverPath),
				mtime,
				string(formatsJSON),
				string(metaJSON),
				isRead,
			); err != nil {
				return 0, err
			}
			if _, err := insertPath.Exec(normalizePath(entry.Path), book.Title, book.Author, mtime); err != nil {
				return 0, err
			}
			if insertFTS != nil {
				_, _ = insertFTS.Exec(normalizePath(entry.Path), book.Title, book.Author, stringValue(meta["tag"]), stringValue(meta["series"]), stringValue(meta["isbn"]))
			}
			count++
		}
	}
	if _, err := tx.Exec(`INSERT INTO meta(key, value) VALUES('last_rebuild_at', ?)
ON CONFLICT(key) DO UPDATE SET value = excluded.value`, time.Now().Format(time.RFC3339)); err != nil {
		return 0, err
	}
	return count, tx.Commit()
}

type formatEntry struct {
	Format string
	Path   string
}

func expandFormatEntries(book scanBook) []formatEntry {
	var out []formatEntry
	keys := make([]string, 0, len(book.Formats))
	for key := range book.Formats {
		keys = append(keys, key)
	}
	sort.Strings(keys)
	for _, key := range keys {
		if path := book.Formats[key]; path != "" {
			out = append(out, formatEntry{Format: key, Path: path})
		}
	}
	if len(out) == 0 && book.Path != "" {
		ext := strings.TrimPrefix(strings.ToLower(filepath.Ext(book.Path)), ".")
		if ext == "" {
			ext = "file"
		}
		out = append(out, formatEntry{Format: ext, Path: book.Path})
	}
	return out
}

func ensureLibrarySchema(db *sql.DB) error {
	statements := []string{
		`CREATE TABLE IF NOT EXISTS books (
id INTEGER PRIMARY KEY AUTOINCREMENT,
title TEXT NOT NULL,
author TEXT NOT NULL,
tag TEXT,
series TEXT,
isbn TEXT,
publisher TEXT,
language TEXT,
description TEXT,
published_at TEXT,
series_index REAL,
uuid TEXT,
author_sort TEXT,
library_timestamp TEXT,
library_last_modified TEXT,
path TEXT NOT NULL,
cover_path TEXT,
source_mtime INTEGER,
formats_json TEXT,
metadata_json TEXT,
is_read INTEGER NOT NULL DEFAULT 0,
created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)`,
		`CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT)`,
		`CREATE TABLE IF NOT EXISTS book_paths (path TEXT PRIMARY KEY, title TEXT NOT NULL, author TEXT NOT NULL, source_mtime INTEGER, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)`,
		`CREATE INDEX IF NOT EXISTS idx_books_title ON books(title)`,
		`CREATE INDEX IF NOT EXISTS idx_books_author ON books(author)`,
		`CREATE INDEX IF NOT EXISTS idx_books_path ON books(path)`,
		`CREATE INDEX IF NOT EXISTS idx_book_paths_source_mtime ON book_paths(source_mtime)`,
	}
	for _, stmt := range statements {
		if _, err := db.Exec(stmt); err != nil {
			return err
		}
	}
	if _, err := db.Exec(`CREATE VIRTUAL TABLE IF NOT EXISTS books_fts USING fts5(path UNINDEXED, title, author, tag, series, isbn, tokenize = 'unicode61')`); err != nil {
		_, _ = db.Exec(`CREATE VIRTUAL TABLE IF NOT EXISTS books_fts USING fts4(path, title, author, tag, series, isbn, notindexed=path)`)
	}
	return nil
}

func loadExistingSnapshots(sqlitePath string) map[string]existingSnapshot {
	out := map[string]existingSnapshot{}
	if !fileExists(sqlitePath) {
		return out
	}
	db, err := openSQLite(sqlitePath)
	if err != nil {
		return out
	}
	defer db.Close()
	rows, err := db.Query(`SELECT path, is_read, COALESCE(cover_path, '') FROM books`)
	if err != nil {
		return out
	}
	defer rows.Close()
	for rows.Next() {
		var path, cover string
		var isRead int
		if rows.Scan(&path, &isRead, &cover) == nil {
			out[normalizePath(path)] = existingSnapshot{IsRead: isRead == 1, CoverPath: normalizePath(cover)}
		}
	}
	return out
}

func findFormats(dir string) map[string]string {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil
	}
	allowed := ebookFormats()
	formats := map[string]string{}
	for _, entry := range entries {
		if entry.IsDir() {
			continue
		}
		ext := strings.TrimPrefix(strings.ToLower(filepath.Ext(entry.Name())), ".")
		if allowed[ext] {
			formats[ext] = filepath.Join(dir, entry.Name())
		}
	}
	return formats
}

func ebookFormats() map[string]bool {
	return map[string]bool{"epub": true, "mobi": true, "azw3": true, "pdf": true, "cbz": true, "azw": true, "txt": true, "html": true, "rtf": true}
}

func pickPrimaryFormat(formats map[string]string) string {
	for _, key := range []string{"epub", "pdf", "mobi", "azw3", "cbz", "azw", "txt", "html", "rtf"} {
		if path := formats[key]; path != "" {
			return path
		}
	}
	keys := make([]string, 0, len(formats))
	for key := range formats {
		keys = append(keys, key)
	}
	sort.Strings(keys)
	if len(keys) == 0 {
		return ""
	}
	return formats[keys[0]]
}

func findExistingCover(dir string) string {
	for _, name := range []string{"cover.jpg", "cover.jpeg", "cover.png"} {
		path := filepath.Join(dir, name)
		if fileExists(path) {
			return path
		}
	}
	return ""
}

func existingCoverForFormats(formats map[string]string, snapshots map[string]existingSnapshot) string {
	for _, path := range formats {
		cover := snapshots[normalizePath(path)].CoverPath
		if cover != "" && fileExists(cover) {
			return cover
		}
	}
	return ""
}

func extractCoverFromFormats(formats map[string]string, thumbDir string) string {
	if path := formats["epub"]; path != "" {
		if cover := extractCoverFromEpub(path, thumbDir); cover != "" {
			return cover
		}
	}
	if path := formats["cbz"]; path != "" {
		return extractCoverFromZipFirstImage(path, thumbDir)
	}
	return ""
}

func extractCoverFromEpub(epubPath string, thumbDir string) string {
	zr, err := zip.OpenReader(epubPath)
	if err != nil {
		return ""
	}
	defer zr.Close()
	containerRaw := zipReadString(&zr.Reader, "META-INF/container.xml")
	if containerRaw == "" {
		return ""
	}
	rootfile := parseRootfile(containerRaw)
	if rootfile == "" {
		return ""
	}
	opfRaw := zipReadString(&zr.Reader, rootfile)
	if opfRaw == "" {
		return ""
	}
	coverHref := parseCoverHref(opfRaw)
	if coverHref == "" {
		return ""
	}
	if base := filepath.ToSlash(filepath.Dir(rootfile)); base != "." && base != "" {
		coverHref = base + "/" + coverHref
	}
	data := zipReadBytes(&zr.Reader, coverHref)
	if len(data) == 0 {
		return ""
	}
	ext := strings.TrimPrefix(strings.ToLower(filepath.Ext(coverHref)), ".")
	if ext == "" {
		ext = "jpg"
	}
	return writeThumb(epubPath, thumbDir, ext, data)
}

func extractCoverFromZipFirstImage(zipPath string, thumbDir string) string {
	zr, err := zip.OpenReader(zipPath)
	if err != nil {
		return ""
	}
	defer zr.Close()
	for _, f := range zr.File {
		if f.FileInfo().IsDir() {
			continue
		}
		ext := strings.TrimPrefix(strings.ToLower(filepath.Ext(f.Name)), ".")
		if ext != "jpg" && ext != "jpeg" && ext != "png" && ext != "gif" && ext != "webp" {
			continue
		}
		rc, err := f.Open()
		if err != nil {
			continue
		}
		data, err := io.ReadAll(rc)
		_ = rc.Close()
		if err == nil && len(data) > 0 {
			return writeThumb(zipPath, thumbDir, ext, data)
		}
	}
	return ""
}

func writeThumb(sourcePath string, thumbDir string, ext string, data []byte) string {
	if err := os.MkdirAll(thumbDir, 0o777); err != nil {
		return ""
	}
	stem := sanitizeThumbStem(strings.TrimSuffix(filepath.Base(sourcePath), filepath.Ext(sourcePath)))
	sum := sha1.Sum([]byte(sourcePath))
	path := filepath.Join(thumbDir, fmt.Sprintf("%s_%s_cover.%s", stem, hex.EncodeToString(sum[:])[:12], ext))
	if err := os.WriteFile(path, data, 0o666); err != nil {
		return ""
	}
	return path
}

func sanitizeThumbStem(value string) string {
	re := regexp.MustCompile(`[^[:alnum:]._~-]+`)
	value = strings.Trim(re.ReplaceAllString(value, "_"), "._-")
	if value == "" {
		value = "book"
	}
	if len(value) > 80 {
		value = value[:80]
	}
	return value
}

func zipReadString(zr *zip.Reader, name string) string {
	return string(zipReadBytes(zr, name))
}

func zipReadBytes(zr *zip.Reader, name string) []byte {
	for _, f := range zr.File {
		if filepath.ToSlash(f.Name) != filepath.ToSlash(name) {
			continue
		}
		rc, err := f.Open()
		if err != nil {
			return nil
		}
		defer rc.Close()
		data, err := io.ReadAll(rc)
		if err != nil {
			return nil
		}
		return data
	}
	return nil
}

func parseRootfile(raw string) string {
	type rootfile struct {
		FullPath string `xml:"full-path,attr"`
	}
	type container struct {
		Rootfiles []rootfile `xml:"rootfiles>rootfile"`
	}
	var c container
	if xml.Unmarshal([]byte(raw), &c) != nil || len(c.Rootfiles) == 0 {
		return ""
	}
	return c.Rootfiles[0].FullPath
}

func parseCoverHref(raw string) string {
	decoder := xml.NewDecoder(strings.NewReader(raw))
	type item struct {
		ID         string
		Href       string
		MediaType  string
		Properties string
	}
	items := []item{}
	for {
		tok, err := decoder.Token()
		if err != nil {
			break
		}
		start, ok := tok.(xml.StartElement)
		if !ok || start.Name.Local != "item" {
			continue
		}
		it := item{}
		for _, attr := range start.Attr {
			switch attr.Name.Local {
			case "id":
				it.ID = attr.Value
			case "href":
				it.Href = attr.Value
			case "media-type":
				it.MediaType = attr.Value
			case "properties":
				it.Properties = attr.Value
			}
		}
		items = append(items, it)
	}
	for _, it := range items {
		if strings.Contains(it.Properties, "cover-image") && it.Href != "" {
			return it.Href
		}
	}
	for _, it := range items {
		if strings.Contains(strings.ToLower(it.ID), "cover") && strings.HasPrefix(it.MediaType, "image/") && it.Href != "" {
			return it.Href
		}
	}
	return ""
}

func readSidecarMetadata(dir string) map[string]any {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return map[string]any{}
	}
	for _, entry := range entries {
		if entry.IsDir() || strings.ToLower(filepath.Ext(entry.Name())) != ".opf" {
			continue
		}
		raw, err := os.ReadFile(filepath.Join(dir, entry.Name()))
		if err == nil {
			return parseOPFMetadata(string(raw))
		}
	}
	return map[string]any{}
}

func readEpubMetadata(epubPath string) map[string]any {
	zr, err := zip.OpenReader(epubPath)
	if err != nil {
		return map[string]any{}
	}
	defer zr.Close()
	rootfile := parseRootfile(zipReadString(&zr.Reader, "META-INF/container.xml"))
	if rootfile == "" {
		return map[string]any{}
	}
	return parseOPFMetadata(zipReadString(&zr.Reader, rootfile))
}

func parseOPFMetadata(raw string) map[string]any {
	result := map[string]any{}
	decoder := xml.NewDecoder(strings.NewReader(raw))
	var current string
	subjects := []string{}
	for {
		tok, err := decoder.Token()
		if err != nil {
			break
		}
		switch t := tok.(type) {
		case xml.StartElement:
			current = t.Name.Local
			if current == "meta" {
				var name, content string
				for _, attr := range t.Attr {
					if attr.Name.Local == "name" {
						name = attr.Value
					}
					if attr.Name.Local == "content" {
						content = attr.Value
					}
				}
				switch name {
				case "calibre:series":
					result["series"] = strings.TrimSpace(content)
				case "calibre:series_index":
					result["series_index"] = strings.TrimSpace(content)
				case "calibre:author_sort":
					result["author_sort"] = strings.TrimSpace(content)
				case "calibre:timestamp":
					result["library_timestamp"] = normalizeDate(content)
				case "calibre:comments":
					result["description"] = normalizeDescription(content)
				}
			}
		case xml.CharData:
			text := strings.TrimSpace(string(t))
			if text == "" {
				continue
			}
			switch current {
			case "title":
				result["title"] = text
			case "creator":
				result["author"] = text
			case "identifier":
				if result["identifier"] == nil {
					result["identifier"] = text
				}
				if result["isbn"] == nil && strings.Contains(strings.ToLower(text), "isbn") {
					result["isbn"] = text
				}
				if result["uuid"] == nil && strings.Contains(strings.ToLower(text), "uuid") {
					result["uuid"] = strings.TrimPrefix(text, "urn:uuid:")
				}
			case "publisher":
				result["publisher"] = text
			case "language":
				result["language"] = text
			case "date":
				result["published_at"] = normalizeDate(text)
				result["pubdate"] = normalizeDate(text)
			case "description":
				result["description"] = normalizeDescription(text)
			case "subject":
				subjects = append(subjects, text)
			}
		case xml.EndElement:
			current = ""
		}
	}
	if len(subjects) > 0 {
		result["tag"] = strings.Join(subjects, ", ")
	}
	return result
}

func sourceMtime(path string) sql.NullInt64 {
	info, err := os.Stat(path)
	if err != nil {
		return sql.NullInt64{}
	}
	if !info.IsDir() {
		info, err = os.Stat(filepath.Dir(path))
		if err != nil {
			return sql.NullInt64{}
		}
	}
	return sql.NullInt64{Int64: info.ModTime().Unix(), Valid: true}
}

func cleanupSQLiteArtifacts(base string) {
	for _, path := range []string{base, base + "-wal", base + "-shm"} {
		_ = os.Remove(path)
	}
}

func promoteSQLite(target string, tmp string) error {
	backup := target + ".swap-backup"
	cleanupSQLiteArtifacts(backup)
	for _, suffix := range []string{"", "-wal", "-shm"} {
		if fileExists(target + suffix) {
			if err := moveFile(target+suffix, backup+suffix); err != nil {
				return err
			}
		}
	}
	for _, suffix := range []string{"", "-wal", "-shm"} {
		if fileExists(tmp + suffix) {
			if err := moveFile(tmp+suffix, target+suffix); err != nil {
				return err
			}
		}
	}
	cleanupSQLiteArtifacts(backup)
	return nil
}

func moveFile(src string, dst string) error {
	if err := os.Rename(src, dst); err == nil {
		return nil
	}
	if err := copyFile(src, dst); err != nil {
		return err
	}
	return os.Remove(src)
}

func copyFile(src string, dst string) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	out, err := os.OpenFile(dst, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o666)
	if err != nil {
		return err
	}
	if _, err := io.Copy(out, in); err != nil {
		_ = out.Close()
		return err
	}
	if err := out.Sync(); err != nil {
		_ = out.Close()
		return err
	}
	return out.Close()
}

func (w *worker) libraryPath() string {
	return resolvePath(w.appRoot, firstSetting(w.config, []string{"BOOKS_CALIBRE_LIBRARY_PATH", "CALIBRE_LIBRARY_PATH"}, "CALIBRE_LIBRARY_PATH", ""))
}

func (w *worker) scanTmpPath(sqlitePath string) string {
	value := firstSetting(w.config, []string{"BOOKS_SCAN_TMP_SQLITE_PATH", "SCAN_TMP_SQLITE_PATH"}, "SCAN_TMP_SQLITE_PATH", "/tmp/books-scan.tmp.sqlite")
	if strings.TrimSpace(value) == "" {
		return sqlitePath + ".tmp"
	}
	return resolvePath(w.appRoot, value)
}

func (w *worker) thumbDir() string {
	return filepath.Join(w.appRoot, "data", "thumb")
}

func (w *worker) appendLog(path string, line string) {
	_ = os.MkdirAll(filepath.Dir(path), 0o777)
	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o666)
	if err != nil {
		return
	}
	defer f.Close()
	_, _ = f.WriteString(line + "\n")
}

func (w *worker) clearOPDSCache() {
	dir := filepath.Join(w.appRoot, "data", "opds-cache")
	entries, err := os.ReadDir(dir)
	if err != nil {
		return
	}
	for _, entry := range entries {
		if entry.IsDir() {
			continue
		}
		if strings.HasSuffix(entry.Name(), ".xml") {
			_ = os.Remove(filepath.Join(dir, entry.Name()))
		}
	}
}

func isExcludedPath(path string) bool {
	return strings.Contains("/"+normalizePath(path)+"/", "/.caltrash/")
}

func normalizePath(path string) string {
	return filepath.ToSlash(path)
}

func normalizeCSV(value string) string {
	parts := strings.Split(value, ",")
	out := []string{}
	for _, part := range parts {
		part = strings.TrimSpace(part)
		if part != "" {
			out = append(out, part)
		}
	}
	return strings.Join(out, ", ")
}

var tagRE = regexp.MustCompile(`<[^>]+>`)
var brRE = regexp.MustCompile(`(?i)<\s*br\s*/?>`)
var pRE = regexp.MustCompile(`(?i)<\s*/p\s*>`)

func normalizeDescription(value string) string {
	value = strings.TrimSpace(value)
	if value == "" {
		return ""
	}
	value = brRE.ReplaceAllString(value, "\n")
	value = pRE.ReplaceAllString(value, "\n\n")
	value = tagRE.ReplaceAllString(value, "")
	value = html.UnescapeString(value)
	value = strings.ReplaceAll(value, "\r\n", "\n")
	value = strings.ReplaceAll(value, "\r", "\n")
	return strings.TrimSpace(value)
}

func normalizeDate(value string) string {
	value = strings.TrimSpace(value)
	if value == "" || strings.HasPrefix(value, "0101-") {
		return ""
	}
	return value
}

func coalesce(values ...string) string {
	for _, value := range values {
		if strings.TrimSpace(value) != "" {
			return strings.TrimSpace(value)
		}
	}
	return ""
}

func stringValue(value any) string {
	if value == nil {
		return ""
	}
	return strings.TrimSpace(fmt.Sprint(value))
}

func cloneMap(in map[string]any) map[string]any {
	out := map[string]any{}
	for k, v := range in {
		out[k] = v
	}
	return out
}

func nullFloatValue(value sql.NullFloat64) any {
	if !value.Valid {
		return nil
	}
	return value.Float64
}

func init() {
	_ = errors.New
}
