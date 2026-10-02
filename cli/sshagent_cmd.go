//go:build !windows

package main

import (
	"errors"
	"fmt"
	"io"
	"net"
	"os"
	"os/signal"
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

func cmdSSHAgent(args []string) error {
	flags, err := parseAgentFlags(args)
	if err != nil {
		return err
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
		if err := a.UnlockWith(promptSecret("Master password: ")); err != nil {
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

func isClosed(err error) bool {
	return errors.Is(err, net.ErrClosed)
}
