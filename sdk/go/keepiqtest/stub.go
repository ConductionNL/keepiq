// Package keepiqtest is an in-memory Keepiq machine API for tests. TEST ONLY.
//
// It serves discovery, the RFC 7523 token exchange (checking the assertion's
// RS256 signature against the application key, as JwtAuthService does), the
// secrets routes (list with updated_since, by id, by name with 404 / envelope /
// 409 candidates, create, update) with ETags and 304, and, when enabled, the
// lease headers and lease renewal. Every request body is recorded so a test can
// prove no plaintext was sent.
//
// The default key and the first secret come from sdk/testdata/machine_envelope.json,
// the envelope the server's own serializer writes. The Go library, the
// Kubernetes operator, the rotation runner and the Terraform provider all test
// against this one stub. sdk/testdata/stub_server.py is the same stub in Python.
package keepiqtest

import (
	"crypto"
	"crypto/rsa"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"runtime"
	"sort"
	"strings"
	"sync"
	"time"

	kcrypto "github.com/ConductionNL/keepiq/sdk/go/crypto"
)

// Webroot is the path prefix the stub serves under, so clients are tested
// with a base URL that carries a Nextcloud web root.
const Webroot = "/index.php"

const api = Webroot + "/apps/keepiq/api/v1/app/secrets"

// Fixture is sdk/testdata/machine_envelope.json.
type Fixture struct {
	PrivateKeyPem  string            `json:"privateKeyPem"`
	CertificatePem string            `json:"certificatePem"`
	Plaintext      map[string]string `json:"plaintext"`
	Envelope       json.RawMessage   `json:"envelope"`
}

// LoadFixture reads sdk/testdata/machine_envelope.json relative to this file.
func LoadFixture() (*Fixture, error) {
	_, self, _, _ := runtime.Caller(0)
	raw, err := os.ReadFile(filepath.Join(filepath.Dir(self), "..", "..", "testdata", "machine_envelope.json"))
	if err != nil {
		return nil, err
	}
	var f Fixture
	if err := json.Unmarshal(raw, &f); err != nil {
		return nil, err
	}
	return &f, nil
}

// Stub is the fake Keepiq. Lock Mu before touching its fields while the server runs.
type Stub struct {
	Mu          sync.Mutex
	App         string
	Fixture     *Fixture
	Key         *rsa.PrivateKey
	Envelopes   map[string]map[string]any
	Exchanges   int
	Bodies      []string
	Requests    []string // "METHOD path" of every request
	RevokeNext  bool
	Leases      bool          // advertise and attach leases
	LeaseTTL    time.Duration // lease lifetime when Leases is on
	RefuseRenew bool          // answer 409 to a renewal
	Renewals    int
	PutCount    int // accepted PUT write-backs
	leases      map[string]time.Time
	tokens      map[string]bool
	leaseSeq    int
	updates     int
	Server      *httptest.Server
	Now         func() time.Time
}

// Start runs a stub for application app with the fixture key and the fixture
// secret ("ci-fixture-db-password", id "sec-cli-fixture"). Close it with Close.
func Start(app string) (*Stub, error) {
	f, err := LoadFixture()
	if err != nil {
		return nil, err
	}
	key, err := kcrypto.ParsePrivateKey(f.PrivateKeyPem)
	if err != nil {
		return nil, err
	}
	var env map[string]any
	if err := json.Unmarshal(f.Envelope, &env); err != nil {
		return nil, err
	}
	s := &Stub{
		App: app, Fixture: f, Key: key,
		Envelopes: map[string]map[string]any{"sec-cli-fixture": env},
		LeaseTTL:  15 * time.Minute,
		leases:    map[string]time.Time{},
		Now:       time.Now,
	}
	s.Server = httptest.NewServer(s)
	return s, nil
}

// URL is the base URL a client uses (with the web root).
func (s *Stub) URL() string { return s.Server.URL + Webroot }

// Close stops the server.
func (s *Stub) Close() { s.Server.Close() }

// Add files a secret with the given plaintext fields encrypted to the stub key
// and returns its id. Safe to call while the server runs.
func (s *Stub) Add(id, name, folderPath string, fields map[string]string) (string, error) {
	s.Mu.Lock()
	defer s.Mu.Unlock()
	env, err := s.envelopeFor(id, name, fields)
	if err != nil {
		return "", err
	}
	env["secret"].(map[string]any)["folderPath"] = folderPath
	s.Envelopes[id] = env
	return id, nil
}

