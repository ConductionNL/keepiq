package logx

import (
	"bytes"
	"errors"
	"log/slog"
	"strings"
	"testing"
)

func TestRegisteredValuesNeverReachTheLog(t *testing.T) {
	var buf bytes.Buffer
	r := NewRedactor()
	log := New(&buf, r, slog.LevelInfo)
	r.Forget("hunter2-secret")
	log.With("ctx", "hunter2-secret").Info("value hunter2-secret leaked", "error", errors.New("login hunter2-secret refused").Error(), slog.Group("g", "v", "hunter2-secret"))
	if strings.Contains(buf.String(), "hunter2-secret") {
		t.Fatalf("log carries the value: %s", buf.String())
	}
	if strings.Count(buf.String(), "[REDACTED]") != 4 {
		t.Fatalf("want 4 redactions: %s", buf.String())
	}
}
