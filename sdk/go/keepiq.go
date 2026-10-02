// Package keepiq is the Go client library for the Keepiq machine API.
//
// An approved application reads and writes the secrets in its own vault with
// its own RSA key pair. The library discovers the instance, signs the RFC 7523
// assertion, caches the bearer token, and decrypts and encrypts every value in
// this process: only ciphertext and a signed assertion cross the network, and
// the private key never leaves the caller.
//
//	c, err := keepiq.New("https://cloud.example.org", "billing", pemString)
//	s, err := c.GetByName("stripe-key", "")
//	fmt.Println(s.Key)
//
// The base URL is the Nextcloud address the CLI uses too: when the instance has
// no pretty URLs, include `/index.php`.
//
// The same surface exists in Python (sdk/python) and TypeScript (sdk/js), and
// all three pass the vectors in sdk/testdata.
package keepiq

import (
	"bytes"
	"crypto/rand"
	"crypto/rsa"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"

	kcrypto "github.com/ConductionNL/keepiq/sdk/go/crypto"
)

// Scheme is the only ciphertext scheme the library reads and writes.
const Scheme = "rsa-oaep-sha256-chunked-v1"

// DiscoveryPath is the machine API discovery document, relative to the base URL.
const DiscoveryPath = "/apps/keepiq/api/v1/app/.well-known/keepiq"

// Fields a write may carry. The secret fields are encrypted before sending;
// the metadata fields are sent as they are and must not hold secrets.
var (
	secretFields   = []string{"key", "login", "additionalFields"}
	metadataFields = []string{"name", "url", "typeId"}
)

// Typed errors. Use errors.Is for the sentinels and errors.As for
// *AmbiguousNameError and *APIError.
var (
	// ErrNotFound is returned for a secret that does not exist in the
	// application's vault (the server does not say whether it exists elsewhere).
	ErrNotFound = errors.New("keepiq: secret not found")
	// ErrUnauthorized is returned when the token exchange or a call with a
	// fresh token is refused: an unknown, unapproved or revoked application,
	// or a key that is not the registered one.
	ErrUnauthorized = errors.New("keepiq: unauthorized")
	// ErrNotModified is returned by a read when the secret is unchanged since
	// the last read of the same address (the server answered 304 to the ETag).
	ErrNotModified = errors.New("keepiq: not modified")
	// ErrKeyMismatch is returned when the envelope was encrypted to a
	// certificate other than the one configured with WithCertificate.
	ErrKeyMismatch = errors.New("keepiq: the envelope is encrypted to a different certificate")
)

// Candidate is one of several secrets that share a name.
type Candidate struct {
	ID         string `json:"id"`
	Name       string `json:"name"`
	FolderPath string `json:"folderPath"`
	UpdatedAt  string `json:"updatedAt"`
}

// AmbiguousNameError is returned on 409 when a name matches several secrets.
// Narrow the read with a folder path, or rename one of them.
type AmbiguousNameError struct {
	Name       string
	Candidates []Candidate
}

func (e *AmbiguousNameError) Error() string {
	parts := make([]string, 0, len(e.Candidates))
	for _, c := range e.Candidates {
		parts = append(parts, fmt.Sprintf("%s (%s)", c.ID, folderLabel(c.FolderPath)))
	}
	return fmt.Sprintf("keepiq: %d secrets are named %q: %s", len(e.Candidates), e.Name, strings.Join(parts, ", "))
}

func folderLabel(path string) string {
	if path == "" {
		return "/"
	}
	return path
}

// APIError is any other non-success answer.
type APIError struct {
	Status  int
	Message string
}

func (e *APIError) Error() string {
	return fmt.Sprintf("keepiq: server answered %d: %s", e.Status, e.Message)
}

// Lease is the machine lease the server attached to a read, when the instance
// runs leases. Empty when it does not.
type Lease struct {
	ID      string
	Expires string // ISO 8601, as the server sends it
}

