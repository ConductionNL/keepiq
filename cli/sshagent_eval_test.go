//go:build !windows

package main

import (
	"context"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"syscall"
	"testing"
	"time"

	"golang.org/x/crypto/ssh/agent"
)

// TestMain lets a test run this binary as the keepiq command itself: with
// KEEPIQ_TEST_RUN_MAIN set, the process is `keepiq <os.Args[1:]...>`.
func TestMain(m *testing.M) {
	if os.Getenv("KEEPIQ_TEST_RUN_MAIN") == "1" {
		main()
		os.Exit(0)
	}
	os.Exit(m.Run())
}

// `eval "$(keepiq ssh-agent)"` must return to the shell with the agent still
// serving, as the README and the spec scenario promise. Found running the
// agent by hand on Linux (keepiq#786): the agent served in the foreground, so
// the command substitution never returned and the shell hung.
func TestEvalReturnsAndTheAgentKeepsServing(t *testing.T) {
	dir, err := os.MkdirTemp("", "kq-agent-")
	if err != nil {
		t.Fatal(err)
	}
	defer os.RemoveAll(dir)
	sock := filepath.Join(dir, "keepiq", "agent.sock")

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	cmd := exec.CommandContext(ctx, os.Args[0], "ssh-agent", "--locked", "--socket", sock)
	cmd.Env = append(os.Environ(), "KEEPIQ_TEST_RUN_MAIN=1")
	// A file, not a buffer: a buffer would make Output wait for every holder
	// of the pipe, the background agent included.
	cmd.Stderr = os.Stderr
	out, err := cmd.Output()
	if ctx.Err() != nil {
		t.Fatal("keepiq ssh-agent did not return; `eval \"$(keepiq ssh-agent)\"` would hang the shell")
	}
	if err != nil {
		t.Fatalf("keepiq ssh-agent failed: %v", err)
	}

	m := regexp.MustCompile(`SSH_AGENT_PID=(\d+); export SSH_AGENT_PID;`).FindStringSubmatch(string(out))
	if m == nil {
		t.Fatalf("no SSH_AGENT_PID export in %q", out)
	}
	pid, _ := strconv.Atoi(m[1])
	defer syscall.Kill(pid, syscall.SIGTERM)
	if !strings.Contains(string(out), "SSH_AUTH_SOCK="+sock+"; export SSH_AUTH_SOCK;") {
		t.Fatalf("no SSH_AUTH_SOCK export for %s in %q", sock, out)
	}

	conn, err := net.Dial("unix", sock)
	if err != nil {
		t.Fatalf("the agent is not serving after eval returned: %v", err)
	}
	defer conn.Close()
	keys, err := agent.NewClient(conn).List()
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(keys) != 0 {
		t.Fatalf("a locked agent offered %d keys", len(keys))
	}
}
