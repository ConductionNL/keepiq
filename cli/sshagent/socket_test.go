//go:build !windows

package sshagent

import (
	"net"
	"os"
	"path/filepath"
	"runtime"
	"testing"
	"time"

	"golang.org/x/crypto/ssh/agent"
)

func shortTempDir(t *testing.T) string {
	t.Helper()
	// Unix socket paths are short (104 bytes on macOS); t.TempDir can be long.
	dir, err := os.MkdirTemp("/tmp", "kq")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.RemoveAll(dir) })
	return dir
}

func TestSocketDirectoryMustBePrivate(t *testing.T) {
	base := shortTempDir(t)
	open := filepath.Join(base, "open")
	if err := os.Mkdir(open, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(open, 0o755); err != nil {
		t.Fatal(err)
	}
	if _, err := Listen(filepath.Join(open, "agent.sock")); err == nil {
		t.Fatal("a directory open to other users must be refused")
	}

	fresh := filepath.Join(base, "fresh")
	l, err := Listen(filepath.Join(fresh, "agent.sock"))
	if err != nil {
		t.Fatal(err)
	}
	defer l.Close()
	dirInfo, _ := os.Stat(fresh)
	sockInfo, _ := os.Stat(filepath.Join(fresh, "agent.sock"))
	if dirInfo.Mode().Perm() != 0o700 || sockInfo.Mode().Perm() != 0o600 {
		t.Fatalf("modes: dir %o, socket %o", dirInfo.Mode().Perm(), sockInfo.Mode().Perm())
	}
}

func serveWith(t *testing.T, peer PeerUIDFunc) string {
	t.Helper()
	path := filepath.Join(shortTempDir(t), "s", "agent.sock")
	l, err := Listen(path)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { l.Close() })
	a, _ := newAgent(t, Options{})
	go func() { _ = Serve(l, a, peer, nil) }()
	return path
}

func TestAForeignPeerIsClosedWithoutAnAnswer(t *testing.T) {
	path := serveWith(t, func(net.Conn) (int, error) { return os.Getuid() + 1, nil })
	conn, err := net.Dial("unix", path)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	_ = conn.SetDeadline(time.Now().Add(5 * time.Second))
	if keys, err := agent.NewClient(conn).List(); err == nil {
		t.Fatalf("a foreign peer got an answer: %d keys", len(keys))
	}
}

func TestTheOwnUserIsServed(t *testing.T) {
	if runtime.GOOS != "linux" && runtime.GOOS != "darwin" {
		t.Skip("peer credentials only on Linux and macOS")
	}
	path := serveWith(t, PeerUID)
	conn, err := net.Dial("unix", path)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	keys, err := agent.NewClient(conn).List()
	if err != nil || len(keys) != 5 {
		t.Fatalf("own user: %d keys, %v", len(keys), err)
	}
}