// ExpiresAt parses Expires; the zero time when it is empty or malformed.
func (l *Lease) ExpiresAt() time.Time {
	t, err := time.Parse(time.RFC3339, l.Expires)
	if err != nil {
		return time.Time{}
	}
	return t
}

// Secret is one decrypted secret with its metadata.
type Secret struct {
	ID           string
	Name         string
	URL          string
	FolderPath   string
	Type         string
	CreatedAt    string
	UpdatedAt    string
	KeyUpdatedAt string

	// Decrypted values.
	Key              string
	Login            string
	AdditionalFields string

	// ETag of this version; the next read of the same address sends it.
	ETag string
	// Lease is set when the server returned Doriath-Lease-* headers.
	Lease *Lease
}

// Option configures a Client.
type Option func(*Client)

// WithHTTPClient replaces the default HTTP client (30 second timeout).
func WithHTTPClient(h *http.Client) Option { return func(c *Client) { c.http = h } }

// WithCertificate sets the application's certificate (PEM). New checks that it
// belongs to the private key, and every read then refuses an envelope whose
// certificate fingerprint differs, before any decryption, with ErrKeyMismatch.
func WithCertificate(pem string) Option { return func(c *Client) { c.certPEM = pem } }

// WithClock replaces time.Now, for tests.
func WithClock(now func() time.Time) Option { return func(c *Client) { c.now = now } }

// Client talks to one Keepiq instance as one application. It is safe for
// concurrent use.
type Client struct {
	base          *url.URL
	baseURL       string
	applicationID string
	key           *rsa.PrivateKey
	certPEM       string
	fingerprint   string
	http          *http.Client
	now           func() time.Time

	mu          sync.Mutex
	disc        *discovery
	token       string
	tokenExpiry time.Time
	etags       map[string]string
}

type discovery struct {
	TokenEndpoint string `json:"tokenEndpoint"`
	GrantType     string `json:"grantType"`
	Assertion     struct {
		Audience string `json:"audience"`
	} `json:"assertion"`
	Lease struct {
		Supported bool `json:"supported"`
	} `json:"lease"`
	Secrets struct {
		List   string `json:"list"`
		ByID   string `json:"byId"`
		ByName string `json:"byName"`
		Create string `json:"create"`
		Update string `json:"update"`
	} `json:"secrets"`
}

// New builds a client for application applicationID with its private key PEM
// (PKCS#8 or PKCS#1). It does not contact the server yet.
func New(baseURL, applicationID, privateKeyPEM string, opts ...Option) (*Client, error) {
	base, err := url.Parse(strings.TrimRight(baseURL, "/"))
	if err != nil || base.Scheme == "" || base.Host == "" {
		return nil, fmt.Errorf("keepiq: invalid base URL %q", baseURL)
	}
	if applicationID == "" {
		return nil, errors.New("keepiq: application id is required")
	}
	key, err := kcrypto.ParsePrivateKey(privateKeyPEM)
	if err != nil {
		return nil, fmt.Errorf("keepiq: %w", err)
	}
	c := &Client{
		base:          base,
		baseURL:       strings.TrimRight(baseURL, "/"),
		applicationID: applicationID,
		key:           key,
		http:          &http.Client{Timeout: 30 * time.Second},
		now:           time.Now,
		etags:         map[string]string{},
	}
	for _, o := range opts {
		o(c)
	}
	if c.certPEM != "" {
		if err := kcrypto.PublicKeyMatchesPrivate(c.certPEM, key); err != nil {
			return nil, fmt.Errorf("keepiq: %w", err)
		}
		if c.fingerprint, err = kcrypto.CertificateFingerprint(c.certPEM); err != nil {
			return nil, fmt.Errorf("keepiq: %w", err)
		}
	}
	return c, nil
}

