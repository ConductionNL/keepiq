package main

import (
	"bytes"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"

	"github.com/ConductionNL/keepiq/cli/internal/client"
	dcrypto "github.com/ConductionNL/keepiq/cli/internal/crypto"
)

// machineFixture is testdata/machine_envelope.json: an envelope written by the
// server's real MachineSecretEnvelopeService::serialize() over ciphertext from
// the real EncryptService, plus the throwaway key that decrypts it. PHPUnit
// (tests/Unit/Service/MachineEnvelopeCliFixtureTest.php) fails when serialize()
// stops producing exactly this envelope, so these tests follow the server.
type machineFixture struct {
	PrivateKeyPem string            `json:"privateKeyPem"`
	Plaintext     map[string]string `json:"plaintext"`
	Envelope      json.RawMessage   `json:"envelope"`
}

func loadMachineFixture(t *testing.T) machineFixture {
	t.Helper()
	raw, err := os.ReadFile("testdata/machine_envelope.json")
	if err != nil {
		t.Fatal(err)
	}
	var f machineFixture
	if err := json.Unmarshal(raw, &f); err != nil {
		t.Fatal(err)
	}
	if len(f.Envelope) == 0 || f.PrivateKeyPem == "" || f.Plaintext["key"] == "" {
		t.Fatal("testdata/machine_envelope.json is missing envelope, privateKeyPem or plaintext.key")
	}
	return f
}

// stubKeepiq serves discovery, the token endpoint and the by-name read, the
// last one answering with the server's envelope bytes and lease headers.
func stubKeepiq(t *testing.T, envelope []byte) *httptest.Server {
	t.Helper()
	mux := http.NewServeMux()
	mux.HandleFunc("/apps/keepiq/api/v1/app/.well-known/doriath", func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"apiVersion":1,"tokenEndpoint":"/apps/keepiq/api/v1/app/token","assertion":{"alg":"RS256","audience":"doriath"},"lease":{"supported":true}}`))
	})
	mux.HandleFunc("/apps/keepiq/api/v1/app/token", func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"access_token":"tok","token_type":"Bearer"}`))
	})
	mux.HandleFunc("/apps/keepiq/api/v1/app/secrets/by-name/", func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer tok" {
			t.Errorf("by-name read without the bearer, got %q", r.Header.Get("Authorization"))
		}
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Doriath-Lease-Id", "lease-7")
		w.Header().Set("Doriath-Lease-Expires", "2026-10-01T00:00:00+00:00")
		_, _ = w.Write(envelope)
	})
	srv := httptest.NewServer(mux)
	t.Cleanup(srv.Close)
	return srv
}

// TestFetchDecryptRealServerEnvelope decrypts the envelope the server really
// sends (keepiq#793): the scheme sits under encryption.scheme and the value
// under ciphertext.key.
func TestFetchDecryptRealServerEnvelope(t *testing.T) {
	f := loadMachineFixture(t)
	srv := stubKeepiq(t, f.Envelope)
	key, err := dcrypto.ParsePrivateKey(f.PrivateKeyPem)
	if err != nil {
		t.Fatal(err)
	}

	c := client.New(srv.URL)
	got, err := fetchDecrypt(c, key, "ci-fixture-db-password", "tok")
	if err != nil {
		t.Fatalf("fetchDecrypt: %v", err)
	}
	if got != f.Plaintext["key"] {
		t.Fatalf("value = %q, want %q", got, f.Plaintext["key"])
	}
	if c.LeaseID() != "lease-7" {
		t.Fatalf("lease id = %q, want lease-7", c.LeaseID())
	}
}

// TestFetchDecryptRefusesAnUnknownScheme keeps the scheme check: an envelope
// naming another scheme is refused before any decryption.
func TestFetchDecryptRefusesAnUnknownScheme(t *testing.T) {
	f := loadMachineFixture(t)
	var env map[string]any
	if err := json.Unmarshal(f.Envelope, &env); err != nil {
		t.Fatal(err)
	}
	env["encryption"].(map[string]any)["scheme"] = "rsa-oaep-sha1-v0"
	body, _ := json.Marshal(env)
	srv := stubKeepiq(t, body)
	key, err := dcrypto.ParsePrivateKey(f.PrivateKeyPem)
	if err != nil {
		t.Fatal(err)
	}

	_, err = fetchDecrypt(client.New(srv.URL), key, "ci-fixture-db-password", "tok")
	if err == nil || !strings.Contains(err.Error(), `unexpected envelope scheme "rsa-oaep-sha1-v0"`) {
		t.Fatalf("want an unexpected scheme error, got %v", err)
	}
}

// TestCIFetchPrintsValueAndLease runs `keepiq ci fetch <name> --output json`
// end to end against the stub: the decrypted value goes to stdout and the
// lease line to stderr.
func TestCIFetchPrintsValueAndLease(t *testing.T) {
	f := loadMachineFixture(t)
	srv := stubKeepiq(t, f.Envelope)
	t.Setenv("KEEPIQ_URL", srv.URL)
	t.Setenv("KEEPIQ_APP_ID", "app-cli-fixture")
	t.Setenv("KEEPIQ_APP_KEY", f.PrivateKeyPem)
	t.Setenv("KEEPIQ_APP_KEY_FILE", "")

	stdout, stderr, err := captureOutput(t, func() error {
		return cmdCIFetch([]string{"ci-fixture-db-password", "--output", "json"})
	})
	if err != nil {
		t.Fatalf("ci fetch: %v", err)
	}
	var out map[string]string
	if err := json.Unmarshal([]byte(stdout), &out); err != nil {
		t.Fatalf("stdout is not JSON: %q", stdout)
	}
	if out["name"] != "ci-fixture-db-password" || out["value"] != f.Plaintext["key"] {
		t.Fatalf("stdout = %v, want name ci-fixture-db-password and the decrypted value", out)
	}
	if !strings.Contains(stderr, "lease lease-7 expires 2026-10-01T00:00:00+00:00") {
		t.Fatalf("stderr has no lease line: %q", stderr)
	}
}

// captureOutput runs fn with os.Stdout and os.Stderr redirected to pipes.
func captureOutput(t *testing.T, fn func() error) (string, string, error) {
	t.Helper()
	oldOut, oldErr := os.Stdout, os.Stderr
	outR, outW, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	errR, errW, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	os.Stdout, os.Stderr = outW, errW
	var outBuf, errBuf bytes.Buffer
	done := make(chan struct{}, 2)
	go func() { _, _ = io.Copy(&outBuf, outR); done <- struct{}{} }()
	go func() { _, _ = io.Copy(&errBuf, errR); done <- struct{}{} }()

	runErr := fn()

	os.Stdout, os.Stderr = oldOut, oldErr
	_ = outW.Close()
	_ = errW.Close()
	<-done
	<-done
	return outBuf.String(), errBuf.String(), runErr
}
