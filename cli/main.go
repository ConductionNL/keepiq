// Command keepiq is the Keepiq command-line client (keepiq-cli).
//
// It serves the shell audience over the SAME server surfaces the browser and
// the openconnector machine consumer already use — nothing new server-side.
// Human mode authenticates with a Nextcloud app-password and unlocks by
// deriving the master-password key and unwrapping the EncryptionSuite private
// key IN THIS PROCESS; every decryption is client-side, so no plaintext key
// material ever reaches the server (ADR-003). CI mode is an RFC 7523 machine
// consumer holding the application private key.
//
// v1 is strictly READ-ONLY (keepiq-cli §5): a write in Keepiq's model
// requires re-wrapping the value under every recipient's public key (the share
// fan-out), which is a separate, larger surface deferred to a follow-up.
//
// Single static binary, stdlib only — cross-compile with GOOS/GOARCH.
package main

import (
	"bufio"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"strings"

	"github.com/ConductionNL/keepiq/cli/internal/client"
	dcrypto "github.com/ConductionNL/keepiq/cli/internal/crypto"
)

// version is stamped at build time via -ldflags "-X main.version=…".
var version = "dev"

func main() {
	if len(os.Args) < 2 {
		usage()
		os.Exit(2)
	}
	cmd := os.Args[1]
	args := os.Args[2:]
	var err error
	switch cmd {
	case "version", "--version", "-v":
		fmt.Printf("keepiq %s\n", version)
	case "login":
		err = cmdLogin(args)
	case "list":
		err = cmdList(args)
	case "show":
		err = cmdShow(args)
	case "get":
		err = cmdGet(args)
	case "copy":
		err = cmdCopy(args)
	case "ci":
		err = cmdCI(args)
	case "completion":
		err = cmdCompletion(args)
	case "help", "--help", "-h":
		usage()
	default:
		fmt.Fprintf(os.Stderr, "unknown command %q\n\n", cmd)
		usage()
		os.Exit(2)
	}
	if err != nil {
		fmt.Fprintf(os.Stderr, "error: %v\n", err)
		os.Exit(1)
	}
}

func usage() {
	fmt.Fprint(os.Stderr, `keepiq — zero-knowledge secrets CLI (read-only v1)

Human mode (decrypts client-side):
  keepiq login --url <instance> --user <nc-user>   store the instance + app-password
  keepiq list [--output json|table]                list your secrets (metadata only)
  keepiq show <id> [--output json]                 reveal one secret (all fields)
  keepiq get <id> <field>                          print one field (key|login|url|name)
  keepiq copy <id> <field> [--clear-after <sec>]   copy a field to the clipboard, auto-clear

CI mode (RFC 7523 machine consumer):
  keepiq ci fetch <name> [--output env|json]       fetch+decrypt an application secret
  keepiq ci run <name>[,<name>...] -- <cmd...>      run <cmd> with the secret(s) in its env

  version | completion <bash|zsh|fish> | help

v1 is READ-ONLY: no create/edit/update/delete (share fan-out is a follow-up).
The master password is prompted per session and never leaves this process.
`)
}

// --- flag helpers (stdlib only, minimal) ---

func popFlag(args []string, name string) (string, []string) {
	out := make([]string, 0, len(args))
	val := ""
	for i := 0; i < len(args); i++ {
		if args[i] == name && i+1 < len(args) {
			val = args[i+1]
			i++
			continue
		}
		if strings.HasPrefix(args[i], name+"=") {
			val = strings.TrimPrefix(args[i], name+"=")
			continue
		}
		out = append(out, args[i])
	}
	return val, out
}

func prompt(label string) string {
	fmt.Fprint(os.Stderr, label)
	r := bufio.NewReader(os.Stdin)
	line, _ := r.ReadString('\n')
	return strings.TrimRight(line, "\r\n")
}

func promptSecret(label string) string {
	// Read without echo where possible; falls back to a visible read.
	fmt.Fprint(os.Stderr, label)
	if pw, ok := readPasswordNoEcho(); ok {
		fmt.Fprintln(os.Stderr)
		return pw
	}
	r := bufio.NewReader(os.Stdin)
	line, _ := r.ReadString('\n')
	return strings.TrimRight(line, "\r\n")
}

// --- human mode ---

