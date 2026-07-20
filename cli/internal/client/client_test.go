package client

import (
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"
)

// TestFetchByNameConditional verifies the ETag poll loop: the first fetch
// captures the ETag and decodes the envelope; an unchanged re-fetch sends
// If-None-Match and is answered 304 → ErrNotModified (§4.2).
func TestFetchByNameConditional(t *testing.T) {
	const etag = `"v1-abc"`
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer tok" {
			t.Errorf("missing bearer, got %q", r.Header.Get("Authorization"))
		}
		if r.Header.Get("If-None-Match") == etag {
			w.Header().Set("ETag", etag)
			w.WriteHeader(http.StatusNotModified)
			return
		}
		w.Header().Set("ETag", etag)
		w.Header().Set("Doriath-Lease-Id", "lease-9")
		w.Header().Set("Doriath-Lease-Expires", "2026-01-01T00:00:00Z")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"format":"doriath-machine-secret-v1","scheme":"rsa-oaep-sha256-chunked-v1","payload":{"value":"QUJD"}}`))
	}))
	defer srv.Close()

	c := New(srv.URL)

	env, err := c.FetchByName("DB_PASSWORD", "tok")
	if err != nil {
		t.Fatalf("first fetch: %v", err)
	}
	if env.Payload.Value != "QUJD" {
		t.Fatalf("payload = %q", env.Payload.Value)
	}
	if c.LeaseID() != "lease-9" {
		t.Fatalf("lease id = %q", c.LeaseID())
	}
	if c.LastETag() != etag {
		t.Fatalf("etag = %q", c.LastETag())
	}

	// Unchanged re-fetch → 304 → ErrNotModified.
	if _, err := c.FetchByName("DB_PASSWORD", "tok"); !errors.Is(err, ErrNotModified) {
		t.Fatalf("second fetch: want ErrNotModified, got %v", err)
	}
}
