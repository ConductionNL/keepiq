//go:build !windows

package main

import (
	"bufio"
	"errors"
	"fmt"
	"io"
	"net"
	"os"
	"os/exec"
	"os/signal"
	"strings"
	"syscall"
	"time"

	"github.com/ConductionNL/keepiq/cli/sshagent"
	"github.com/ConductionNL/keepiq/sdk/go/client"
	dcrypto "github.com/ConductionNL/keepiq/sdk/go/crypto"
)

// vaultUnlocker opens the vault in this process and decrypts the user's
// ssh_key secrets. The suite key is used here and not kept; only the parsed
// SSH keys stay, in the agent's memory.
func vaultUnlocker(folder string, warn io.Writer, open func(string) (*client.Client, *dcrypto.UnlockedSuite, error)) sshagent.Unlocker {
	return func(masterPassword string) ([]sshagent.Identity, error) {
		c, suite, err := open(masterPassword)
		if err != nil {
			return nil, err
		}
		typeID, err := c.SSHKeyTypeID()
		if err != nil {
			return nil, err
		}
		folderID := ""
		if folder != "" {
			if folderID, err = c.FolderIDByName(folder); err != nil {
				return nil, err
			}
		}
		secrets, err := c.ListSecrets()
		if err != nil {
			return nil, err
		}
		decrypt := func(ct string) (string, error) { return dcrypto.DecryptField(ct, suite.Key) }
		return sshagent.BuildKeyring(secrets, typeID, folderID, decrypt, warn), nil
	}
}

// detachedEnv marks the background copy that `keepiq ssh-agent` starts when
// its output goes to `eval "$(...)"` rather than to a terminal.
const detachedEnv = "KEEPIQ_SSH_AGENT_DETACHED"

func cmdSSHAgent(args []string) error {
	flags, err := parseAgentFlags(args)
	if err != nil {
		return err
	}
	detached := os.Getenv(detachedEnv) == "1"
	if shouldDetach(flags, detached, isTerminal(os.Stdout)) {
		return startDetached(args, flags)
	}
	var confirm sshagent.Confirmer
	if flags.Confirm {
		if confirm, err = sshagent.AskpassConfirmer(os.Getenv("SSH_ASKPASS")); err != nil {
			return err
		}
	}
	if err := sshagent.HardenProcess(); err != nil {
		return fmt.Errorf("cannot disable core dumps: %w", err)
	}
	a := sshagent.New(sshagent.Options{
		Unlock:  vaultUnlocker(flags.Folder, os.Stderr, openHumanSession),
		Confirm: confirm,
		Idle:    time.Duration(flags.Idle) * time.Minute,
	})
	if !flags.Locked {
		masterPassword := ""
		if detached {
			// The starting process asked for it and hands it over on stdin.
			line, _ := bufio.NewReader(os.Stdin).ReadString('\n')
			masterPassword = strings.TrimRight(line, "\r\n")
		} else {
			masterPassword = promptSecret("Master password: ")
		}
		if err := a.UnlockWith(masterPassword); err != nil {
			return err
		}
	}

	path := flags.Socket
	if path == "" {
		path = defaultAgentSocket()
	}
	l, err := sshagent.Listen(path)
	if err != nil {
		return err
	}
	defer os.Remove(path)
	fmt.Printf("SSH_AUTH_SOCK=%s; export SSH_AUTH_SOCK;\n", path)
	if detached {
		// Closing stdout tells the starting process the socket is listening.
		os.Stdout.Close()
	}
	if flags.Locked {
		fmt.Fprintln(os.Stderr, "keepiq ssh-agent: locked; run `ssh-add -X` and enter your master password to unlock")
	}

	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)
	go func() {
		<-stop
		l.Close()
	}()
	go func() {
		for range time.Tick(30 * time.Second) {
			a.ExpireIfIdle()
		}
	}()

	logf := func(format string, v ...any) { fmt.Fprintf(os.Stderr, format, v...) }
	if err := sshagent.Serve(l, a, sshagent.PeerUID, logf); err != nil && !isClosed(err) {
		return err
	}
	return nil
}

// startDetached serves `eval "$(keepiq ssh-agent)"`: a command substitution
// waits for its command to end, so the agent itself runs in a background copy
// of this program, in its own session. This process asks for the master
// password on the terminal, passes it to that copy on a pipe, prints the
// exports once the socket listens and returns to the shell.
func startDetached(args []string, flags agentFlags) error {
	exe, err := os.Executable()
	if err != nil {
		return err
	}
	stdin := ""
	if !flags.Locked {
		stdin = promptSecret("Master password: ") + "\n"
	}
	cmd := exec.Command(exe, append([]string{"ssh-agent"}, args...)...)
	cmd.Env = append(os.Environ(), detachedEnv+"=1")
	cmd.Stdin = strings.NewReader(stdin)
	cmd.Stderr = os.Stderr
	cmd.SysProcAttr = &syscall.SysProcAttr{Setsid: true}
	out, err := cmd.StdoutPipe()
	if err != nil {
		return err
	}
	if err := cmd.Start(); err != nil {
		return err
	}
	exports, _ := io.ReadAll(out)
	if !strings.HasPrefix(string(exports), "SSH_AUTH_SOCK=") {
		if werr := cmd.Wait(); werr != nil {
			return fmt.Errorf("the agent did not start: %w", werr)
		}
		return errors.New("the agent did not start")
	}
	fmt.Print(string(exports))
	fmt.Printf("SSH_AGENT_PID=%d; export SSH_AGENT_PID;\n", cmd.Process.Pid)
	return cmd.Process.Release()
}

// shouldDetach is true only for `eval "$(keepiq ssh-agent)"`: stdout is a
// pipe, this is not already the background copy, and --foreground is not set.
// A service manager also gives a stdout that is no terminal (systemd's
// journal), and there the agent must stay the unit's main process.
func shouldDetach(flags agentFlags, detached bool, stdoutIsTerminal bool) bool {
	return !flags.Foreground && !detached && !stdoutIsTerminal
}

// isTerminal reports whether f is a character device, so a terminal and not a
// pipe such as the one `eval "$(...)"` reads.
func isTerminal(f *os.File) bool {
	info, err := f.Stat()
	return err == nil && info.Mode()&os.ModeCharDevice != 0
}

func isClosed(err error) bool {
	return errors.Is(err, net.ErrClosed)
}
