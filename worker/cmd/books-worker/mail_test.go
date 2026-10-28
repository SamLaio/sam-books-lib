package main

import (
	"encoding/base64"
	"io"
	"mime"
	"mime/multipart"
	"net"
	"net/mail"
	"net/textproto"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestSendMailDateAndAttachment(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	received := make(chan string, 1)
	go func() {
		conn, err := listener.Accept()
		if err != nil {
			received <- ""
			return
		}
		defer conn.Close()
		conn.SetDeadline(time.Now().Add(5 * time.Second))
		peer := textproto.NewConn(conn)
		peer.PrintfLine("220 localhost SMTP")
		for {
			line, err := peer.ReadLine()
			if err != nil {
				received <- ""
				return
			}
			switch {
			case line == "DATA":
				peer.PrintfLine("354 Send message")
				data, _ := io.ReadAll(peer.DotReader())
				received <- string(data)
				peer.PrintfLine("250 Accepted")
			case line == "QUIT":
				peer.PrintfLine("221 Bye")
				return
			default:
				peer.PrintfLine("250 OK")
			}
		}
	}()
	attachment := filepath.Join(t.TempDir(), "book.epub")
	if err := os.WriteFile(attachment, []byte("book content"), 0600); err != nil {
		t.Fatal(err)
	}
	started := time.Now().Truncate(time.Second)
	err = sendMail(smtpSettings{Host: "127.0.0.1", Port: listener.Addr().(*net.TCPAddr).Port}, "sender@example.com", "reader@example.com", "寄書測試", "Book", attachment, "book.epub", "application/epub+zip")
	if err != nil {
		t.Fatal(err)
	}
	message, err := mail.ReadMessage(strings.NewReader(<-received))
	if err != nil {
		t.Fatal(err)
	}
	date, err := mail.ParseDate(message.Header.Get("Date"))
	if err != nil || date.Before(started) || date.After(time.Now()) {
		t.Fatalf("invalid Date header %q: %v", message.Header.Get("Date"), err)
	}
	_, params, err := mime.ParseMediaType(message.Header.Get("Content-Type"))
	if err != nil {
		t.Fatal(err)
	}
	parts := multipart.NewReader(message.Body, params["boundary"])
	if _, err := parts.NextPart(); err != nil {
		t.Fatal(err)
	}
	part, err := parts.NextPart()
	if err != nil {
		t.Fatal(err)
	}
	data, err := io.ReadAll(base64.NewDecoder(base64.StdEncoding, part))
	if err != nil || string(data) != "book content" {
		t.Fatalf("attachment changed: %q, %v", data, err)
	}
}