// GetByName reads the secret with exactly this name. folder narrows the match
// to a slash-separated folder path; pass "" for the whole vault.
func (c *Client) GetByName(name, folder string) (*Secret, error) {
	d, err := c.discover()
	if err != nil {
		return nil, err
	}
	addr := c.endpoint(d.Secrets.ByName, "/apps/keepiq/api/v1/app/secrets/by-name/{name}", "{name}", name)
	if folder != "" {
		addr += "?folder=" + url.QueryEscape(folder)
	}
	return c.read(addr, name)
}

// GetByNameIfNoneMatch reads by name with an ETag the caller kept (for
// example in a resource status), instead of the one this client remembers.
// An empty etag reads unconditionally. Returns ErrNotModified on 304.
func (c *Client) GetByNameIfNoneMatch(name, folder, etag string) (*Secret, error) {
	d, err := c.discover()
	if err != nil {
		return nil, err
	}
	addr := c.endpoint(d.Secrets.ByName, "/apps/keepiq/api/v1/app/secrets/by-name/{name}", "{name}", name)
	if folder != "" {
		addr += "?folder=" + url.QueryEscape(folder)
	}
	return c.readWith(addr, name, etag)
}

// LeaseSupported reports whether the instance advertises machine leases.
func (c *Client) LeaseSupported() (bool, error) {
	d, err := c.discover()
	if err != nil {
		return false, err
	}
	return d.Lease.Supported, nil
}

// GetByID reads one secret by id.
func (c *Client) GetByID(id string) (*Secret, error) {
	d, err := c.discover()
	if err != nil {
		return nil, err
	}
	return c.read(c.endpoint(d.Secrets.ByID, "/apps/keepiq/api/v1/app/secrets/{id}", "{id}", id), id)
}

// List reads every secret in the vault, or, with a non-zero updatedSince, only
// the secrets updated strictly after that instant.
func (c *Client) List(updatedSince time.Time) ([]*Secret, error) {
	d, err := c.discover()
	if err != nil {
		return nil, err
	}
	addr := c.endpoint(d.Secrets.List, "/apps/keepiq/api/v1/app/secrets")
	if !updatedSince.IsZero() {
		addr += "?updated_since=" + url.QueryEscape(updatedSince.UTC().Format(time.RFC3339))
	}
	resp, body, err := c.do(http.MethodGet, addr, nil, "")
	if err != nil {
		return nil, err
	}
	if resp.StatusCode != http.StatusOK {
		return nil, c.statusError(resp.StatusCode, body, "")
	}
	var page struct {
		Items []envelope `json:"items"`
	}
	if err := json.Unmarshal(body, &page); err != nil {
		return nil, fmt.Errorf("keepiq: decode list: %w", err)
	}
	out := make([]*Secret, 0, len(page.Items))
	for i := range page.Items {
		s, err := c.open(&page.Items[i])
		if err != nil {
			return nil, err
		}
		out = append(out, s)
	}
	return out, nil
}

// Create files a new secret in the application's vault. fields takes "name"
// (required), "url" and "typeId" as metadata, and "key" (required), "login"
// and "additionalFields" as values, which are encrypted here first.
func (c *Client) Create(fields map[string]string) (*Secret, error) {
	d, err := c.discover()
	if err != nil {
		return nil, err
	}
	payload, err := c.writePayload(fields)
	if err != nil {
		return nil, err
	}
	return c.write(http.MethodPost, c.endpoint(d.Secrets.Create, "/apps/keepiq/api/v1/app/secrets"), payload, http.StatusCreated)
}

// Update replaces the given fields of one secret. Values are encrypted here
// first; fields not named stay as they are.
func (c *Client) Update(id string, fields map[string]string) (*Secret, error) {
	d, err := c.discover()
	if err != nil {
		return nil, err
	}
	payload, err := c.writePayload(fields)
	if err != nil {
		return nil, err
	}
	addr := c.endpoint(d.Secrets.Update, "/apps/keepiq/api/v1/app/secrets/{id}", "{id}", id)
	return c.write(http.MethodPut, addr, payload, http.StatusOK)
}

// --- internals ---