// SetExpiry sets a secret's expiresAt (ISO 8601; "" for none).
func (s *Stub) SetExpiry(id, expiresAt string) {
	s.Mu.Lock()
	defer s.Mu.Unlock()
	var v any
	if expiresAt != "" {
		v = expiresAt
	}
	s.Envelopes[id]["secret"].(map[string]any)["expiresAt"] = v
}

// Plain decrypts one field of a stored secret, for assertions.
func (s *Stub) Plain(id, field string) (string, error) {
	s.Mu.Lock()
	defer s.Mu.Unlock()
	ct, _ := s.Envelopes[id]["ciphertext"].(map[string]any)[field].(string)
	if ct == "" {
		return "", nil
	}
	return kcrypto.DecryptField(ct, s.Key)
}

// ETagOf is the ETag the stub serves for a secret now.
func (s *Stub) ETagOf(id string) string {
	s.Mu.Lock()
	defer s.Mu.Unlock()
	return s.etagOf(s.Envelopes[id])
}

// SetValue rotates one field of a secret to a new plaintext, the way a
// machine client's PUT would, and moves updatedAt forward.
func (s *Stub) SetValue(id, field, plaintext string) error {
	s.Mu.Lock()
	defer s.Mu.Unlock()
	env, ok := s.Envelopes[id]
	if !ok {
		return fmt.Errorf("no secret %s", id)
	}
	ct, err := kcrypto.EncryptField(plaintext, &s.Key.PublicKey)
	if err != nil {
		return err
	}
	env["ciphertext"].(map[string]any)[field] = ct
	s.updates++
	env["secret"].(map[string]any)["updatedAt"] = fmt.Sprintf("2026-10-02T12:%02d:00+00:00", s.updates%60)
	return nil
}

func (s *Stub) envelopeFor(id, name string, fields map[string]string) (map[string]any, error) {
	ct := map[string]any{"key": nil, "login": nil, "additionalFields": nil}
	for k, v := range fields {
		enc, err := kcrypto.EncryptField(v, &s.Key.PublicKey)
		if err != nil {
			return nil, err
		}
		ct[k] = enc
	}
	return s.newEnvelope(id, map[string]any{"name": name}, ct), nil
}

func (s *Stub) newEnvelope(id string, meta map[string]any, ct map[string]any) map[string]any {
	var fixture map[string]any
	_ = json.Unmarshal(s.Fixture.Envelope, &fixture)
	enc := fixture["encryption"].(map[string]any)
	return map[string]any{
		"format": "doriath-machine-secret-v1",
		"secret": map[string]any{"id": id, "name": meta["name"], "url": meta["url"], "folderPath": "", "type": meta["typeId"],
			"createdAt": "2026-10-02T10:00:00+00:00", "updatedAt": "2026-10-02T10:00:00+00:00", "keyUpdatedAt": "2026-10-02T10:00:00+00:00", "expiresAt": nil},
		"encryption": map[string]any{"suiteId": enc["suiteId"], "certificateFingerprint": enc["certificateFingerprint"], "scheme": "rsa-oaep-sha256-chunked-v1"},
		"ciphertext": ct,
	}
}

