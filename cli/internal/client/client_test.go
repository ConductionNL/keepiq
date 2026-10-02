package client

import (
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"
)

// serverEnvelope returns the envelope the server's real
// MachineSecretEnvelopeService::serialize() writes (cli/testdata, guarded by a
// PHPUnit test), so this test cannot drift back to a shape only the CLI knows.
func serverEnvelope(t *testing.T) []byte {
	t.Helper()
	raw, err := os.ReadFile("../../testdata/machine_envelope.json")
	if err != nil {
		t.Fatal(err)
	}
	var f struct {
		Envelope json.RawMessage `json:"envelope"`
	}
	if err := json.Unmarshal(raw, &f); err != nil {
		t.Fatal(err)
	}
	return f.Envelope
}

// TestFetchByNameConditional verifies the ETag poll loop: the first fetch
// captures the ETag and decodes the server's envelope; an unchanged re-fetch
// sends If-None-Match and is answered 304 → ErrNotModified (§4.2).
func TestFetchByNameConditional(t *testing.T) {
	const etag = `"v1-abc"`
	body := serverEnvelope(t)
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
		_, _ = w.Write(body)
	}))
	defer srv.Close()

	c := New(srv.URL)

	env, err := c.FetchByName("DB_PASSWORD", "tok")
	if err != nil {
		t.Fatalf("first fetch: %v", err)
	}
	if env.Format != "doriath-machine-secret-v1" {
		t.Fatalf("format = %q", env.Format)
	}
	if env.Encryption.Scheme != "rsa-oaep-sha256-chunked-v1" {
		t.Fatalf("encryption.scheme = %q", env.Encryption.Scheme)
	}
	if env.Ciphertext.Key == "" || env.Ciphertext.Login == "" || env.Ciphertext.AdditionalFields == "" {
		t.Fatalf("ciphertext fields not decoded: %+v", env.Ciphertext)
	}
	if env.Secret.Name != "ci-fixture-db-password" {
		t.Fatalf("secret.name = %q", env.Secret.Name)
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

// TestDiscoverUsesTheKeepiqPath verifies discovery asks for the canonical
// .well-known/keepiq document and never touches the doriath alias when the
// canonical path answers (#755).
func TestDiscoverUsesTheKeepiqPath(t *testing.T) {
	var paths []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		paths = append(paths, r.URL.Path)
		if r.URL.Path != discoveryPath {
			http.NotFound(w, r)
			return
		}
		_, _ = w.Write([]byte(`{"apiVersion":1,"assertion":{"audience":"keepiq"}}`))
	}))
	defer srv.Close()

	d, err := New(srv.URL).Discover()
	if err != nil {
		t.Fatal(err)
	}
	if d.Assertion.Audience != "keepiq" {
		t.Errorf("audience = %q", d.Assertion.Audience)
	}
	if len(paths) != 1 || paths[0] != "/apps/keepiq/api/v1/app/.well-known/keepiq" {
		t.Errorf("requested %v, want only the keepiq path", paths)
	}
}

// TestDiscoverFallsBackToTheDoriathPathOn404 keeps the CLI working against a
// server that predates the canonical path.
func TestDiscoverFallsBackToTheDoriathPathOn404(t *testing.T) {
	var paths []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		paths = append(paths, r.URL.Path)
		if r.URL.Path != legacyDiscoveryPath {
			http.NotFound(w, r)
			return
		}
		_, _ = w.Write([]byte(`{"apiVersion":1}`))
	}))
	defer srv.Close()

	if _, err := New(srv.URL).Discover(); err != nil {
		t.Fatal(err)
	}
	if len(paths) != 2 || paths[1] != legacyDiscoveryPath {
		t.Errorf("requested %v, want keepiq then doriath", paths)
	}
}

// TestDiscoverDoesNotFallBackOnOtherErrors keeps a 401 a 401: only a missing
// canonical path is a reason to try the alias.
func TestDiscoverDoesNotFallBackOnOtherErrors(t *testing.T) {
	calls := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		w.WriteHeader(http.StatusUnauthorized)
	}))
	defer srv.Close()

	if _, err := New(srv.URL).Discover(); err == nil {
		t.Fatal("want an error on 401")
	}
	if calls != 1 {
		t.Errorf("calls = %d, want 1", calls)
	}
}

// TestDefaultAudienceIsTheCanonicalOne pins the fallback audience to the
// server's AudiencePolicy::CANONICAL_AUDIENCE, not the pre-rename name.
func TestDefaultAudienceIsTheCanonicalOne(t *testing.T) {
	src, err := os.ReadFile("../../../lib/Service/AudiencePolicy.php")
	if err != nil {
		t.Fatal(err)
	}
	want := `CANONICAL_AUDIENCE = '` + DefaultAudience + `'`
	if !strings.Contains(string(src), want) {
		t.Errorf("AudiencePolicy.php does not declare %s", want)
	}
}