type envelope struct {
	Format string `json:"format"`
	Secret struct {
		ID           string `json:"id"`
		Name         string `json:"name"`
		URL          string `json:"url"`
		FolderPath   string `json:"folderPath"`
		Type         string `json:"type"`
		CreatedAt    string `json:"createdAt"`
		UpdatedAt    string `json:"updatedAt"`
		KeyUpdatedAt string `json:"keyUpdatedAt"`
	} `json:"secret"`
	Encryption struct {
		SuiteID                string `json:"suiteId"`
		CertificateFingerprint string `json:"certificateFingerprint"`
		Scheme                 string `json:"scheme"`
	} `json:"encryption"`
	Ciphertext struct {
		Key              *string `json:"key"`
		Login            *string `json:"login"`
		AdditionalFields *string `json:"additionalFields"`
	} `json:"ciphertext"`
}

func (c *Client) writePayload(fields map[string]string) (map[string]string, error) {
	pub := &c.key.PublicKey
	out := map[string]string{}
	for name, value := range fields {
		switch {
		case contains(secretFields, name):
			ct, err := kcrypto.EncryptField(value, pub)
			if err != nil {
				return nil, fmt.Errorf("keepiq: encrypt %s: %w", name, err)
			}
			out[name] = ct
		case contains(metadataFields, name):
			out[name] = value
		default:
			return nil, fmt.Errorf("keepiq: unknown field %q (allowed: %s, %s)", name,
				strings.Join(metadataFields, ", "), strings.Join(secretFields, ", "))
		}
	}
	return out, nil
}

func (c *Client) read(addr, label string) (*Secret, error) {
	c.mu.Lock()
	etag := c.etags[addr]
	c.mu.Unlock()
	return c.readWith(addr, label, etag)
}

func (c *Client) readWith(addr, label, etag string) (*Secret, error) {
	resp, body, err := c.do(http.MethodGet, addr, nil, etag)
	if err != nil {
		return nil, err
	}
	if resp.StatusCode == http.StatusNotModified {
		return nil, ErrNotModified
	}
	if resp.StatusCode != http.StatusOK {
		return nil, c.statusError(resp.StatusCode, body, label)
	}
	s, err := c.decodeOne(resp, body)
	if err != nil {
		return nil, err
	}
	if s.ETag != "" {
		c.mu.Lock()
		c.etags[addr] = s.ETag
		c.mu.Unlock()
	}
	return s, nil
}

func (c *Client) write(method, addr string, payload map[string]string, want int) (*Secret, error) {
	raw, _ := json.Marshal(payload)
	resp, body, err := c.do(method, addr, raw, "")
	if err != nil {
		return nil, err
	}
	if resp.StatusCode != want {
		return nil, c.statusError(resp.StatusCode, body, "")
	}
	return c.decodeOne(resp, body)
}

func (c *Client) decodeOne(resp *http.Response, body []byte) (*Secret, error) {
	var env envelope
	if err := json.Unmarshal(body, &env); err != nil {
		return nil, fmt.Errorf("keepiq: decode envelope: %w", err)
	}
	s, err := c.open(&env)
	if err != nil {
		return nil, err
	}
	s.ETag = resp.Header.Get("ETag")
	if id := resp.Header.Get("Doriath-Lease-Id"); id != "" {
		s.Lease = &Lease{ID: id, Expires: resp.Header.Get("Doriath-Lease-Expires")}
	}
	return s, nil
}