// ServeHTTP implements the routes.
func (s *Stub) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	s.Mu.Lock()
	defer s.Mu.Unlock()
	body, _ := io.ReadAll(r.Body)
	if len(body) > 0 {
		s.Bodies = append(s.Bodies, string(body))
	}
	s.Requests = append(s.Requests, r.Method+" "+r.URL.Path)
	p := r.URL.Path
	switch {
	case p == Webroot+"/apps/keepiq/api/v1/app/.well-known/keepiq":
		writeJSON(w, 200, map[string]any{
			"apiVersion":    1,
			"tokenEndpoint": Webroot + "/apps/keepiq/api/v1/app/token",
			"grantType":     "urn:ietf:params:oauth:grant-type:jwt-bearer",
			"assertion":     map[string]any{"alg": "RS256", "audience": "keepiq"},
			"secrets": map[string]any{
				"list": api, "byId": api + "/{id}", "byName": api + "/by-name/{name}", "create": api, "update": api + "/{id}",
			},
			"lease": map[string]any{"supported": s.Leases},
		})
		return
	case p == Webroot+"/apps/keepiq/api/v1/app/token" && r.Method == http.MethodPost:
		vals, _ := url.ParseQuery(string(body))
		if vals.Get("grant_type") != "urn:ietf:params:oauth:grant-type:jwt-bearer" || !s.validAssertion(vals.Get("assertion")) {
			writeJSON(w, 401, map[string]any{"error": "invalid_grant"})
			return
		}
		s.Exchanges++
		tok := fmt.Sprintf("tok-%d", s.Exchanges)
		if s.tokens == nil {
			s.tokens = map[string]bool{}
		}
		s.tokens[tok] = true
		writeJSON(w, 200, map[string]any{"access_token": tok, "token_type": "Bearer", "expires_in": 300})
		return
	}
	// Every issued token stays valid until RevokeNext revokes them all, as on
	// the server, where several clients (or provider processes) hold tokens
	// at once.
	if !s.tokens[strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")] || s.RevokeNext {
		s.RevokeNext = false
		s.tokens = map[string]bool{}
		writeJSON(w, 401, map[string]any{"message": "Bearer token required"})
		return
	}
	leasePrefix := Webroot + "/apps/keepiq/api/v1/app/leases/"
	switch {
	case strings.HasPrefix(p, leasePrefix) && strings.HasSuffix(p, "/renew") && r.Method == http.MethodPost:
		id := strings.TrimSuffix(strings.TrimPrefix(p, leasePrefix), "/renew")
		if _, ok := s.leases[id]; !ok {
			writeJSON(w, 404, map[string]any{"message": "Lease not found"})
			return
		}
		if s.RefuseRenew {
			writeJSON(w, 409, map[string]any{"message": "Lease cannot be renewed"})
			return
		}
		s.Renewals++
		exp := s.Now().Add(s.LeaseTTL).UTC()
		s.leases[id] = exp
		writeJSON(w, 200, map[string]any{"id": id, "applicationId": s.App, "expiresAt": exp.Format(time.RFC3339), "status": "active", "renewedCount": s.Renewals})
	case p == api && r.Method == http.MethodGet:
		since := r.URL.Query().Get("updated_since")
		ids := make([]string, 0, len(s.Envelopes))
		for id := range s.Envelopes {
			ids = append(ids, id)
		}
		sort.Strings(ids)
		var items []any
		for _, id := range ids {
			e := s.Envelopes[id]
			if since == "" || e["secret"].(map[string]any)["updatedAt"].(string) > since {
				items = append(items, e)
			}
		}
		writeJSON(w, 200, map[string]any{"format": "doriath-machine-secret-v1", "items": items, "total": len(items)})
	case strings.HasPrefix(p, api+"/by-name/"):
		name, _ := url.PathUnescape(strings.TrimPrefix(p, api+"/by-name/"))
		folder := r.URL.Query().Get("folder")
		var hits []map[string]any
		ids := make([]string, 0, len(s.Envelopes))
		for id := range s.Envelopes {
			ids = append(ids, id)
		}
		sort.Strings(ids)
		for _, id := range ids {
			e := s.Envelopes[id]
			m := e["secret"].(map[string]any)
			if m["name"] == name && (folder == "" || m["folderPath"] == strings.Trim(folder, "/")) {
				hits = append(hits, e)
			}
		}
		switch len(hits) {
		case 0:
			writeJSON(w, 404, map[string]any{"message": "Secret not found"})
		case 1:
			s.envelope(w, r, hits[0])
		default:
			var cands []any
			for _, h := range hits {
				m := h["secret"].(map[string]any)
				cands = append(cands, map[string]any{"id": m["id"], "name": m["name"], "folderPath": m["folderPath"], "updatedAt": m["updatedAt"]})
			}
			writeJSON(w, 409, map[string]any{"message": "Multiple secrets match this name; disambiguate by folder or rename", "candidates": cands})
		}
	case p == api && r.Method == http.MethodPost:
		var in map[string]any
		_ = json.Unmarshal(body, &in)
		if in["name"] == nil || in["name"] == "" {
			writeJSON(w, 400, map[string]any{"message": "A secret requires a name and a key"})
			return
		}
		id := fmt.Sprintf("sec-new-%d", len(s.Envelopes))
		ct := map[string]any{"key": in["key"], "login": in["login"], "additionalFields": in["additionalFields"]}
		e := s.newEnvelope(id, in, ct)
		s.Envelopes[id] = e
		writeJSON(w, 201, e)
	case strings.HasPrefix(p, api+"/") && r.Method == http.MethodPut:
		id, _ := url.PathUnescape(strings.TrimPrefix(p, api+"/"))
		e, ok := s.Envelopes[id]
		if !ok {
			writeJSON(w, 404, map[string]any{"message": "Secret not found"})
			return
		}
		if im := r.Header.Get("If-Match"); im != "" && im != s.etagOf(e) {
			w.Header().Set("ETag", s.etagOf(e))
			writeJSON(w, 412, map[string]any{"message": "The secret changed since it was read"})
			return
		}
		s.PutCount++
		var in map[string]any
		_ = json.Unmarshal(body, &in)
		ct := e["ciphertext"].(map[string]any)
		for _, f := range []string{"key", "login", "additionalFields"} {
			if v, ok := in[f]; ok {
				ct[f] = v
			}
		}
		meta := e["secret"].(map[string]any)
		for _, f := range []string{"name", "url"} {
			if v, ok := in[f]; ok {
				meta[f] = v
			}
		}
		s.updates++
		meta["updatedAt"] = fmt.Sprintf("2026-10-02T12:%02d:00+00:00", s.updates%60)
		s.envelope(w, r, e)
	case strings.HasPrefix(p, api+"/") && r.Method == http.MethodGet:
		id, _ := url.PathUnescape(strings.TrimPrefix(p, api+"/"))
		e, ok := s.Envelopes[id]
		if !ok {
			writeJSON(w, 404, map[string]any{"message": "Secret not found"})
			return
		}
		s.envelope(w, r, e)
	default:
		writeJSON(w, 404, map[string]any{"message": "no route " + p})
	}
}

