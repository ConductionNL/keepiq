package main

import (
	"fmt"
	"os"
	"os/exec"
	"strconv"
	"strings"
	"time"
)

// clipboardTools are the platform copy commands tried in order: macOS, Windows
// (native + WSL bridge), Wayland, X11. First one on PATH wins.
var clipboardTools = [][]string{
	{"pbcopy"},
	{"clip.exe"},
	{"clip"},
	{"wl-copy"},
	{"xclip", "-selection", "clipboard"},
	{"xsel", "--clipboard", "--input"},
}

func writeClipboard(value string) ([]string, error) {
	for _, tool := range clipboardTools {
		if _, err := exec.LookPath(tool[0]); err != nil {
			continue
		}
		cmd := exec.Command(tool[0], tool[1:]...)
		cmd.Stdin = strings.NewReader(value)
		if err := cmd.Run(); err != nil {
			return nil, err
		}
		return tool, nil
	}
	return nil, fmt.Errorf("no clipboard tool found (tried pbcopy/clip/wl-copy/xclip/xsel)")
}

func cmdCopy(args []string) error {
	clearAfter, args := popFlag(args, "--clear-after")
	if len(args) < 2 {
		return fmt.Errorf("usage: doriath copy <id> <field> [--clear-after <seconds>]")
	}
	seconds := 30
	if clearAfter != "" {
		if n, err := strconv.Atoi(clearAfter); err == nil && n > 0 {
			seconds = n
		}
	}

	c, session, err := openHumanSession(promptSecret("Master password: "))
	if err != nil {
		return err
	}
	s, err := c.GetSecret(args[0])
	if err != nil {
		return err
	}
	fields := decryptSecret(s, session)
	v, ok := fields[args[1]]
	if !ok {
		return fmt.Errorf("no such field %q (have: name, url, key, login)", args[1])
	}

	tool, err := writeClipboard(v)
	if err != nil {
		return err
	}
	fmt.Fprintf(os.Stderr, "Copied %s to clipboard via %s — clearing in %ds.\n", args[1], tool[0], seconds)

	// Block for the interval, then overwrite the clipboard so the secret does
	// not linger (§3.4). Only clear if the clipboard still holds our value would
	// require a read-back tool; we unconditionally clear, which is the safe default.
	time.Sleep(time.Duration(seconds) * time.Second)
	if _, err := writeClipboard(""); err != nil {
		return fmt.Errorf("clipboard cleared failed: %w", err)
	}
	fmt.Fprintln(os.Stderr, "Clipboard cleared.")
	return nil
}