// open checks the envelope and decrypts it in this process.
func (c *Client) open(env *envelope) (*Secret, error) {
	if env.Encryption.Scheme != Scheme {
		return nil, fmt.Errorf("keepiq: unsupported encryption scheme %q", env.Encryption.Scheme)
	}
	if c.fingerprint != "" && env.Encryption.CertificateFingerprint != "" &&
		!strings.EqualFold(env.Encryption.CertificateFingerprint, c.fingerprint) {
		return nil, ErrKeyMismatch
	}
	s := &Secret{
		ID: env.Secret.ID, Name: env.Secret.Name, URL: env.Secret.URL,
		FolderPath: env.Secret.FolderPath, Type: env.Secret.Type,
		CreatedAt: env.Secret.CreatedAt, UpdatedAt: env.Secret.UpdatedAt, KeyUpdatedAt: env.Secret.KeyUpdatedAt,
	}
	for _, f := range []struct {
		ct  *string
		dst *string
		n   string
	}{
		{env.Ciphertext.Key, &s.Key, "key"},
		{env.Ciphertext.Login, &s.Login, "login"},
		{env.Ciphertext.AdditionalFields, &s.AdditionalFields, "additionalFields"},
	} {
		if f.ct == nil || *f.ct == "" {
			continue
		}
		pt, err := kcrypto.DecryptField(*f.ct, c.key)
		if err != nil {
			return nil, fmt.Errorf("keepiq: decrypt %s of %s: %w", f.n, s.ID, err)
		}
		*f.dst = pt
	}
	return s, nil
}

func (c *Client) statusError(status int, body []byte, label string) error {
	var msg struct {
		Message    string      `json:"message"`
		Candidates []Candidate `json:"candidates"`
	}
	_ = json.Unmarshal(body, &msg)
	switch status {
	case http.StatusNotFound:
		return ErrNotFound
	case http.StatusUnauthorized, http.StatusForbidden:
		return fmt.Errorf("%w: %s", ErrUnauthorized, msg.Message)
	case http.StatusConflict:
		if msg.Candidates != nil {
			return &AmbiguousNameError{Name: label, Candidates: msg.Candidates}
		}
	}
	if msg.Message == "" {
		msg.Message = strings.TrimSpace(string(body))
	}
	return &APIError{Status: status, Message: msg.Message}
}

// do sends one authenticated request. A 401 drops the cached token and retries
// once with a fresh one, so a token revoked or expired early does not fail a
// long-running consumer.
func (c *Client) do(method, addr string, body []byte, etag string) (*http.Response, []byte, error) {
	for attempt := 0; ; attempt++ {
		token, err := c.bearer()
		if err != nil {
			return nil, nil, err
		}
		var rd io.Reader
		if body != nil {
			rd = bytes.NewReader(body)
		}
		req, err := http.NewRequest(method, addr, rd)
		if err != nil {
			return nil, nil, err
		}
		req.Header.Set("Authorization", "Bearer "+token)
		req.Header.Set("Accept", "application/json")
		if body != nil {
			req.Header.Set("Content-Type", "application/json")
		}
		if etag != "" {
			req.Header.Set("If-None-Match", etag)
		}
		resp, err := c.http.Do(req)
		if err != nil {
			return nil, nil, err
		}
		data, err := io.ReadAll(resp.Body)
		resp.Body.Close()
		if err != nil {
			return nil, nil, err
		}
		if resp.StatusCode == http.StatusUnauthorized && attempt == 0 {
			c.mu.Lock()
			c.token = ""
			c.mu.Unlock()
			continue
		}
		return resp, data, nil
	}
}

func (c *Client) discover() (*discovery, error) {
	c.mu.Lock()
	d := c.disc
	c.mu.Unlock()
	if d != nil {
		return d, nil
	}
	resp, err := c.http.Get(c.baseURL + DiscoveryPath)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	data, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != http.StatusOK {
		return nil, &APIError{Status: resp.StatusCode, Message: "discovery: " + strings.TrimSpace(string(data))}
	}
	d = &discovery{}
	if err := json.Unmarshal(data, d); err != nil {
		return nil, fmt.Errorf("keepiq: decode discovery: %w", err)
	}
	if d.TokenEndpoint == "" {
		return nil, errors.New("keepiq: discovery has no tokenEndpoint")
	}
	c.mu.Lock()
	c.disc = d
	c.mu.Unlock()
	return d, nil
}