func (s *Stub) etagOf(e map[string]any) string {
	raw, _ := json.Marshal(e)
	sum := sha256.Sum256(raw)
	return fmt.Sprintf(`"%x"`, sum[:8])
}

func (s *Stub) envelope(w http.ResponseWriter, r *http.Request, e map[string]any) {
	raw, _ := json.Marshal(e)
	etag := s.etagOf(e)
	w.Header().Set("ETag", etag)
	if s.Leases {
		s.leaseSeq++
		id := fmt.Sprintf("lease-%d", s.leaseSeq)
		exp := s.Now().Add(s.LeaseTTL).UTC()
		s.leases[id] = exp
		w.Header().Set("Doriath-Lease-Id", id)
		w.Header().Set("Doriath-Lease-Expires", exp.Format(time.RFC3339))
	}
	if r.Header.Get("If-None-Match") == etag {
		w.WriteHeader(304)
		return
	}
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(200)
	_, _ = w.Write(raw)
}

func writeJSON(w http.ResponseWriter, code int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(v)
}

func (s *Stub) validAssertion(a string) bool {
	parts := strings.Split(a, ".")
	if len(parts) != 3 {
		return false
	}
	sig, err := base64.RawURLEncoding.DecodeString(parts[2])
	if err != nil {
		return false
	}
	digest := sha256.Sum256([]byte(parts[0] + "." + parts[1]))
	if rsa.VerifyPKCS1v15(&s.Key.PublicKey, crypto.SHA256, digest[:], sig) != nil {
		return false
	}
	raw, _ := base64.RawURLEncoding.DecodeString(parts[1])
	var c map[string]any
	_ = json.Unmarshal(raw, &c)
	return c["iss"] == s.App && c["sub"] == s.App && c["aud"] == "keepiq" && c["jti"] != ""
}

// BodiesContain reports whether any recorded request body contains needle.
func (s *Stub) BodiesContain(needle string) bool {
	s.Mu.Lock()
	defer s.Mu.Unlock()
	for _, b := range s.Bodies {
		if strings.Contains(b, needle) {
			return true
		}
	}
	return false
}
