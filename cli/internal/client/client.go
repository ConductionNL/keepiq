// Package client is Keepiq's shared HTTP client (keepiq-cli §1.2) for both
// the human session API (Nextcloud app-password auth) and the CI machine
// secret-store API (RFC 7523 JWT bearer). It never transmits the master
// password or any derived key — those stay in the CLI process (§3.2).
package client

import (
	"bytes"
	"crypto/rsa"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"

	dcrypto "github.com/ConductionNL/keepiq/cli/internal/crypto"
)

// ErrNotModified is returned by a conditional fetch when the server answers 304
// (the secret is unchanged since the last observed ETag) — the caller keeps its
// cached value (§4.2, poll loops).
var ErrNotModified = errors.New("not modified")

// Client talks to one Keepiq instance.
type Client struct {
	BaseURL    string
	HTTP       *http.Client
	appUser    string // Nextcloud user (human mode)
	appPass    string // Nextcloud app-password (human mode)
	leaseID    string // last observed machine lease id (§4.4)
	leaseUntil string // last observed lease expiry
	lastETag   string // last observed secret ETag (§4.2, conditional re-fetch)
}

// New builds a client with sane timeouts.
func New(baseURL string) *Client {
	return &Client{
		BaseURL: strings.TrimRight(baseURL, "/"),
		HTTP:    &http.Client{Timeout: 30 * time.Second},
	}
}

// WithAppPassword sets the human-mode Nextcloud credentials (an app-password,
// never the login password, §3.1).
func (c *Client) WithAppPassword(user, appPassword string) {
	c.appUser = user
	c.appPass = appPassword
}

// LeaseID / LeaseExpires expose the last machine lease headers (§4.4).
func (c *Client) LeaseID() string      { return c.leaseID }
func (c *Client) LeaseExpires() string { return c.leaseUntil }

// LastETag exposes the ETag of the last fetched secret (§4.2).
func (c *Client) LastETag() string { return c.lastETag }

// Suite is the active EncryptionSuite blob the human unlock needs.
type Suite struct {
	ID          string `json:"id"`
	Certificate string `json:"certificate"`
	PrivateKey  string `json:"privateKey"`
	Status      string `json:"status"`
}

// Secret is one vault secret (ciphertext fields until decrypted locally).
type Secret struct {
	ID               string `json:"id"`
	Name             string `json:"name"`
	URL              string `json:"url"`
	TypeID           string `json:"typeId"`
	FolderID         string `json:"folderId"`
	Key              string `json:"key"`
	Login            string `json:"login"`
	AdditionalFields string `json:"additionalFields"`
}

// ActiveSuite fetches the caller's active suite (human mode).
func (c *Client) ActiveSuite() (*Suite, error) {
	var suites []Suite
	if err := c.getJSON("/apps/keepiq/api/v1/suites", &suites); err != nil {
		return nil, err
	}
	for i := range suites {
		if suites[i].Status == "active" {
			return &suites[i], nil
		}
	}
	return nil, fmt.Errorf("no active encryption suite")
}

// ListSecrets fetches the caller's secret list (metadata + ciphertext).
func (c *Client) ListSecrets() ([]Secret, error) {
	var page struct {
		Items []Secret `json:"items"`
	}
	if err := c.getJSON("/apps/keepiq/api/v1/secrets?limit=100000", &page); err != nil {
		return nil, err
	}
	return page.Items, nil
}

// GetSecret fetches one secret by id (human mode).
func (c *Client) GetSecret(id string) (*Secret, error) {
	var s Secret
	if err := c.getJSON("/apps/keepiq/api/v1/secrets/"+id, &s); err != nil {
		return nil, err
	}
	return &s, nil
}

// --- CI mode (RFC 7523 machine secret store) ---

// Discovery is the machine-store discovery document (subset used by the CLI).
// The field names mirror the server's DiscoveryController.document() shape
// (camelCase, nested) so the CLI self-configures from discovery alone (§4.1).
type Discovery struct {
	APIVersion    int    `json:"apiVersion"`
	TokenEndpoint string `json:"tokenEndpoint"`
	GrantType     string `json:"grantType"`
	Assertion     struct {
		Alg      string `json:"alg"`
		Audience string `json:"audience"`
	} `json:"assertion"`
	Secrets struct {
		ByName string `json:"byName"`
	} `json:"secrets"`
	Lease struct {
		Supported bool `json:"supported"`
	} `json:"lease"`
}

// Discover fetches and returns the machine-store discovery document.
func (c *Client) Discover() (*Discovery, error) {
	var d Discovery
	if err := c.getJSON("/apps/keepiq/api/v1/app/.well-known/doriath", &d); err != nil {
		return nil, err
	}
	return &d, nil
}

