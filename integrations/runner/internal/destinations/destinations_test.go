package destinations

import (
	"context"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	"golang.org/x/crypto/nacl/box"

	keepiq "github.com/ConductionNL/keepiq/sdk/go"
)

// awsStub speaks the Secrets Manager JSON protocol (X-Amz-Target): the
// secret does not exist until CreateSecret, as on a fresh account.
func awsStub(t *testing.T) (*httptest.Server, map[string]string, *[]string) {
	store := map[string]string{}
	var calls []string
	var mu sync.Mutex
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		op := strings.TrimPrefix(r.Header.Get("X-Amz-Target"), "secretsmanager.")
		calls = append(calls, op)
		if !strings.HasPrefix(r.Header.Get("Authorization"), "AWS4-HMAC-SHA256 Credential=AKIATEST/") {
			w.WriteHeader(403)
			return
		}
		var in map[string]string
		_ = json.NewDecoder(r.Body).Decode(&in)
		w.Header().Set("Content-Type", "application/x-amz-json-1.1")
		switch op {
		case "PutSecretValue":
			if _, ok := store[in["SecretId"]]; !ok {
				w.WriteHeader(400)
				_, _ = io.WriteString(w, `{"__type":"ResourceNotFoundException","message":"Secrets Manager can't find the specified secret."}`)
				return
			}
			store[in["SecretId"]] = in["SecretString"]
			_, _ = io.WriteString(w, `{"ARN":"arn:x","Name":"`+in["SecretId"]+`","VersionId":"v2"}`)
		case "CreateSecret":
			store[in["Name"]] = in["SecretString"]
			_, _ = io.WriteString(w, `{"ARN":"arn:x","Name":"`+in["Name"]+`","VersionId":"v1"}`)
		default:
			w.WriteHeader(400)
		}
	}))
	t.Cleanup(srv.Close)
	return srv, store, &calls
}

func TestAWSCreatesThenUpdates(t *testing.T) {
	srv, store, calls := awsStub(t)
	creds := &keepiq.Secret{Login: "AKIATEST", Key: "aws-secret-key"}
	a, err := NewAWS(context.Background(), map[string]string{"region": "eu-west-1", "prefix": "prod/", "endpoint": srv.URL}, creds)
	if err != nil {
		t.Fatal(err)
	}
	if err := a.Push(context.Background(), "stripe-key", "sk_live_one"); err != nil {
		t.Fatal(err)
	}
	if err := a.Push(context.Background(), "stripe-key", "sk_live_two"); err != nil {
		t.Fatal(err)
	}
	if store["prod/stripe-key"] != "sk_live_two" || strings.Join(*calls, ",") != "PutSecretValue,CreateSecret,PutSecretValue" {
		t.Fatalf("store %v calls %v", store, *calls)
	}
}

func TestAzureClientCredentialsAndSetSecret(t *testing.T) {
	var got struct{ name, value, auth, form string }
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.URL.Path == "/tenant-1/oauth2/v2.0/token":
			_ = r.ParseForm()
			got.form = r.Form.Get("client_id") + "|" + r.Form.Get("scope") + "|" + r.Form.Get("grant_type")
			_, _ = io.WriteString(w, `{"access_token":"az-token","expires_in":3599}`)
		case strings.HasPrefix(r.URL.Path, "/secrets/") && r.Method == http.MethodPut:
			var in map[string]any
			_ = json.NewDecoder(r.Body).Decode(&in)
			got.name, got.value, got.auth = strings.TrimPrefix(r.URL.Path, "/secrets/"), in["value"].(string), r.Header.Get("Authorization")
			if r.URL.Query().Get("api-version") != "7.4" {
				w.WriteHeader(400)
				return
			}
			_, _ = io.WriteString(w, `{"id":"x"}`)
		default:
			w.WriteHeader(404)
		}
	}))
	defer srv.Close()
	creds := &keepiq.Secret{Login: "client-1", Key: "client-secret", AdditionalFields: `{"tenantId":"tenant-1"}`}
	a, err := NewAzure(map[string]string{"vaultUrl": srv.URL, "authorityHost": srv.URL, "prefix": "prod-"}, creds)
	if err != nil {
		t.Fatal(err)
	}
	if err := a.Push(context.Background(), "stripe_key.v2", "sk_live_one"); err != nil {
		t.Fatal(err)
	}
	if got.name != "prod-stripe-key-v2" || got.value != "sk_live_one" || got.auth != "Bearer az-token" || got.form != "client-1|https://vault.azure.net/.default|client_credentials" {
		t.Fatalf("azure saw %+v", got)
	}
}

