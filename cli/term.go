package main

import (
	"bufio"
	"os"
	"os/exec"
	"strings"
)

// readPasswordNoEcho reads a line from stdin with terminal echo disabled, so the
// master password / app-password never appears on screen. It toggles echo via
// `stty` (present on any POSIX shell) to keep the CLI stdlib-only — no cgo, no
// external Go module, single static binary. Returns ok=false when stdin is not
// an interactive TTY (piped input), so the caller falls back to a plain read.
func readPasswordNoEcho() (string, bool) {
	if _, err := exec.LookPath("stty"); err != nil {
		return "", false
	}
	off := exec.Command("stty", "-echo")
	off.Stdin = os.Stdin
	if err := off.Run(); err != nil {
		return "", false
	}
	defer func() {
		on := exec.Command("stty", "echo")
		on.Stdin = os.Stdin
		_ = on.Run()
	}()

	line, err := bufio.NewReader(os.Stdin).ReadString('\n')
	if err != nil && line == "" {
		return "", false
	}
	return strings.TrimRight(line, "\r\n"), true
}
