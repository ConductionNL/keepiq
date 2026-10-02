// Package sshagent is the SSH agent of the Keepiq CLI (cli-ssh-agent): it
// serves the user's vault `ssh_key` secrets over the OpenSSH agent protocol.
// Keys are decrypted in this process only; the server sees the same ciphertext
// reads `keepiq show` makes, and nothing decrypted is written to disk.
package sshagent

import (
	"errors"
	"fmt"
	"io"

	"golang.org/x/crypto/ssh"

	"github.com/ConductionNL/keepiq/sdk/go/client"
)

// Identity is one usable vault key: the secret's name, its signer and the
// public key derived from the private key.
type Identity struct {
	SecretID string
	Name     string
	Signer   ssh.Signer
}

// BuildKeyring turns the user's secrets into identities: it keeps the secrets
// of the ssh_key type (and of one folder when folderID is set), decrypts each
// `key` field with decrypt, and parses it. A key that cannot be decrypted or
// parsed, or that needs a passphrase, is skipped and named on warn.
func BuildKeyring(secrets []client.Secret, typeID, folderID string, decrypt func(string) (string, error), warn io.Writer) []Identity {
	var out []Identity
	for _, s := range secrets {
		if s.TypeID != typeID {
			continue
		}
		if folderID != "" && s.FolderID != folderID {
			continue
		}
		pem, err := decrypt(s.Key)
		if err != nil {
			fmt.Fprintf(warn, "keepiq ssh-agent: skipped %q: cannot decrypt it\n", s.Name)
			continue
		}
		raw, err := ssh.ParseRawPrivateKey([]byte(pem))
		if err != nil {
			var missing *ssh.PassphraseMissingError
			if errors.As(err, &missing) {
				fmt.Fprintf(warn, "keepiq ssh-agent: skipped %q: it is protected by a passphrase\n", s.Name)
			} else {
				fmt.Fprintf(warn, "keepiq ssh-agent: skipped %q: not an OpenSSH or PEM private key\n", s.Name)
			}
			continue
		}
		signer, err := ssh.NewSignerFromKey(raw)
		if err != nil {
			fmt.Fprintf(warn, "keepiq ssh-agent: skipped %q: unsupported key type\n", s.Name)
			continue
		}
		out = append(out, Identity{SecretID: s.ID, Name: s.Name, Signer: signer})
	}
	return out
}
