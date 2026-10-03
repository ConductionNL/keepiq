// Package logx is the runner's structured log. The runner never passes a
// value to it; as a second line of defence every value the runner handles is
// registered with Forget, and any log record that would contain one has it
// replaced by [REDACTED].
package logx

import (
	"context"
	"io"
	"log/slog"
	"strings"
	"sync"
)

// Redactor remembers values that must never be printed.
type Redactor struct {
	mu     sync.RWMutex
	values map[string]struct{}
}

// NewRedactor returns an empty redactor.
func NewRedactor() *Redactor { return &Redactor{values: map[string]struct{}{}} }

// Forget registers a value to redact. Values shorter than 4 bytes are not
// registered, so they cannot blank out ordinary words.
func (r *Redactor) Forget(v string) {
	if len(v) < 4 {
		return
	}
	r.mu.Lock()
	r.values[v] = struct{}{}
	r.mu.Unlock()
}

// Clean replaces every registered value in s.
func (r *Redactor) Clean(s string) string {
	r.mu.RLock()
	defer r.mu.RUnlock()
	for v := range r.values {
		if strings.Contains(s, v) {
			s = strings.ReplaceAll(s, v, "[REDACTED]")
		}
	}
	return s
}

type handler struct {
	next slog.Handler
	r    *Redactor
}

// New returns a JSON logger on w that redacts registered values.
func New(w io.Writer, r *Redactor, level slog.Level) *slog.Logger {
	return slog.New(&handler{next: slog.NewJSONHandler(w, &slog.HandlerOptions{Level: level}), r: r})
}

func (h *handler) Enabled(ctx context.Context, l slog.Level) bool { return h.next.Enabled(ctx, l) }

func (h *handler) Handle(ctx context.Context, rec slog.Record) error {
	out := slog.NewRecord(rec.Time, rec.Level, h.r.Clean(rec.Message), rec.PC)
	rec.Attrs(func(a slog.Attr) bool {
		out.AddAttrs(h.clean(a))
		return true
	})
	return h.next.Handle(ctx, out)
}

func (h *handler) clean(a slog.Attr) slog.Attr {
	if a.Value.Kind() == slog.KindGroup {
		attrs := a.Value.Group()
		cleaned := make([]any, 0, len(attrs))
		for _, g := range attrs {
			cleaned = append(cleaned, h.clean(g))
		}
		return slog.Group(a.Key, cleaned...)
	}
	return slog.String(a.Key, h.r.Clean(a.Value.String()))
}

func (h *handler) WithAttrs(attrs []slog.Attr) slog.Handler {
	cleaned := make([]slog.Attr, 0, len(attrs))
	for _, a := range attrs {
		cleaned = append(cleaned, h.clean(a))
	}
	return &handler{next: h.next.WithAttrs(cleaned), r: h.r}
}

func (h *handler) WithGroup(name string) slog.Handler {
	return &handler{next: h.next.WithGroup(name), r: h.r}
}
