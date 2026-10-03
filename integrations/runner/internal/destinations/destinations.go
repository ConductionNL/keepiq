// Package destinations pushes values to AWS Secrets Manager, Azure Key Vault,
// GitHub Actions secrets and exec hooks.
package destinations

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os/exec"
	"regexp"
	"strings"
	"sync"
	"time"

	"github.com/aws/aws-sdk-go-v2/aws"
	awsconfig "github.com/aws/aws-sdk-go-v2/config"
	"github.com/aws/aws-sdk-go-v2/credentials"
	"github.com/aws/aws-sdk-go-v2/service/secretsmanager"
	smtypes "github.com/aws/aws-sdk-go-v2/service/secretsmanager/types"
	"golang.org/x/crypto/nacl/box"

	keepiq "github.com/ConductionNL/keepiq/sdk/go"

	"github.com/ConductionNL/keepiq/integrations/runner/internal/config"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/syncer"
)

// HTTPClient is used by the Azure and GitHub destinations.
var HTTPClient = &http.Client{Timeout: 30 * time.Second}

// New builds the destination of a sync set. creds is the decrypted
// credentials secret, or nil for the ambient identity.
func New(s config.Sync, creds *keepiq.Secret) (syncer.Destination, error) {
	switch s.Destination {
	case "aws-secrets-manager":
		return NewAWS(context.Background(), s.Options, creds)
	case "azure-key-vault":
		return NewAzure(s.Options, creds)
	case "github-actions":
		return NewGitHub(s.Options, creds)
	case "exec":
		return &Exec{Command: s.Command}, nil
	}
	return nil, fmt.Errorf("unknown destination %q", s.Destination)
}

// --- AWS Secrets Manager ---

// AWS pushes with PutSecretValue, creating the secret on first push.
// Options: region, prefix, endpoint (for a local emulator). Credentials:
// login = access key id, key = secret access key, additionalFields
// {"sessionToken": …} optional; without them the default AWS chain is used.
type AWS struct {
	Client *secretsmanager.Client
	Prefix string
}

// NewAWS builds the client.
func NewAWS(ctx context.Context, opts map[string]string, creds *keepiq.Secret) (*AWS, error) {
	var lo []func(*awsconfig.LoadOptions) error
	if r := opts["region"]; r != "" {
		lo = append(lo, awsconfig.WithRegion(r))
	}
	if creds != nil {
		session := extra(creds, "sessionToken")
		lo = append(lo, awsconfig.WithCredentialsProvider(credentials.NewStaticCredentialsProvider(creds.Login, creds.Key, session)))
	}
	cfg, err := awsconfig.LoadDefaultConfig(ctx, lo...)
	if err != nil {
		return nil, err
	}
	client := secretsmanager.NewFromConfig(cfg, func(o *secretsmanager.Options) {
		if ep := opts["endpoint"]; ep != "" {
			o.BaseEndpoint = aws.String(ep)
		}
	})
	return &AWS{Client: client, Prefix: opts["prefix"]}, nil
}

// Push stores the value as the secret's current version.
func (a *AWS) Push(ctx context.Context, name, value string) error {
	id := a.Prefix + name
	_, err := a.Client.PutSecretValue(ctx, &secretsmanager.PutSecretValueInput{SecretId: aws.String(id), SecretString: aws.String(value)})
	var nf *smtypes.ResourceNotFoundException
	if errors.As(err, &nf) {
		_, err = a.Client.CreateSecret(ctx, &secretsmanager.CreateSecretInput{
			Name: aws.String(id), SecretString: aws.String(value),
			Description: aws.String("Synced from Keepiq by keepiq-runner"),
		})
	}
	if err != nil {
		return fmt.Errorf("aws-secrets-manager %s: %w", id, err)
	}
	return nil
}

// --- Azure Key Vault ---

// Azure pushes with Set Secret (REST, api-version 7.4). Options: vaultUrl,
// prefix, authorityHost (default login.microsoftonline.com), imdsEndpoint.
// Credentials: login = client id, key = client secret, additionalFields
// {"tenantId": …}; without them the managed identity endpoint is used.
type Azure struct {
	VaultURL, Prefix, AuthorityHost, IMDS string
	ClientID, ClientSecret, TenantID      string

	mu     sync.Mutex
	token  string
	expiry time.Time
}

