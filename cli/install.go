package main

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
)

// cmdInstall copies this binary to a path, so an init container from the
// distroless CLI image (which has no shell and no cp) can put keepiq into a
// shared volume for the app container's `keepiq ci run` wrapper.
func cmdInstall(args []string) error {
	if len(args) != 1 {
		return fmt.Errorf("usage: keepiq install <path>")
	}
	self, err := os.Executable()
	if err != nil {
		return err
	}
	return copyExecutable(self, args[0])
}

func copyExecutable(src, dst string) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	if err := os.MkdirAll(filepath.Dir(dst), 0o755); err != nil {
		return err
	}
	tmp := dst + ".tmp"
	out, err := os.OpenFile(tmp, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o755)
	if err != nil {
		return err
	}
	if _, err := io.Copy(out, in); err != nil {
		out.Close()
		os.Remove(tmp)
		return err
	}
	if err := out.Close(); err != nil {
		os.Remove(tmp)
		return err
	}
	return os.Rename(tmp, dst)
}
