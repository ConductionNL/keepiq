package keepiq

import (
	"crypto"
	"crypto/rsa"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"testing"
	"time"

	kcrypto "github.com/ConductionNL/keepiq/sdk/go/crypto"
)

// fixture is sdk/testdata/machine_envelope.json: the envelope the server's
// MachineSecretEnvelopeService::serialize() writes, guarded by
// tests/Unit/Service/MachineEnvelopeCliFixtureTest.php.
type fixture struct {
	PrivateKeyPem  string            `json:"privateKeyPem"`
	CertificatePem string            `json:"certificatePem"`
	Plaintext      map[string]string `json:"plaintext"`
	Envelope       json.RawMessage   `json:"envelope"`
}

func loadFixture(t *testing.T) fixture {
	t.Helper()
	raw, err := os.ReadFile("../testdata/machine_envelope.json")
	if err != nil {
		t.Fatal(err)
	}
	var f fixture
	if err := json.Unmarshal(raw, &f); err != nil {
		t.Fatal(err)
	}
	return f
}

// stub is a small in-memory Keepiq machine API. It checks the assertion
// signature against the application's public key, as JwtAuthService does,
// and records every request body so a test can prove no plaintext was sent.
type stub struct {
	t         *testing.T
	pub       *rsa.PublicKey
	mu        sync.Mutex
	envelopes map[string]map[string]any // id -> envelope
	exchanges int
	bodies    []string
	revokeNext bool
}

func newStub(t *testing.T, f fixture) (*stub, *httptest.Server) {
	key, err := kcrypto.ParsePrivateKey(f.PrivateKeyPem)
	if err != nil {
		t.Fatal(err)
	}
	var env map[string]any
	if err := json.Unmarshal(f.Envelope, &env); err != nil {
		t.Fatal(err)
	}
	s := &stub{t: t, pub: &key.PublicKey, envelopes: map[string]map[string]any{"sec-cli-fixture": env}}
	srv := httptest.NewServer(s)
	t.Cleanup(srv.Close)
	return s, srv
}

const webroot = "/index.php"

func (s *stub) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	s.mu.Lock()
	defer s.mu.Unlock()
	body, _ := io.ReadAll(r.Body)
	if len(body) > 0 {
		s.bodies = append(s.bodies, string(body))
	}
	p := r.URL.Path
	api := webroot + "/apps/keepiq/api/v1/app/secrets"
	switch {
	case p == webroot+DiscoveryPath:
		s.json(w, 200, map[string]any{
			"apiVersion":    1,
			"tokenEndpoint": webroot + "/apps/keepiq/api/v1/app/token",
			"grantType":     "urn:ietf:params:oauth:grant-type:jwt-bearer",
			"assertion":     map[string]any{"alg": "RS256", "audience": "keepiq"},
			"secrets": map[string]any{
				"list": api, "byId": api + "/{id}", "byName": api + "/by-name/{name}",
				"create": api, "update": api + "/{id}",
			},
		})
		return
	case p == webroot+"/apps/keepiq/api/v1/app/token" && r.Method == http.MethodPost:
		vals, _ := urlValues(string(body))
		if vals["grant_type"] != "urn:ietf:params:oauth:grant-type:jwt-bearer" || !s.validAssertion(vals["assertion"]) {
			s.json(w, 401, map[string]any{"error": "invalid_grant"})
			return
		}
		s.exchanges++
		s.json(w, 200, map[string]any{"access_token": fmt.Sprintf("tok-%d", s.exchanges), "token_type": "Bearer", "expires_in": 300})
		return
	}
	if r.Header.Get("Authorization") != fmt.Sprintf("Bearer tok-%d", s.exchanges) || s.exchanges == 0 || s.revokeNext {
		s.revokeNext = false
		s.json(w, 401, map[string]any{"message": "Bearer token required"})
		return
	}
	switch {
	case p == api && r.Method == http.MethodGet:
		var items []any
		since := r.URL.Query().Get("updated_since")
		for _, e := range s.envelopes {
			if since == "" || e["secret"].(map[string]any)["updatedAt"].(string) > since {
				items = append(items, e)
			}
		}
		s.json(w, 200, map[string]any{"format": "doriath-machine-secret-v1", "items": items, "total": len(items)})
	case strings.HasPrefix(p, api+"/by-name/"):
		name := strings.TrimPrefix(p, api+"/by-name/")
		var hits []map[string]any
		for _, e := range s.envelopes {
			if e["secret"].(map[string]any)["name"] == name {
				hits = append(hits, e)
			}
		}
		switch len(hits) {
		case 0:
			s.json(w, 404, map[string]any{"message": "Secret not found"})
		case 1:
			s.envelope(w, r, hits[0])
		default:
			var cands []any
			for _, h := range hits {
				m := h["secret"].(map[string]any)
				cands = append(cands, map[string]any{"id": m["id"], "name": m["name"], "folderPath": m["folderPath"], "updatedAt": m["updatedAt"]})
			}
			s.json(w, 409, map[string]any{"message": "Multiple secrets match this name", "candidates": cands})
		}
	case p == api && r.Method == http.MethodPost:
		var in map[string]string
		_ = json.Unmarshal(body, &in)
		id := fmt.Sprintf("sec-new-%d", len(s.envelopes))
		e := s.newEnvelope(id, in)
		s.envelopes[id] = e
		s.json(w, 201, e)
	case strings.HasPrefix(p, api+"/") && r.Method == http.MethodPut:
		id := strings.TrimPrefix(p, api+"/")
		e, ok := s.envelopes[id]
		if !ok {
			s.json(w, 404, map[string]any{"message": "Secret not found"})
			return
		}
		var in map[string]string
		_ = json.Unmarshal(body, &in)
		ct := e["ciphertext"].(map[string]any)
		for _, f := range []string{"key", "login", "additionalFields"} {
			if v, ok := in[f]; ok {
				ct[f] = v
			}
		}
		e["secret"].(map[string]any)["updatedAt"] = "2026-10-02T12:00:00+00:00"
		s.envelope(w, r, e)
	case strings.HasPrefix(p, api+"/") && r.Method == http.MethodGet:
		e, ok := s.envelopes[strings.TrimPrefix(p, api+"/")]
		if !ok {
			s.json(w, 404, map[string]any{"message": "Secret not found"})
			return
		}
		s.envelope(w, r, e)
	default:
		s.json(w, 404, map[string]any{"message": "no route " + p})
	}
}