// NewAzure builds the destination.
func NewAzure(opts map[string]string, creds *keepiq.Secret) (*Azure, error) {
	a := &Azure{VaultURL: strings.TrimRight(opts["vaultUrl"], "/"), Prefix: opts["prefix"],
		AuthorityHost: opts["authorityHost"], IMDS: opts["imdsEndpoint"]}
	if a.AuthorityHost == "" {
		a.AuthorityHost = "https://login.microsoftonline.com"
	}
	if a.IMDS == "" {
		a.IMDS = "http://169.254.169.254/metadata/identity/oauth2/token"
	}
	if creds != nil {
		a.ClientID, a.ClientSecret, a.TenantID = creds.Login, creds.Key, extra(creds, "tenantId")
		if a.TenantID == "" || a.ClientID == "" {
			return nil, errors.New("azure-key-vault: the credentials secret needs login (client id), key (client secret) and additionalFields.tenantId")
		}
	}
	return a, nil
}

var azureName = regexp.MustCompile(`[^0-9A-Za-z-]`)

// AzureName maps a Keepiq name to a Key Vault name (letters, digits, dashes).
func AzureName(prefix, name string) string { return azureName.ReplaceAllString(prefix+name, "-") }

// Push sets the secret.
func (a *Azure) Push(ctx context.Context, name, value string) error {
	tok, err := a.accessToken(ctx)
	if err != nil {
		return err
	}
	body, _ := json.Marshal(map[string]any{"value": value, "tags": map[string]string{"source": "keepiq"}})
	u := a.VaultURL + "/secrets/" + url.PathEscape(AzureName(a.Prefix, name)) + "?api-version=7.4"
	req, _ := http.NewRequestWithContext(ctx, http.MethodPut, u, bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer "+tok)
	req.Header.Set("Content-Type", "application/json")
	return expect(req, http.StatusOK, "azure-key-vault "+name)
}

func (a *Azure) accessToken(ctx context.Context) (string, error) {
	a.mu.Lock()
	defer a.mu.Unlock()
	if a.token != "" && time.Now().Before(a.expiry) {
		return a.token, nil
	}
	var req *http.Request
	if a.ClientID != "" {
		form := url.Values{"grant_type": {"client_credentials"}, "client_id": {a.ClientID}, "client_secret": {a.ClientSecret}, "scope": {"https://vault.azure.net/.default"}}
		req, _ = http.NewRequestWithContext(ctx, http.MethodPost, a.AuthorityHost+"/"+url.PathEscape(a.TenantID)+"/oauth2/v2.0/token", strings.NewReader(form.Encode()))
		req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	} else {
		req, _ = http.NewRequestWithContext(ctx, http.MethodGet, a.IMDS+"?api-version=2018-02-01&resource="+url.QueryEscape("https://vault.azure.net"), nil)
		req.Header.Set("Metadata", "true")
	}
	resp, err := HTTPClient.Do(req)
	if err != nil {
		return "", fmt.Errorf("azure token: %w", err)
	}
	defer resp.Body.Close()
	var tok struct {
		AccessToken string          `json:"access_token"`
		ExpiresIn   json.RawMessage `json:"expires_in"`
	}
	if resp.StatusCode != http.StatusOK || json.NewDecoder(resp.Body).Decode(&tok) != nil || tok.AccessToken == "" {
		return "", fmt.Errorf("azure token: answered %d", resp.StatusCode)
	}
	secs := 300
	_, _ = fmt.Sscanf(strings.Trim(string(tok.ExpiresIn), `"`), "%d", &secs)
	a.token, a.expiry = tok.AccessToken, time.Now().Add(time.Duration(secs)*time.Second-time.Minute)
	return a.token, nil
}

// --- GitHub Actions secrets ---

// GitHub stores repository, environment or organisation secrets through the
// REST API, sealed with the target's public key (libsodium sealed box), as
// GitHub requires. Options: repository (owner/name) with optional
// environment, or organization with optional visibility (default private);
// apiUrl for GitHub Enterprise; prefix. Credentials: key = a token.
type GitHub struct {
	API, Repository, Environment, Organization, Visibility, Prefix, Token string
}

// NewGitHub builds the destination.
func NewGitHub(opts map[string]string, creds *keepiq.Secret) (*GitHub, error) {
	if creds == nil || creds.Key == "" {
		return nil, errors.New("github-actions: the credentials secret must hold a token in key")
	}
	g := &GitHub{API: strings.TrimRight(opts["apiUrl"], "/"), Repository: opts["repository"], Environment: opts["environment"],
		Organization: opts["organization"], Visibility: opts["visibility"], Prefix: opts["prefix"], Token: creds.Key}
	if g.API == "" {
		g.API = "https://api.github.com"
	}
	if g.Visibility == "" {
		g.Visibility = "private"
	}
	return g, nil
}

var ghName = regexp.MustCompile(`[^A-Z0-9_]`)

// GitHubName maps a Keepiq name to a GitHub secret name (A-Z, 0-9, _).
func GitHubName(prefix, name string) string {
	n := ghName.ReplaceAllString(strings.ToUpper(prefix+name), "_")
	if n != "" && n[0] >= '0' && n[0] <= '9' {
		n = "_" + n
	}
	return n
}

func (g *GitHub) base() string {
	switch {
	case g.Organization != "":
		return g.API + "/orgs/" + url.PathEscape(g.Organization) + "/actions/secrets"
	case g.Environment != "":
		return g.API + "/repos/" + g.Repository + "/environments/" + url.PathEscape(g.Environment) + "/secrets"
	default:
		return g.API + "/repos/" + g.Repository + "/actions/secrets"
	}
}

// Seal encrypts value as a libsodium sealed box for the base64 public key.
func Seal(value, publicKeyB64 string) (string, error) {
	raw, err := base64.StdEncoding.DecodeString(publicKeyB64)
	if err != nil || len(raw) != 32 {
		return "", errors.New("github public key is not a 32-byte base64 key")
	}
	var pk [32]byte
	copy(pk[:], raw)
	out, err := box.SealAnonymous(nil, []byte(value), &pk, rand.Reader)
	if err != nil {
		return "", err
	}
	return base64.StdEncoding.EncodeToString(out), nil
}

// Push seals and stores the value.
func (g *GitHub) Push(ctx context.Context, name, value string) error {
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, g.base()+"/public-key", nil)
	g.headers(req)
	resp, err := HTTPClient.Do(req)
	if err != nil {
		return fmt.Errorf("github public key: %w", err)
	}
	var pk struct {
		KeyID string `json:"key_id"`
		Key   string `json:"key"`
	}
	err = json.NewDecoder(resp.Body).Decode(&pk)
	resp.Body.Close()
	if resp.StatusCode != http.StatusOK || err != nil {
		return fmt.Errorf("github public key: answered %d", resp.StatusCode)
	}
	sealed, err := Seal(value, pk.Key)
	if err != nil {
		return err
	}
	body := map[string]any{"encrypted_value": sealed, "key_id": pk.KeyID}
	if g.Organization != "" {
		body["visibility"] = g.Visibility
	}
	raw, _ := json.Marshal(body)
	put, _ := http.NewRequestWithContext(ctx, http.MethodPut, g.base()+"/"+GitHubName(g.Prefix, name), bytes.NewReader(raw))
	g.headers(put)
	put.Header.Set("Content-Type", "application/json")
	return expect(put, 0, "github-actions "+name)
}

