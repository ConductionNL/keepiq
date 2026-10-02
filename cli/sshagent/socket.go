//go:build !windows

package sshagent

import (
	"errors"
	"fmt"
	"io"
	"net"
	"os"
	"path/filepath"
	"syscall"

	"golang.org/x/crypto/ssh/agent"
)

// PrepareSocketDir creates the socket directory with mode 0700, or checks an
// existing one: it must belong to this user and be closed to everyone else.
func PrepareSocketDir(dir string) error {
	info, err := os.Lstat(dir)
	if errors.Is(err, os.ErrNotExist) {
		return os.MkdirAll(dir, 0o700)
	}
	if err != nil {
		return err
	}
	if !info.IsDir() {
		return fmt.Errorf("%s is not a directory", dir)
	}
	st, ok := info.Sys().(*syscall.Stat_t)
	if !ok || int(st.Uid) != os.Getuid() {
		return fmt.Errorf("%s belongs to another user; refusing to put the agent socket there", dir)
	}
	if info.Mode().Perm()&0o077 != 0 {
		return fmt.Errorf("%s is open to other users (mode %o); it must be 0700", dir, info.Mode().Perm())
	}
	return nil
}

// Listen prepares the directory, replaces a stale socket and listens with
// mode 0600.
func Listen(path string) (net.Listener, error) {
	if err := PrepareSocketDir(filepath.Dir(path)); err != nil {
		return nil, err
	}
	if info, err := os.Lstat(path); err == nil {
		if info.Mode()&os.ModeSocket == 0 {
			return nil, fmt.Errorf("%s exists and is not a socket", path)
		}
		_ = os.Remove(path)
	}
	old := syscall.Umask(0o177)
	l, err := net.Listen("unix", path)
	syscall.Umask(old)
	if err != nil {
		return nil, err
	}
	if err := os.Chmod(path, 0o600); err != nil {
		l.Close()
		return nil, err
	}
	return l, nil
}

// PeerUIDFunc returns the uid of the process at the other end of a connection.
type PeerUIDFunc func(net.Conn) (int, error)

// Serve accepts connections until the listener closes. A connection whose
// peer is another user, or whose peer cannot be identified, is closed before
// any request is read.
func Serve(l net.Listener, a *Agent, peerUID PeerUIDFunc, logf func(string, ...any)) error {
	self := os.Getuid()
	for {
		conn, err := l.Accept()
		if err != nil {
			return err
		}
		uid, err := peerUID(conn)
		if err != nil || uid != self {
			if logf != nil {
				logf("keepiq ssh-agent: refused a connection from another user\n")
			}
			conn.Close()
			continue
		}
		go func(c net.Conn) {
			defer c.Close()
			a.ExpireIfIdle()
			if err := agent.ServeAgent(a, c); err != nil && !errors.Is(err, io.EOF) && logf != nil {
				logf("keepiq ssh-agent: %v\n", err)
			}
		}(conn)
	}
}
