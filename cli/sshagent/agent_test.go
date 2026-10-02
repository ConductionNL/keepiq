package sshagent

import (
	"bytes"
	"crypto/ecdsa"
	"crypto/ed25519"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/rsa"
	"encoding/pem"
	"errors"
	"net"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
	"time"

	"golang.org/x/crypto/ssh"
	"golang.org/x/crypto/ssh/agent"

	"github.com/ConductionNL/keepiq/sdk/go/client"
)

const sshType = "t-ssh"

func openSSHPEM(t *testing.T, key any, passphrase string) string {
	t.Helper()
	var block *pem.Block
	var err error
	if passphrase != "" {
		block, err = ssh.MarshalPrivateKeyWithPassphrase(key, "", []byte(passphrase))
	} else {
		block, err = ssh.MarshalPrivateKey(key, "")
	}
	if err != nil {
		t.Fatal(err)
	}
	return string(pem.EncodeToMemory(block))
}

// vault returns throwaway secrets: four usable keys of every supported kind,
// a passphrase-protected one, a broken one, a key in another folder and a
// login secret. The "ciphertext" is the plaintext; decrypt is the identity.
func vault(t *testing.T) []client.Secret {
	t.Helper()
	_, ed, _ := ed25519.GenerateKey(rand.Reader)
	ec256, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	ec384, _ := ecdsa.GenerateKey(elliptic.P384(), rand.Reader)
	ec521, _ := ecdsa.GenerateKey(elliptic.P521(), rand.Reader)
	rk, _ := rsa.GenerateKey(rand.Reader, 2048)
	_, locked, _ := ed25519.GenerateKey(rand.Reader)
	_, other, _ := ed25519.GenerateKey(rand.Reader)
	return []client.Secret{
		{ID: "1", Name: "GitHub deploy", TypeID: sshType, FolderID: "f1", Key: openSSHPEM(t, ed, "")},
		{ID: "2", Name: "P-256", TypeID: sshType, FolderID: "f1", Key: openSSHPEM(t, ec256, "")},
		{ID: "3", Name: "P-384", TypeID: sshType, FolderID: "f1", Key: openSSHPEM(t, ec384, "")},
		{ID: "4", Name: "P-521", TypeID: sshType, FolderID: "f1", Key: openSSHPEM(t, ec521, "")},
		{ID: "5", Name: "RSA", TypeID: sshType, FolderID: "f1", Key: openSSHPEM(t, rk, "")},
		{ID: "6", Name: "With passphrase", TypeID: sshType, FolderID: "f1", Key: openSSHPEM(t, locked, "pw")},
		{ID: "7", Name: "Truncated", TypeID: sshType, FolderID: "f1", Key: "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAA\n"},
		{ID: "8", Name: "Elsewhere", TypeID: sshType, FolderID: "f2", Key: openSSHPEM(t, other, "")},
		{ID: "9", Name: "A login", TypeID: "t-login", Key: "hunter2"},
	}
}

func identity(s string) (string, error) { return s, nil }

func TestBuildKeyringKeepsUsableKeysAndNamesTheSkippedOnes(t *testing.T) {
	var warn bytes.Buffer
	keys := BuildKeyring(vault(t), sshType, "f1", identity, &warn)

	var names []string
	for _, k := range keys {
		names = append(names, k.Name)
	}
	if got := strings.Join(names, ","); got != "GitHub deploy,P-256,P-384,P-521,RSA" {
		t.Fatalf("keys = %s", got)
	}
	if !strings.Contains(warn.String(), `"With passphrase": it is protected by a passphrase`) {
		t.Errorf("passphrase key not named: %q", warn.String())
	}
	if !strings.Contains(warn.String(), `"Truncated"`) {
		t.Errorf("broken key not named: %q", warn.String())
	}
	if strings.Contains(warn.String(), "A login") || strings.Contains(warn.String(), "Elsewhere") {
		t.Errorf("other types and folders must be ignored silently: %q", warn.String())
	}

	all := BuildKeyring(vault(t), sshType, "", identity, &bytes.Buffer{})
	if len(all) != 6 {
		t.Fatalf("without a folder filter: %d keys, want 6", len(all))
	}
}

// newAgent returns an unlocked agent and a protocol client talking to it over
// a pipe, the way ssh and ssh-add do.
func newAgent(t *testing.T, o Options) (*Agent, agent.ExtendedAgent) {
	t.Helper()
	secrets := vault(t)
	if o.Unlock == nil {
		o.Unlock = func(pw string) ([]Identity, error) {
			if pw != "master" {
				return nil, errors.New("wrong master password")
			}
			return BuildKeyring(secrets, sshType, "f1", identity, &bytes.Buffer{}), nil
		}
	}
	a := New(o)
	if err := a.UnlockWith("master"); err != nil {
		t.Fatal(err)
	}
	server, conn := net.Pipe()
	go func() { _ = agent.ServeAgent(a, server) }()
	t.Cleanup(func() { conn.Close(); server.Close() })
	return a, agent.NewClient(conn)
}

func TestListAndSignWithEveryKeyType(t *testing.T) {
	_, c := newAgent(t, Options{})
	keys, err := c.List()
	if err != nil || len(keys) != 5 {
		t.Fatalf("List = %d keys, %v", len(keys), err)
	}
	data := []byte("session data")
	for _, k := range keys {
		flags := agent.SignatureFlags(0)
		if k.Format == ssh.KeyAlgoRSA {
			flags = agent.SignatureFlagRsaSha256
		}
		sig, err := c.SignWithFlags(k, data, flags)
		if err != nil {
			t.Fatalf("%s: %v", k.Comment, err)
		}
		if err := k.Verify(data, sig); err != nil {
			t.Fatalf("%s: signature does not verify: %v", k.Comment, err)
		}
		if k.Format == ssh.KeyAlgoRSA && sig.Format != ssh.KeyAlgoRSASHA256 {
			t.Fatalf("RSA signed with %s", sig.Format)
		}
	}
}