func TestAzureManagedIdentity(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/imds" {
			if r.Header.Get("Metadata") != "true" {
				w.WriteHeader(400)
				return
			}
			_, _ = io.WriteString(w, `{"access_token":"mi-token","expires_in":"3599"}`)
			return
		}
		if r.Header.Get("Authorization") != "Bearer mi-token" {
			w.WriteHeader(401)
			return
		}
		_, _ = io.WriteString(w, `{}`)
	}))
	defer srv.Close()
	a, _ := NewAzure(map[string]string{"vaultUrl": srv.URL, "imdsEndpoint": srv.URL + "/imds"}, nil)
	if err := a.Push(context.Background(), "x", "v"); err != nil {
		t.Fatal(err)
	}
}

// GitHub gets a sealed box only the repository key can open.
func TestGitHubSealsWithTheRepositoryKey(t *testing.T) {
	pub, priv, _ := box.GenerateKey(rand.Reader)
	var put map[string]string
	var path string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer ghp_test" {
			w.WriteHeader(401)
			return
		}
		switch {
		case strings.HasSuffix(r.URL.Path, "/public-key"):
			_ = json.NewEncoder(w).Encode(map[string]string{"key_id": "k-1", "key": base64.StdEncoding.EncodeToString(pub[:])})
		case r.Method == http.MethodPut:
			path = r.URL.Path
			_ = json.NewDecoder(r.Body).Decode(&put)
			w.WriteHeader(201)
		}
	}))
	defer srv.Close()
	g, err := NewGitHub(map[string]string{"apiUrl": srv.URL, "repository": "example/app"}, &keepiq.Secret{Key: "ghp_test"})
	if err != nil {
		t.Fatal(err)
	}
	if err := g.Push(context.Background(), "deploy-token", "dt_secret_value"); err != nil {
		t.Fatal(err)
	}
	if path != "/repos/example/app/actions/secrets/DEPLOY_TOKEN" || put["key_id"] != "k-1" {
		t.Fatalf("PUT %s %v", path, put)
	}
	if strings.Contains(put["encrypted_value"], "dt_secret_value") {
		t.Fatal("value sent in the clear")
	}
	sealed, _ := base64.StdEncoding.DecodeString(put["encrypted_value"])
	opened, ok := box.OpenAnonymous(nil, sealed, pub, priv)
	if !ok || string(opened) != "dt_secret_value" {
		t.Fatalf("the repository key does not open the box: %v %q", ok, opened)
	}
	org, _ := NewGitHub(map[string]string{"organization": "example"}, &keepiq.Secret{Key: "t"})
	if org.base() != "https://api.github.com/orgs/example/actions/secrets" {
		t.Fatal(org.base())
	}
	env, _ := NewGitHub(map[string]string{"repository": "example/app", "environment": "prod"}, &keepiq.Secret{Key: "t"})
	if env.base() != "https://api.github.com/repos/example/app/environments/prod/secrets" {
		t.Fatal(env.base())
	}
	if GitHubName("", "1st-key.v2") != "_1ST_KEY_V2" {
		t.Fatal(GitHubName("", "1st-key.v2"))
	}
}

func TestExecDestination(t *testing.T) {
	dir := t.TempDir()
	out := filepath.Join(dir, "got.json")
	script := filepath.Join(dir, "push.sh")
	_ = os.WriteFile(script, []byte("#!/bin/sh\ncat > "+out+"\n"), 0o755)
	if err := (&Exec{Command: []string{script}}).Push(context.Background(), "stripe-key", "sk_live_one"); err != nil {
		t.Fatal(err)
	}
	raw, _ := os.ReadFile(out)
	if string(raw) != `{"name":"stripe-key","value":"sk_live_one"}` {
		t.Fatalf("hook got %s", raw)
	}
	fail := filepath.Join(dir, "fail.sh")
	_ = os.WriteFile(fail, []byte("#!/bin/sh\necho sk_live_one >&2\nexit 4\n"), 0o755)
	err := (&Exec{Command: []string{fail}}).Push(context.Background(), "stripe-key", "sk_live_one")
	if err == nil || !strings.Contains(err.Error(), "exit 4") || strings.Contains(err.Error(), "sk_live_one") {
		t.Fatalf("exec failure: %v", err)
	}
}
