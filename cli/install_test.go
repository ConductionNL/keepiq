package main

import (
	"os"
	"path/filepath"
	"testing"
)

// TestCopyExecutable: `keepiq install <path>` writes an identical, executable
// copy, creating the directory.
func TestCopyExecutable(t *testing.T) {
	dir := t.TempDir()
	src := filepath.Join(dir, "src")
	if err := os.WriteFile(src, []byte("binary bytes"), 0o700); err != nil {
		t.Fatal(err)
	}
	dst := filepath.Join(dir, "shared", "keepiq")
	if err := copyExecutable(src, dst); err != nil {
		t.Fatal(err)
	}
	got, err := os.ReadFile(dst)
	if err != nil || string(got) != "binary bytes" {
		t.Fatalf("copy = %q, %v", got, err)
	}
	if fi, _ := os.Stat(dst); fi.Mode().Perm()&0o111 == 0 {
		t.Fatalf("copy is not executable: %v", fi.Mode())
	}
	if err := cmdInstall(nil); err == nil {
		t.Fatal("install without a path must fail")
	}
}
