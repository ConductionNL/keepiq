package main

import (
	"encoding/json"
	"fmt"
	"os"
	"strings"
	"time"

	"crypto/rsa"
	"github.com/ConductionNL/keepiq/cli/internal/client"

	dcrypto "github.com/ConductionNL/keepiq/cli/internal/crypto"
)

// ciSetup loads the CI-mode inputs: the instance URL (KEEPIQ_URL), the
// application id (KEEPIQ_APP_ID), and the application private key — supplied by
// env (KEEPIQ_APP_KEY, a PEM) or file (KEEPIQ_APP_KEY_FILE), the operator's own
// credential Keepiq never stores (§4.1). It self-configures from discovery and
// exchanges an RFC 7523 assertion for a bearer token.
func ciSetup() (c *client.Client, key *rsa.PrivateKey, disc *client.Discovery, bearer, appID string, err error) {
	url := os.Getenv("KEEPIQ_URL")
	appID = os.Getenv("KEEPIQ_APP_ID")
	if url == "" || appID == "" {
		return nil, nil, nil, "", "", fmt.Errorf("set KEEPIQ_URL and KEEPIQ_APP_ID")
	}

	pemStr := os.Getenv("KEEPIQ_APP_KEY")
	if pemStr == "" {
		if f := os.Getenv("KEEPIQ_APP_KEY_FILE"); f != "" {
			data, rerr := os.ReadFile(f)
			if rerr != nil {
				return nil, nil, nil, "", "", rerr
			}
			pemStr = string(data)
		}
	}
	if pemStr == "" {
		return nil, nil, nil, "", "", fmt.Errorf("set KEEPIQ_APP_KEY (PEM) or KEEPIQ_APP_KEY_FILE")
	}
	pk, perr := dcrypto.ParsePrivateKey(pemStr)
	if perr != nil {
		return nil, nil, nil, "", "", perr
	}

	c = client.New(url)
	disc, err = c.Discover()
	if err != nil {
		return nil, nil, nil, "", "", err
	}
	bearer, err = c.MachineToken(appID, pk, disc, time.Now().Unix())
	if err != nil {
		return nil, nil, nil, "", "", err
	}
	return c, pk, disc, bearer, appID, nil
}

// fetchDecrypt fetches an application secret by name and decrypts its envelope
// with the application private key (§4.2). Returns the plaintext value.
func fetchDecrypt(c *client.Client, key *rsa.PrivateKey, name, bearer string) (string, error) {
	env, err := c.FetchByName(name, bearer)
	if err != nil {
		return "", err
	}
	if env.Scheme != "rsa-oaep-sha256-chunked-v1" {
		return "", fmt.Errorf("unexpected envelope scheme %q", env.Scheme)
	}
	return dcrypto.DecryptField(env.Payload.Value, key)
}

func cmdCIFetch(args []string) error {
	output, args := popFlag(args, "--output")
	if len(args) < 1 {
		return fmt.Errorf("usage: keepiq ci fetch <name> [--output env|json]")
	}
	c, key, _, bearer, _, err := ciSetup()
	if err != nil {
		return err
	}
	value, err := fetchDecrypt(c, key, args[0], bearer)
	if err != nil {
		return err
	}
	if lease := c.LeaseID(); lease != "" {
		fmt.Fprintf(os.Stderr, "lease %s expires %s\n", lease, c.LeaseExpires())
	}
	switch output {
	case "json":
		return json.NewEncoder(os.Stdout).Encode(map[string]string{"name": args[0], "value": value})
	default: // env
		fmt.Fprintln(os.Stderr, "# WARNING: exporting a secret into the shell environment exposes it to child processes")
		fmt.Printf("export %s=%s\n", envName(args[0]), shellQuote(value))
	}
	return nil
}

func cmdCIRun(args []string) error {
	// Split at the "--" separator: names before, command after.
	sep := -1
	for i, a := range args {
		if a == "--" {
			sep = i
			break
		}
	}
	if sep < 1 || sep+1 >= len(args) {
		return fmt.Errorf("usage: keepiq ci run <name>[,<name>...] -- <cmd...>")
	}
	names := strings.Split(args[0], ",")
	cmd := args[sep+1:]

	c, key, _, bearer, _, err := ciSetup()
	if err != nil {
		return err
	}
	var env []string
	for _, name := range names {
		value, ferr := fetchDecrypt(c, key, strings.TrimSpace(name), bearer)
		if ferr != nil {
			return ferr
		}
		env = append(env, envName(name)+"="+value)
	}
	// Inject into the child environment ONLY — no plaintext to disk (§4.3).
	return runChild(env, cmd)
}

func envName(secretName string) string {
	up := strings.ToUpper(secretName)
	var b strings.Builder
	for _, r := range up {
		if (r >= 'A' && r <= 'Z') || (r >= '0' && r <= '9') {
			b.WriteRune(r)
		} else {
			b.WriteRune('_')
		}
	}
	return "KEEPIQ_" + b.String()
}

func shellQuote(v string) string {
	return "'" + strings.ReplaceAll(v, "'", `'\''`) + "'"
}
