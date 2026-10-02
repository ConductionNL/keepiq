package main

import (
	"encoding/json"
	"fmt"
	"os"
	"strings"

	keepiq "github.com/ConductionNL/keepiq/sdk/go"
)

// ciSetup loads the CI-mode inputs: the instance URL (KEEPIQ_URL), the
// application id (KEEPIQ_APP_ID), and the application private key — supplied by
// env (KEEPIQ_APP_KEY, a PEM) or file (KEEPIQ_APP_KEY_FILE), the operator's own
// credential Keepiq never stores (§4.1). The returned client is the Go library
// (sdk/go): it self-configures from discovery and exchanges an RFC 7523
// assertion for a bearer token on first use.
func ciSetup() (*keepiq.Client, error) {
	url := os.Getenv("KEEPIQ_URL")
	appID := os.Getenv("KEEPIQ_APP_ID")
	if url == "" || appID == "" {
		return nil, fmt.Errorf("set KEEPIQ_URL and KEEPIQ_APP_ID")
	}

	pemStr := os.Getenv("KEEPIQ_APP_KEY")
	if pemStr == "" {
		if f := os.Getenv("KEEPIQ_APP_KEY_FILE"); f != "" {
			data, rerr := os.ReadFile(f)
			if rerr != nil {
				return nil, rerr
			}
			pemStr = string(data)
		}
	}
	if pemStr == "" {
		return nil, fmt.Errorf("set KEEPIQ_APP_KEY (PEM) or KEEPIQ_APP_KEY_FILE")
	}
	return keepiq.New(url, appID, pemStr)
}

// fetchDecrypt fetches an application secret by name and decrypts its envelope
// with the application private key (§4.2), in this process. The library refuses
// any scheme other than rsa-oaep-sha256-chunked-v1 before decrypting.
func fetchDecrypt(c *keepiq.Client, name string) (*keepiq.Secret, error) {
	return c.GetByName(name, "")
}

func cmdCIFetch(args []string) error {
	output, args := popFlag(args, "--output")
	if len(args) < 1 {
		return fmt.Errorf("usage: keepiq ci fetch <name> [--output env|json]")
	}
	c, err := ciSetup()
	if err != nil {
		return err
	}
	secret, err := fetchDecrypt(c, args[0])
	if err != nil {
		return err
	}
	value := secret.Key
	if secret.Lease != nil {
		fmt.Fprintf(os.Stderr, "lease %s expires %s\n", secret.Lease.ID, secret.Lease.Expires)
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

	c, err := ciSetup()
	if err != nil {
		return err
	}
	var env []string
	for _, name := range names {
		secret, ferr := fetchDecrypt(c, strings.TrimSpace(name))
		if ferr != nil {
			return ferr
		}
		env = append(env, envName(name)+"="+secret.Key)
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
