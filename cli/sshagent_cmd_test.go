//go:build !windows

package main

import (
	"bytes"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/rsa"
	"crypto/sha256"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"encoding/pem"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"

	"golang.org/x/crypto/ssh"

	"github.com/ConductionNL/keepiq/sdk/go/client"
	dcrypto "github.com/ConductionNL/keepiq/sdk/go/crypto"
)

func TestParseAgentFlags(t *testing.T) {
	f, err := parseAgentFlags(nil)
	if err != nil || f.Idle != 60 || f.Confirm || f.Locked || f.Socket != "" {
		t.Fatalf("defaults: %+v %v", f, err)
	}
	f, err = parseAgentFlags([]string{"--socket", "/run/x.sock", "--confirm", "--idle", "0", "--folder", "Deploy", "--locked"})
	if err != nil || f.Socket != "/run/x.sock" || !f.Confirm || f.Idle != 0 || f.Folder != "Deploy" || !f.Locked {
		t.Fatalf("all flags: %+v %v", f, err)
	}
	for _, bad := range [][]string{{"--idle", "-1"}, {"--idle", "soon"}, {"--socket"}, {"--add"}} {
		if _, err := parseAgentFlags(bad); err == nil {
			t.Errorf("%v: expected an error", bad)
		}
	}
}

// encryptField mirrors the browser's rsaEncrypt for an RSA-4096 key:
// [4-byte chunk count][512-byte RSA-OAEP-SHA256 blocks].
func encryptField(t *testing.T, plaintext []byte, pub *rsa.PublicKey) string {
	t.Helper()
	const chunk = 446
	var blocks [][]byte
	for len(plaintext) > 0 {
		n := chunk
		if len(plaintext) < n {
			n = len(plaintext)
		}
		b, err := rsa.EncryptOAEP(sha256.New(), rand.Reader, pub, plaintext[:n], nil)
		if err != nil {
			t.Fatal(err)
		}
		blocks = append(blocks, b)
		plaintext = plaintext[n:]
	}
	out := make([]byte, 4)
	binary.BigEndian.PutUint32(out, uint32(len(blocks)))
	for _, b := range blocks {
		out = append(out, b...)
	}
	return base64.StdEncoding.EncodeToString(out)
}

// The agent's unlock reads only ciphertext from the server, sends no body and
// no master password, and decrypts the SSH key in this process.
func TestVaultUnlockerReadsOnlyCiphertext(t *testing.T) {
	suiteKey, err := rsa.GenerateKey(rand.Reader, 4096)
	if err != nil {
		t.Fatal(err)
	}
	_, sshKey, _ := ed25519.GenerateKey(rand.Reader)
	block, _ := ssh.MarshalPrivateKey(sshKey, "")
	privatePEM := pem.EncodeToMemory(block)
	secrets := []client.Secret{
		{ID: "s1", Name: "GitHub deploy", TypeID: "t-ssh", Key: encryptField(t, privatePEM, &suiteKey.PublicKey)},
		{ID: "s2", Name: "Mail", TypeID: "t-login", Key: encryptField(t, []byte("hunter2"), &suiteKey.PublicKey)},
	}

	type seen struct{ method, path, body, auth string }
	var mu sync.Mutex
	var requests []seen
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		mu.Lock()
		requests = append(requests, seen{r.Method, r.URL.Path, string(body), r.Header.Get("Authorization")})
		mu.Unlock()
		switch r.URL.Path {
		case "/apps/keepiq/api/v1/secret-types":
			_, _ = w.Write([]byte(`[{"id":"t-login","name":"login"},{"id":"t-ssh","name":"ssh_key"}]`))
		case "/apps/keepiq/api/v1/secrets":
			_ = json.NewEncoder(w).Encode(map[string]any{"items": secrets})
		default:
			http.NotFound(w, r)
		}
	}))
	defer srv.Close()

	const master = "correct horse battery staple"
	open := func(pw string) (*client.Client, *dcrypto.UnlockedSuite, error) {
		if pw != master {
			t.Fatalf("the unlocker passed on %q", pw)
		}
		c := client.New(srv.URL)
		c.WithAppPassword("alice", "app-password")
		return c, &dcrypto.UnlockedSuite{Key: suiteKey}, nil
	}

	var warn bytes.Buffer
	keys, err := vaultUnlocker("", &warn, open)(master)
	if err != nil {
		t.Fatal(err)
	}
	if len(keys) != 1 || keys[0].Name != "GitHub deploy" {
		t.Fatalf("keys: %+v", keys)
	}
	want, _ := ssh.NewSignerFromKey(sshKey)
	if !bytes.Equal(keys[0].Signer.PublicKey().Marshal(), want.PublicKey().Marshal()) {
		t.Fatal("the decrypted key is not the vault key")
	}

	for _, r := range requests {
		if r.method != http.MethodGet || r.body != "" {
			t.Errorf("%s %s sent a body or was not a read", r.method, r.path)
		}
		if strings.Contains(r.path+r.body+r.auth, master) || strings.Contains(r.body, "PRIVATE KEY") {
			t.Errorf("%s leaked key material or the master password", r.path)
		}
	}
	if len(requests) != 2 {
		t.Fatalf("requests: %+v", requests)
	}
}

// A service manager such as systemd gives the agent a stdout that is not a
// terminal. Without --foreground the agent would detach, the unit's main
// process would exit, and systemd would stop the unit and kill the agent.
func TestTheAgentDetachesOnlyForEvalNotUnderAServiceManager(t *testing.T) {
	cases := []struct {
		name       string
		flags      agentFlags
		isDetached bool
		terminal   bool
		want       bool
	}{
		{"eval pipe, plain", agentFlags{}, false, false, true},
		{"terminal", agentFlags{}, false, true, false},
		{"already the background copy", agentFlags{}, true, false, false},
		{"systemd: journal stdout with --foreground", agentFlags{Foreground: true}, false, false, false},
		{"systemd: --locked --foreground", agentFlags{Locked: true, Foreground: true}, false, false, false},
	}
	for _, c := range cases {
		if got := shouldDetach(c.flags, c.isDetached, c.terminal); got != c.want {
			t.Errorf("%s: shouldDetach = %v, want %v", c.name, got, c.want)
		}
	}
	f, err := parseAgentFlags([]string{"--locked", "--foreground"})
	if err != nil || !f.Foreground || !f.Locked {
		t.Fatalf("parseAgentFlags(--locked --foreground) = %+v, %v", f, err)
	}
}