func (s *stub) newEnvelope(id string, in map[string]string) map[string]any {
	return map[string]any{
		"format": "doriath-machine-secret-v1",
		"secret": map[string]any{"id": id, "name": in["name"], "url": in["url"], "folderPath": "", "type": in["typeId"],
			"createdAt": "2026-10-02T10:00:00+00:00", "updatedAt": "2026-10-02T10:00:00+00:00", "keyUpdatedAt": "2026-10-02T10:00:00+00:00"},
		"encryption": map[string]any{"suiteId": "suite-cli-fixture", "certificateFingerprint": "sha256:81394845ca3ff63930b78428f345690b1f96a867bd644e9823e06624992457c5", "scheme": Scheme},
		"ciphertext": map[string]any{"key": in["key"], "login": in["login"], "additionalFields": in["additionalFields"]},
	}
}

func (s *stub) envelope(w http.ResponseWriter, r *http.Request, e map[string]any) {
	raw, _ := json.Marshal(e)
	sum := sha256.Sum256(raw)
	etag := fmt.Sprintf(`"%x"`, sum[:8])
	w.Header().Set("ETag", etag)
	w.Header().Set("Doriath-Lease-Id", "lease-7")
	w.Header().Set("Doriath-Lease-Expires", "2026-10-02T13:00:00+00:00")
	if r.Header.Get("If-None-Match") == etag {
		w.WriteHeader(304)
		return
	}
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(200)
	_, _ = w.Write(raw)
}

func (s *stub) json(w http.ResponseWriter, code int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(v)
}

func (s *stub) validAssertion(a string) bool {
	parts := strings.Split(a, ".")
	if len(parts) != 3 {
		return false
	}
	sig, err := base64.RawURLEncoding.DecodeString(parts[2])
	if err != nil {
		return false
	}
	digest := sha256.Sum256([]byte(parts[0] + "." + parts[1]))
	if rsa.VerifyPKCS1v15(s.pub, crypto.SHA256, digest[:], sig) != nil {
		return false
	}
	raw, _ := base64.RawURLEncoding.DecodeString(parts[1])
	var c map[string]any
	_ = json.Unmarshal(raw, &c)
	return c["iss"] == "billing" && c["sub"] == "billing" && c["aud"] == "keepiq" && c["jti"] != ""
}

func urlValues(form string) (map[string]string, error) {
	out := map[string]string{}
	for _, kv := range strings.Split(form, "&") {
		k, v, _ := strings.Cut(kv, "=")
		k, _ = urlUnescape(k)
		v, _ = urlUnescape(v)
		out[k] = v
	}
	return out, nil
}