func TestRSAWithoutSHA2FlagIsRefused(t *testing.T) {
	_, c := newAgent(t, Options{})
	keys, _ := c.List()
	for _, k := range keys {
		if k.Format != ssh.KeyAlgoRSA {
			continue
		}
		if _, err := c.Sign(k, []byte("x")); err == nil {
			t.Fatal("an ssh-rsa (SHA-1) signature must be refused")
		}
		sig, err := c.SignWithFlags(k, []byte("x"), agent.SignatureFlagRsaSha512)
		if err != nil || sig.Format != ssh.KeyAlgoRSASHA512 {
			t.Fatalf("rsa-sha2-512: %v %v", sig, err)
		}
		return
	}
	t.Fatal("no RSA key listed")
}

func TestAddAndRemoveAreRefused(t *testing.T) {
	_, c := newAgent(t, Options{})
	_, local, _ := ed25519.GenerateKey(rand.Reader)
	if err := c.Add(agent.AddedKey{PrivateKey: local}); err == nil {
		t.Fatal("ssh-add of a local key must be refused")
	}
	keys, _ := c.List()
	if len(keys) != 5 {
		t.Fatalf("the refused key was listed: %d keys", len(keys))
	}
	if err := c.Remove(keys[0]); err == nil {
		t.Fatal("remove must be refused")
	}
	if err := c.RemoveAll(); err == nil {
		t.Fatal("remove-all must be refused")
	}
}

func TestLockDropsKeysAndUnlockNeedsTheMasterPassword(t *testing.T) {
	a, c := newAgent(t, Options{})
	keys, _ := c.List()
	if err := c.Lock([]byte("anything")); err != nil {
		t.Fatal(err)
	}
	if listed, _ := c.List(); len(listed) != 0 {
		t.Fatalf("locked agent listed %d keys", len(listed))
	}
	if _, err := c.SignWithFlags(keys[0], []byte("x"), 0); err == nil {
		t.Fatal("a locked agent must refuse to sign")
	}
	if a.keys != nil {
		t.Fatal("locking must drop the decrypted keys")
	}
	if err := c.Unlock([]byte("wrong")); err == nil {
		t.Fatal("a wrong master password must not unlock")
	}
	if err := c.Unlock([]byte("master")); err != nil {
		t.Fatal(err)
	}
	if listed, _ := c.List(); len(listed) != 5 {
		t.Fatalf("after unlock: %d keys", len(listed))
	}
}

func TestStartedLockedHoldsNoKeysUntilUnlocked(t *testing.T) {
	a := New(Options{Unlock: func(string) ([]Identity, error) { return nil, nil }})
	if !a.Locked() {
		t.Fatal("a new agent must start locked")
	}
	if keys, _ := a.List(); len(keys) != 0 {
		t.Fatal("a locked agent lists nothing")
	}
}

func TestIdleLockDropsKeys(t *testing.T) {
	now := time.Date(2026, 10, 2, 12, 0, 0, 0, time.UTC)
	clock := func() time.Time { return now }
	a, c := newAgent(t, Options{Idle: 30 * time.Minute, Now: clock})
	keys, _ := c.List()

	now = now.Add(29 * time.Minute)
	if _, err := c.SignWithFlags(keys[0], []byte("x"), 0); err != nil {
		t.Fatalf("before the idle period: %v", err)
	}
	now = now.Add(30 * time.Minute)
	a.ExpireIfIdle()
	if !a.Locked() || a.keys != nil {
		t.Fatal("the idle lock must drop every key")
	}
	if _, err := c.SignWithFlags(keys[0], []byte("x"), 0); err == nil {
		t.Fatal("after the idle period the agent must refuse as locked")
	}
}

func askpass(t *testing.T, exit int) string {
	t.Helper()
	if runtime.GOOS == "windows" {
		t.Skip("shell script askpass")
	}
	dir := t.TempDir()
	path := filepath.Join(dir, "askpass")
	log := filepath.Join(dir, "log")
	script := "#!/bin/sh\necho \"$SSH_ASKPASS_PROMPT|$1\" > " + log + "\nexit " + map[int]string{0: "0", 1: "1"}[exit] + "\n"
	if err := os.WriteFile(path, []byte(script), 0o700); err != nil {
		t.Fatal(err)
	}
	return path
}

func TestConfirmAllowsOnlyOnExitZero(t *testing.T) {
	for _, tc := range []struct {
		exit int
		ok   bool
	}{{0, true}, {1, false}} {
		program := askpass(t, tc.exit)
		confirm, err := AskpassConfirmer(program)
		if err != nil {
			t.Fatal(err)
		}
		_, c := newAgent(t, Options{Confirm: confirm})
		keys, _ := c.List()
		_, err = c.SignWithFlags(keys[0], []byte("x"), 0)
		if (err == nil) != tc.ok {
			t.Fatalf("askpass exit %d: sign error %v", tc.exit, err)
		}
		logged, _ := os.ReadFile(filepath.Join(filepath.Dir(program), "log"))
		if !strings.HasPrefix(string(logged), "confirm|") || !strings.Contains(string(logged), "GitHub deploy") {
			t.Fatalf("askpass was not asked to confirm the named key: %q", logged)
		}
	}
}

func TestConfirmNeedsAskpass(t *testing.T) {
	if _, err := AskpassConfirmer(""); !errors.Is(err, ErrNoAskpass) {
		t.Fatalf("want ErrNoAskpass, got %v", err)
	}
}
