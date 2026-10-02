//go:build linux || darwin

package sshagent

import (
	"bytes"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/pem"
	"fmt"
	"net"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"golang.org/x/crypto/ssh"

	"github.com/ConductionNL/keepiq/cli/internal/client"
)

// TestRealSSHThroughTheAgent starts a throwaway sshd on localhost that trusts
// one generated key, serves that key from the agent as a vault key, and runs
// the real ssh client against it with IdentityAgent pointing at the agent.
// It skips where sshd or ssh is not installed.
func TestRealSSHThroughTheAgent(t *testing.T) {
	sshd := findBinary("sshd", "/usr/sbin/sshd", "/usr/local/sbin/sshd")
	sshBin := findBinary("ssh", "/usr/bin/ssh")
	if sshd == "" || sshBin == "" {
		t.Skip("sshd or ssh not installed")
	}
	me, err := user.Current()
	if err != nil {
		t.Fatal(err)
	}
	dir := shortTempDir(t)

	// The user's vault key, and the host key of the throwaway server.
	_, userKey, _ := ed25519.GenerateKey(rand.Reader)
	_, hostKey, _ := ed25519.GenerateKey(rand.Reader)
	hostBlock, _ := ssh.MarshalPrivateKey(hostKey, "")
	hostKeyPath := filepath.Join(dir, "host_ed25519")
	must(t, os.WriteFile(hostKeyPath, pem.EncodeToMemory(hostBlock), 0o600))
	signer, _ := ssh.NewSignerFromKey(userKey)
	authorized := filepath.Join(dir, "authorized_keys")
	must(t, os.WriteFile(authorized, ssh.MarshalAuthorizedKey(signer.PublicKey()), 0o600))

	port := freePort(t)
	config := filepath.Join(dir, "sshd_config")
	must(t, os.WriteFile(config, []byte(fmt.Sprintf(`Port %d
ListenAddress 127.0.0.1
HostKey %s
AuthorizedKeysFile %s
PidFile %s
StrictModes no
UsePAM no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
PermitRootLogin yes
`, port, hostKeyPath, authorized, filepath.Join(dir, "sshd.pid"))), 0o600))
	daemon := exec.Command(sshd, "-D", "-e", "-f", config)
	var daemonLog bytes.Buffer
	daemon.Stderr = &daemonLog
	must(t, daemon.Start())
	t.Cleanup(func() { _ = daemon.Process.Kill(); _ = daemon.Wait() })
	waitForPort(t, port)

	// The agent, serving that key as the vault's only ssh_key secret.
	block, _ := ssh.MarshalPrivateKey(userKey, "")
	vaultKey := string(pem.EncodeToMemory(block))
	a := New(Options{Unlock: func(string) ([]Identity, error) {
		return BuildKeyring(
			[]client.Secret{{ID: "s1", Name: "GitHub deploy", TypeID: sshType, Key: vaultKey}},
			sshType, "", identity, &bytes.Buffer{},
		), nil
	}})
	must(t, a.UnlockWith("master"))
	sock := filepath.Join(dir, "agent", "agent.sock")
	l, err := Listen(sock)
	must(t, err)
	t.Cleanup(func() { l.Close() })
	go func() { _ = Serve(l, a, PeerUID, nil) }()

	out, err := exec.Command(sshBin,
		"-F", "/dev/null",
		"-o", "IdentityAgent="+sock,
		"-o", "IdentityFile=none",
		"-o", "StrictHostKeyChecking=no",
		"-o", "UserKnownHostsFile=/dev/null",
		"-o", "BatchMode=yes",
		"-p", fmt.Sprint(port),
		me.Username+"@127.0.0.1", "echo keepiq-agent-ok",
	).CombinedOutput()
	if err != nil || !strings.Contains(string(out), "keepiq-agent-ok") {
		t.Fatalf("ssh through the agent failed: %v\n%s\nsshd: %s", err, out, daemonLog.String())
	}

	// Locked, the same login fails.
	must(t, a.Lock(nil))
	if out, err := exec.Command(sshBin, "-F", "/dev/null", "-o", "IdentityAgent="+sock, "-o", "IdentityFile=none",
		"-o", "StrictHostKeyChecking=no", "-o", "UserKnownHostsFile=/dev/null", "-o", "BatchMode=yes",
		"-p", fmt.Sprint(port), me.Username+"@127.0.0.1", "true").CombinedOutput(); err == nil {
		t.Fatalf("a locked agent still logged in: %s", out)
	}
}

func findBinary(name string, candidates ...string) string {
	if p, err := exec.LookPath(name); err == nil {
		return p
	}
	for _, c := range candidates {
		if _, err := os.Stat(c); err == nil {
			return c
		}
	}
	return ""
}

func freePort(t *testing.T) int {
	l, err := net.Listen("tcp", "127.0.0.1:0")
	must(t, err)
	defer l.Close()
	return l.Addr().(*net.TCPAddr).Port
}

func waitForPort(t *testing.T, port int) {
	deadline := time.Now().Add(10 * time.Second)
	for time.Now().Before(deadline) {
		if c, err := net.Dial("tcp", fmt.Sprintf("127.0.0.1:%d", port)); err == nil {
			c.Close()
			return
		}
		time.Sleep(100 * time.Millisecond)
	}
	t.Fatalf("sshd did not start on port %d", port)
}

func must(t *testing.T, err error) {
	t.Helper()
	if err != nil {
		t.Fatal(err)
	}
}