func urlUnescape(s string) (string, error) {
	return queryUnescape(s)
}

func newClient(t *testing.T, srv *httptest.Server, f fixture, opts ...Option) *Client {
	t.Helper()
	c, err := New(srv.URL+webroot, "billing", f.PrivateKeyPem, opts...)
	if err != nil {
		t.Fatal(err)
	}
	return c
}

// A read by name decrypts the server's real envelope in this process, carries
// the metadata and the lease, and caches the token across calls.
func TestGetByNameDecryptsTheServerEnvelope(t *testing.T) {
	f := loadFixture(t)
	st, srv := newStub(t, f)
	c := newClient(t, srv, f)

	s, err := c.GetByName("ci-fixture-db-password", "")
	if err != nil {
		t.Fatal(err)
	}
	if s.Key != f.Plaintext["key"] || s.Login != f.Plaintext["login"] || s.AdditionalFields != f.Plaintext["additionalFields"] {
		t.Fatalf("decrypted %+v", s)
	}
	if s.FolderPath != "ci/database" || s.ID != "sec-cli-fixture" {
		t.Fatalf("metadata %+v", s)
	}
	if s.Lease == nil || s.Lease.ID != "lease-7" || s.Lease.Expires == "" {
		t.Fatalf("lease %+v", s.Lease)
	}
	if _, err := c.GetByID("sec-cli-fixture"); err != nil {
		t.Fatal(err)
	}
	if st.exchanges != 1 {
		t.Fatalf("token exchanged %d times, want 1 (cached)", st.exchanges)
	}
}

// A second read of an unchanged secret sends the ETag and reports not modified.
func TestUnchangedReadIsNotModified(t *testing.T) {
	f := loadFixture(t)
	_, srv := newStub(t, f)
	c := newClient(t, srv, f)
	first, err := c.GetByID("sec-cli-fixture")
	if err != nil || first.ETag == "" {
		t.Fatalf("first read: %v %+v", err, first)
	}
	if _, err := c.GetByID("sec-cli-fixture"); !errors.Is(err, ErrNotModified) {
		t.Fatalf("second read: want ErrNotModified, got %v", err)
	}
}

// Two secrets with one name: the error carries both ids and folder paths.
func TestAmbiguousNameListsCandidates(t *testing.T) {
	f := loadFixture(t)
	st, srv := newStub(t, f)
	twin := st.newEnvelope("sec-twin", map[string]string{"name": "ci-fixture-db-password"})
	twin["secret"].(map[string]any)["folderPath"] = "other"
	st.envelopes["sec-twin"] = twin
	c := newClient(t, srv, f)

	_, err := c.GetByName("ci-fixture-db-password", "")
	var amb *AmbiguousNameError
	if !errors.As(err, &amb) {
		t.Fatalf("want *AmbiguousNameError, got %v", err)
	}
	got := map[string]string{}
	for _, cand := range amb.Candidates {
		got[cand.ID] = cand.FolderPath
	}
	if got["sec-cli-fixture"] != "ci/database" || got["sec-twin"] != "other" || len(got) != 2 {
		t.Fatalf("candidates %+v", amb.Candidates)
	}
}

func TestNotFoundIsTyped(t *testing.T) {
	f := loadFixture(t)
	_, srv := newStub(t, f)
	c := newClient(t, srv, f)
	if _, err := c.GetByName("nope", ""); !errors.Is(err, ErrNotFound) {
		t.Fatalf("want ErrNotFound, got %v", err)
	}
}

// A key the server does not know is refused at the token exchange.
func TestWrongKeyIsUnauthorized(t *testing.T) {
	f := loadFixture(t)
	_, srv := newStub(t, f)
	other, _ := rsa.GenerateKey(randReader(), 2048)
	c, err := New(srv.URL+webroot, "billing", pemPKCS1(other))
	if err != nil {
		t.Fatal(err)
	}
	if _, err := c.GetByID("sec-cli-fixture"); !errors.Is(err, ErrUnauthorized) {
		t.Fatalf("want ErrUnauthorized, got %v", err)
	}
}

// A token the server stops honouring is replaced once, transparently.
func TestRevokedTokenIsRenewedOnce(t *testing.T) {
	f := loadFixture(t)
	st, srv := newStub(t, f)
	c := newClient(t, srv, f)
	if _, err := c.GetByID("sec-cli-fixture"); err != nil {
		t.Fatal(err)
	}
	st.revokeNext = true
	if _, err := c.List(time.Time{}); err != nil {
		t.Fatalf("list after revoke: %v", err)
	}
	if st.exchanges != 2 {
		t.Fatalf("exchanges = %d, want 2", st.exchanges)
	}
}