func openHumanSession(masterPassword string) (*client.Client, *dcrypto.UnlockedSuite, error) {
	cfg, err := loadConfig()
	if err != nil {
		return nil, nil, fmt.Errorf("not logged in (run `keepiq login`): %w", err)
	}
	c := client.New(cfg.URL)
	c.WithAppPassword(cfg.User, cfg.AppPassword)

	suite, err := c.ActiveSuite()
	if err != nil {
		return nil, nil, err
	}
	pem, err := dcrypto.UnwrapPrivateKey(suite.PrivateKey, masterPassword)
	if err != nil {
		return nil, nil, err
	}
	key, err := dcrypto.ParsePrivateKey(pem)
	if err != nil {
		return nil, nil, err
	}
	// Fast fail on a key/envelope mismatch before touching any secret (§2.2).
	if err := dcrypto.PublicKeyMatchesPrivate(suite.Certificate, key); err != nil {
		return nil, nil, err
	}
	return c, &dcrypto.UnlockedSuite{Key: key, Certificate: suite.Certificate}, nil
}

func cmdList(args []string) error {
	output, _ := popFlag(args, "--output")
	c, _, err := openHumanSession(promptSecret("Master password: "))
	if err != nil {
		return err
	}
	secrets, err := c.ListSecrets()
	if err != nil {
		return err
	}
	if output == "json" {
		return emitJSON(secrets)
	}
	fmt.Printf("%-38s  %s\n", "ID", "NAME")
	for _, s := range secrets {
		fmt.Printf("%-38s  %s\n", s.ID, s.Name)
	}
	return nil
}

func cmdShow(args []string) error {
	output, args := popFlag(args, "--output")
	if len(args) < 1 {
		return fmt.Errorf("usage: keepiq show <id>")
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
	if output == "json" {
		return emitJSON(fields)
	}
	for k, v := range fields {
		fmt.Printf("%-10s %s\n", k+":", v)
	}
	return nil
}

func cmdGet(args []string) error {
	if len(args) < 2 {
		return fmt.Errorf("usage: keepiq get <id> <field>")
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
	fmt.Println(v)
	return nil
}

// decryptSecret decrypts a secret's ciphertext fields IN PROCESS.
func decryptSecret(s *client.Secret, session *dcrypto.UnlockedSuite) map[string]string {
	out := map[string]string{"id": s.ID, "name": s.Name, "url": s.URL}
	if s.Key != "" {
		if v, err := dcrypto.DecryptField(s.Key, session.Key); err == nil {
			out["key"] = v
		}
	}
	if s.Login != "" {
		if v, err := dcrypto.DecryptField(s.Login, session.Key); err == nil {
			out["login"] = v
		}
	}
	return out
}

func emitJSON(v any) error {
	enc := json.NewEncoder(os.Stdout)
	enc.SetIndent("", "  ")
	return enc.Encode(v)
}

// --- CI mode ---

func cmdCI(args []string) error {
	if len(args) < 1 {
		return fmt.Errorf("usage: keepiq ci <fetch|run> …")
	}
	switch args[0] {
	case "fetch":
		return cmdCIFetch(args[1:])
	case "run":
		return cmdCIRun(args[1:])
	default:
		return fmt.Errorf("unknown ci subcommand %q", args[0])
	}
}

func cmdCompletion(args []string) error {
	shell := "bash"
	if len(args) > 0 {
		shell = args[0]
	}
	// A minimal, valid completion script per shell (§1.3).
	switch shell {
	case "bash":
		fmt.Print("complete -W 'login list show get copy ci version completion help' keepiq\n")
	case "zsh":
		fmt.Print("#compdef keepiq\ncompadd login list show get copy ci version completion help\n")
	case "fish":
		fmt.Print("complete -c keepiq -a 'login list show get copy ci version completion help'\n")
	default:
		return fmt.Errorf("unsupported shell %q (bash|zsh|fish)", shell)
	}
	return nil
}

func runChild(env []string, cmd []string) error {
	if len(cmd) == 0 {
		return fmt.Errorf("no command after --")
	}
	child := exec.Command(cmd[0], cmd[1:]...)
	child.Env = append(os.Environ(), env...)
	child.Stdin, child.Stdout, child.Stderr = os.Stdin, os.Stdout, os.Stderr
	return child.Run()
}