// bearer returns the cached token, or exchanges a fresh assertion for one.
func (c *Client) bearer() (string, error) {
	d, err := c.discover()
	if err != nil {
		return "", err
	}
	now := c.now()
	c.mu.Lock()
	if c.token != "" && now.Before(c.tokenExpiry) {
		t := c.token
		c.mu.Unlock()
		return t, nil
	}
	c.mu.Unlock()

	assertion, err := c.assertion(d, now)
	if err != nil {
		return "", err
	}
	grant := d.GrantType
	if grant == "" {
		grant = "urn:ietf:params:oauth:grant-type:jwt-bearer"
	}
	form := url.Values{"grant_type": {grant}, "assertion": {assertion}}.Encode()
	req, _ := http.NewRequest(http.MethodPost, c.resolve(d.TokenEndpoint), strings.NewReader(form))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	resp, err := c.http.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	data, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != http.StatusOK {
		if resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden || resp.StatusCode == http.StatusBadRequest {
			return "", fmt.Errorf("%w: token exchange answered %d: %s", ErrUnauthorized, resp.StatusCode, strings.TrimSpace(string(data)))
		}
		return "", &APIError{Status: resp.StatusCode, Message: "token exchange: " + strings.TrimSpace(string(data))}
	}
	var tok struct {
		AccessToken string `json:"access_token"`
		ExpiresIn   int64  `json:"expires_in"`
	}
	if err := json.Unmarshal(data, &tok); err != nil || tok.AccessToken == "" {
		return "", errors.New("keepiq: token exchange returned no access_token")
	}
	life := time.Duration(tok.ExpiresIn) * time.Second
	if life <= 0 {
		life = time.Minute
	}
	// Renew a little early so a token never expires between check and use.
	margin := 30 * time.Second
	if life <= 2*margin {
		margin = life / 2
	}
	c.mu.Lock()
	c.token = tok.AccessToken
	c.tokenExpiry = now.Add(life - margin)
	c.mu.Unlock()
	return tok.AccessToken, nil
}

func (c *Client) assertion(d *discovery, now time.Time) (string, error) {
	aud := d.Assertion.Audience
	if aud == "" {
		aud = "keepiq"
	}
	jti := make([]byte, 16)
	if _, err := rand.Read(jti); err != nil {
		return "", err
	}
	claims, _ := json.Marshal(map[string]any{
		"iss": c.applicationID,
		"sub": c.applicationID,
		"aud": aud,
		"iat": now.Unix(),
		"exp": now.Unix() + 300,
		"jti": hex.EncodeToString(jti),
	})
	input := base64.RawURLEncoding.EncodeToString([]byte(`{"alg":"RS256","typ":"JWT"}`)) + "." +
		base64.RawURLEncoding.EncodeToString(claims)
	sig, err := kcrypto.SignRS256(input, c.key)
	if err != nil {
		return "", err
	}
	return input + "." + sig, nil
}

// endpoint resolves a discovery-advertised path, or the fallback when the
// instance does not advertise one, with an optional placeholder filled in
// (path-escaped) before the path is resolved.
func (c *Client) endpoint(advertised, fallback string, placeholder ...string) string {
	p := advertised
	if p == "" {
		p = fallback
	}
	if len(placeholder) == 2 {
		p = strings.Replace(p, placeholder[0], url.PathEscape(placeholder[1]), 1)
	}
	if advertised == "" {
		return c.baseURL + p
	}
	return c.resolve(p)
}

// resolve turns a discovery path into an absolute URL. Discovery paths are
// absolute paths that already carry the Nextcloud web root, so they replace
// the base URL's path rather than being appended to it.
func (c *Client) resolve(p string) string {
	if strings.HasPrefix(p, "http://") || strings.HasPrefix(p, "https://") {
		return p
	}
	ref, err := url.Parse(p)
	if err != nil {
		return c.baseURL + p
	}
	return c.base.ResolveReference(ref).String()
}

func contains(list []string, v string) bool {
	for _, x := range list {
		if x == v {
			return true
		}
	}
	return false
}