// Create and update send only ciphertext for the value fields, and a later
// read returns the new value: the rotation scenario of the spec.
func TestWritesSendOnlyCiphertext(t *testing.T) {
	f := loadFixture(t)
	st, srv := newStub(t, f)
	c := newClient(t, srv, f)

	created, err := c.Create(map[string]string{"name": "stripe-key", "key": "sk_live_first", "login": "billing-bot"})
	if err != nil {
		t.Fatal(err)
	}
	if created.Key != "sk_live_first" || created.Login != "billing-bot" {
		t.Fatalf("created %+v", created)
	}
	if _, err := c.Update(created.ID, map[string]string{"key": "YOUR_TOKEN_HERE"}); err != nil {
		t.Fatal(err)
	}
	for _, b := range st.bodies {
		for _, plain := range []string{"sk_live_first", "billing-bot", "YOUR_TOKEN_HERE", "PRIVATE KEY"} {
			if strings.Contains(b, plain) {
				t.Fatalf("request body carries %q: %s", plain, b)
			}
		}
	}
	got, err := c.GetByID(created.ID)
	if err != nil {
		t.Fatal(err)
	}
	if got.Key != "YOUR_TOKEN_HERE" || got.Login != "billing-bot" {
		t.Fatalf("after update %+v", got)
	}
	if _, err := c.Create(map[string]string{"name": "x", "password": "y"}); err == nil {
		t.Fatal("an unknown field must be refused, not sent")
	}
}

func TestListFiltersOnUpdatedSince(t *testing.T) {
	f := loadFixture(t)
	st, srv := newStub(t, f)
	st.envelopes["sec-new"] = st.newEnvelope("sec-new", map[string]string{"name": "n"})
	c := newClient(t, srv, f)
	all, err := c.List(time.Time{})
	if err != nil || len(all) != 2 {
		t.Fatalf("all: %v %d", err, len(all))
	}
	recent, err := c.List(time.Date(2026, 10, 1, 0, 0, 0, 0, time.UTC))
	if err != nil || len(recent) != 1 || recent[0].ID != "sec-new" {
		t.Fatalf("recent: %v %+v", err, recent)
	}
}

// With the certificate configured, an envelope encrypted to another
// certificate is refused before any decryption.
func TestFingerprintMismatchIsRefused(t *testing.T) {
	f := loadFixture(t)
	st, srv := newStub(t, f)
	st.envelopes["sec-cli-fixture"]["encryption"].(map[string]any)["certificateFingerprint"] = "sha256:00"
	c := newClient(t, srv, f, WithCertificate(f.CertificatePem))
	if _, err := c.GetByID("sec-cli-fixture"); !errors.Is(err, ErrKeyMismatch) {
		t.Fatalf("want ErrKeyMismatch, got %v", err)
	}
	other, _ := rsa.GenerateKey(randReader(), 2048)
	if _, err := New(srv.URL, "billing", pemPKCS1(other), WithCertificate(f.CertificatePem)); err == nil {
		t.Fatal("a certificate that does not belong to the key must be refused")
	}
}

// The Go-encrypted vector in sdk/testdata decrypts here; PHPUnit
// (tests/Unit/Service/SdkEncryptedVectorsTest.php) decrypts the same file
// with DecryptService. KEEPIQ_WRITE_VECTORS=1 rewrites it.
func TestGoEncryptedVector(t *testing.T) {
	f := loadFixture(t)
	key, _ := kcrypto.ParsePrivateKey(f.PrivateKeyPem)
	const path = "../testdata/encrypted_by_go.json"
	if os.Getenv("KEEPIQ_WRITE_VECTORS") == "1" {
		writeVector(t, path, "Go (sdk/go)", &key.PublicKey)
	}
	checkVector(t, path, key)
}

// Every library's encrypted vector decrypts in Go too. A missing file is
// skipped here: the producing library's own test fails on it.
func TestEveryLibraryVector(t *testing.T) {
	f := loadFixture(t)
	key, _ := kcrypto.ParsePrivateKey(f.PrivateKeyPem)
	for _, lang := range []string{"go", "python", "js"} {
		path := "../testdata/encrypted_by_" + lang + ".json"
		if _, err := os.Stat(path); err != nil {
			continue
		}
		checkVector(t, path, key)
	}
}