func (g *GitHub) headers(r *http.Request) {
	r.Header.Set("Authorization", "Bearer "+g.Token)
	r.Header.Set("Accept", "application/vnd.github+json")
	r.Header.Set("X-GitHub-Api-Version", "2022-11-28")
}

// --- exec ---

// Exec pushes through a command: one JSON object {"name": …, "value": …} on
// stdin, exit code 0 for success. Its output is not read.
type Exec struct{ Command []string }

// Push runs the hook.
func (e *Exec) Push(ctx context.Context, name, value string) error {
	body, _ := json.Marshal(map[string]string{"name": name, "value": value})
	cmd := exec.CommandContext(ctx, e.Command[0], e.Command[1:]...)
	cmd.Stdin = bytes.NewReader(body)
	if err := cmd.Run(); err != nil {
		if ee, ok := err.(*exec.ExitError); ok {
			return fmt.Errorf("exec %s: exit %d", e.Command[0], ee.ExitCode())
		}
		return fmt.Errorf("exec %s: %w", e.Command[0], err)
	}
	return nil
}

// --- helpers ---

// expect sends req and wants status (0 means any 2xx). The response body is
// not echoed, so a destination error cannot carry a value into the log.
func expect(req *http.Request, status int, what string) error {
	resp, err := HTTPClient.Do(req)
	if err != nil {
		return fmt.Errorf("%s: %w", what, err)
	}
	defer resp.Body.Close()
	_, _ = io.Copy(io.Discard, resp.Body)
	ok := resp.StatusCode == status || (status == 0 && resp.StatusCode/100 == 2)
	if !ok {
		return fmt.Errorf("%s: answered %d", what, resp.StatusCode)
	}
	return nil
}

func extra(s *keepiq.Secret, field string) string {
	var m map[string]any
	if json.Unmarshal([]byte(s.AdditionalFields), &m) != nil {
		return ""
	}
	v, _ := m[field].(string)
	return v
}
