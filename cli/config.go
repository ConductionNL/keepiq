package main

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
)

// config is the human-mode session config: the instance URL, Nextcloud user,
// and an APP-PASSWORD (never the login password, §3.1). Stored 0600 in the
// user's config dir. Note: the design mandates the OS keyring; a keyring
// backend is a documented hardening follow-up (it needs cgo/dbus and would
// break the pure-static single-binary build). The master-password-derived key
// is NEVER written here — it lives in-process only.
type config struct {
	URL         string `json:"url"`
	User        string `json:"user"`
	AppPassword string `json:"appPassword"`
}

func configPath() (string, error) {
	dir, err := os.UserConfigDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(dir, "doriath", "config.json"), nil
}

func loadConfig() (*config, error) {
	path, err := configPath()
	if err != nil {
		return nil, err
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	var c config
	if err := json.Unmarshal(data, &c); err != nil {
		return nil, err
	}
	if c.URL == "" || c.User == "" {
		return nil, fmt.Errorf("incomplete config at %s", path)
	}
	return &c, nil
}

func saveConfig(c *config) error {
	path, err := configPath()
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}
	data, _ := json.MarshalIndent(c, "", "  ")
	return os.WriteFile(path, data, 0o600)
}

func cmdLogin(args []string) error {
	url, args := popFlag(args, "--url")
	user, _ := popFlag(args, "--user")
	if url == "" || user == "" {
		return fmt.Errorf("usage: doriath login --url <instance> --user <nc-user>")
	}
	appPassword := promptSecret("Nextcloud app-password (NOT your login password): ")
	if appPassword == "" {
		return fmt.Errorf("an app-password is required")
	}
	if err := saveConfig(&config{URL: url, User: user, AppPassword: appPassword}); err != nil {
		return err
	}
	fmt.Fprintf(os.Stderr, "Logged in to %s as %s (app-password stored).\n", url, user)
	return nil
}