// LeaseSupported reports whether the last discovery advertised lease support.
func (d *Discovery) LeaseSupported() bool { return d.Lease.Supported }

// MachineToken signs an RFC 7523 JWT assertion with the application private key
// and exchanges it for an opaque bearer token (§4.1). The private key never
// leaves the process.
func (c *Client) MachineToken(applicationID string, key *rsa.PrivateKey, disc *Discovery, now int64) (string, error) {
	aud := disc.Assertion.Audience
	if aud == "" {
		aud = "doriath" // EXPECTED_AUDIENCE fallback
	}
	header := base64.RawURLEncoding.EncodeToString([]byte(`{"alg":"RS256","typ":"JWT"}`))
	claims := map[string]any{
		"iss": applicationID,
		"sub": applicationID,
		"aud": aud,
		"iat": now,
		"exp": now + 300,
		"jti": fmt.Sprintf("%s-%d", applicationID, now),
	}
	claimsJSON, _ := json.Marshal(claims)
	payload := base64.RawURLEncoding.EncodeToString(claimsJSON)
	signingInput := header + "." + payload
	sig, err := dcrypto.SignRS256(signingInput, key)
	if err != nil {
		return "", err
	}
	assertion := signingInput + "." + sig

	// NB: Keepiq's token endpoint reads camelCase param names (grantType), not
	// the OAuth-standard snake_case — verified against the live server.
	form := url.Values{
		"grantType": {"urn:ietf:params:oauth:grant-type:jwt-bearer"},
		"assertion": {assertion},
	}.Encode()
	req, _ := http.NewRequest(http.MethodPost, c.abs(disc.TokenEndpoint), strings.NewReader(form))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	resp, err := c.HTTP.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("token exchange failed (%d): %s", resp.StatusCode, strings.TrimSpace(string(body)))
	}
	var tok struct {
		AccessToken string `json:"access_token"`
	}
	if err := json.Unmarshal(body, &tok); err != nil {
		return "", err
	}
	return tok.AccessToken, nil
}

// MachineEnvelope is the doriath-machine-secret-v1 envelope (CI fetch, §4.2).
type MachineEnvelope struct {
	Format  string `json:"format"`
	Scheme  string `json:"scheme"`
	Payload struct {
		Value string `json:"value"`
	} `json:"payload"`
}

// FetchByName fetches an application secret envelope by name with the bearer
// token, capturing any Doriath-Lease-* headers (§4.4 — the header names keep
// the old prefix; they are a published wire contract, see
// lib/Service/MachineSecretResponseService.php). When the client has a
// prior ETag for this secret it sends If-None-Match; on a 304 it returns
// ErrNotModified so a poll loop can keep its cached value (§4.2).
func (c *Client) FetchByName(name, bearer string) (*MachineEnvelope, error) {
	req, _ := http.NewRequest(http.MethodGet, c.BaseURL+"/apps/keepiq/api/v1/app/secrets/by-name/"+name, nil)
	req.Header.Set("Authorization", "Bearer "+bearer)
	if c.lastETag != "" {
		req.Header.Set("If-None-Match", c.lastETag)
	}
	resp, err := c.HTTP.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	c.leaseID = resp.Header.Get("Doriath-Lease-Id")
	c.leaseUntil = resp.Header.Get("Doriath-Lease-Expires")
	if etag := resp.Header.Get("ETag"); etag != "" {
		c.lastETag = etag
	}
	if resp.StatusCode == http.StatusNotModified {
		return nil, ErrNotModified
	}
	body, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("fetch %q failed (%d): %s", name, resp.StatusCode, strings.TrimSpace(string(body)))
	}
	var env MachineEnvelope
	if err := json.Unmarshal(body, &env); err != nil {
		return nil, err
	}
	return &env, nil
}

// --- internals ---

func (c *Client) getJSON(path string, out any) error {
	req, _ := http.NewRequest(http.MethodGet, c.BaseURL+path, nil)
	c.authenticate(req)
	req.Header.Set("Accept", "application/json")
	req.Header.Set("OCS-APIRequest", "true")
	resp, err := c.HTTP.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("GET %s failed (%d): %s", path, resp.StatusCode, strings.TrimSpace(string(body)))
	}
	return json.Unmarshal(bytes.TrimSpace(body), out)
}

func (c *Client) authenticate(req *http.Request) {
	if c.appUser != "" {
		req.SetBasicAuth(c.appUser, c.appPass)
	}
}

func (c *Client) abs(endpoint string) string {
	if strings.HasPrefix(endpoint, "http") {
		return endpoint
	}
	return c.BaseURL + endpoint
}
