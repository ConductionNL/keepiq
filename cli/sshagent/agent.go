package sshagent

import (
	"bytes"
	"crypto/rand"
	"errors"
	"os"
	"os/exec"
	"sync"
	"time"

	"golang.org/x/crypto/ssh"
	"golang.org/x/crypto/ssh/agent"
)

// Unlocker opens the vault with the master password and returns the keys.
type Unlocker func(masterPassword string) ([]Identity, error)

// Confirmer asks the user to allow one signature with the named key.
type Confirmer func(keyName string) bool

// Errors the agent answers with. The protocol carries only "failure"; these
// are for logs and tests.
var (
	ErrLocked        = errors.New("the agent is locked")
	ErrVaultOnly     = errors.New("keys come from the Keepiq vault; add or remove them there")
	ErrUnknownKey    = errors.New("no such key in the agent")
	ErrSHA1Refused   = errors.New("ssh-rsa (SHA-1) signatures are refused; use rsa-sha2-256 or rsa-sha2-512")
	ErrNotConfirmed  = errors.New("the signature was not confirmed")
	ErrNoAskpass     = errors.New("--confirm needs SSH_ASKPASS to point at a confirmation program")
	errNotSupported  = errors.New("not supported")
)

// Agent serves vault keys. It implements agent.ExtendedAgent.
type Agent struct {
	mu       sync.Mutex
	keys     []Identity
	locked   bool
	unlock   Unlocker
	confirm  Confirmer
	idle     time.Duration
	now      func() time.Time
	lastUsed time.Time
}

// Options configure an Agent.
type Options struct {
	// Unlock opens the vault; required.
	Unlock Unlocker
	// Confirm, when set, is asked before every signature.
	Confirm Confirmer
	// Idle drops every key after this long without a sign request; 0 disables.
	Idle time.Duration
	// Now is the clock (tests inject one).
	Now func() time.Time
}

// New returns a locked agent.
func New(o Options) *Agent {
	now := o.Now
	if now == nil {
		now = time.Now
	}
	return &Agent{locked: true, unlock: o.Unlock, confirm: o.Confirm, idle: o.Idle, now: now}
}

// UnlockWith opens the vault with the master password (terminal start or
// `ssh-add -X`).
func (a *Agent) UnlockWith(masterPassword string) error {
	keys, err := a.unlock(masterPassword)
	if err != nil {
		return err
	}
	a.mu.Lock()
	defer a.mu.Unlock()
	a.keys = keys
	a.locked = false
	a.lastUsed = a.now()
	return nil
}

// dropLocked clears every decrypted key. Callers hold mu.
func (a *Agent) dropLocked() {
	a.keys = nil
	a.locked = true
}

// ExpireIfIdle locks the agent when the idle period has passed since the last
// signature. The serve loop calls it on a timer and before every request.
func (a *Agent) ExpireIfIdle() {
	a.mu.Lock()
	defer a.mu.Unlock()
	a.expireLocked()
}

func (a *Agent) expireLocked() {
	if a.locked || a.idle <= 0 {
		return
	}
	if a.now().Sub(a.lastUsed) >= a.idle {
		a.dropLocked()
	}
}

// Locked reports whether the agent holds no keys.
func (a *Agent) Locked() bool {
	a.mu.Lock()
	defer a.mu.Unlock()
	a.expireLocked()
	return a.locked
}

// List answers `ssh-add -l`: the vault keys, or nothing while locked.
func (a *Agent) List() ([]*agent.Key, error) {
	a.mu.Lock()
	defer a.mu.Unlock()
	a.expireLocked()
	if a.locked {
		return nil, nil
	}
	out := make([]*agent.Key, 0, len(a.keys))
	for _, k := range a.keys {
		pub := k.Signer.PublicKey()
		out = append(out, &agent.Key{Format: pub.Type(), Blob: pub.Marshal(), Comment: k.Name})
	}
	return out, nil
}

// Sign signs with default flags.
func (a *Agent) Sign(key ssh.PublicKey, data []byte) (*ssh.Signature, error) {
	return a.SignWithFlags(key, data, 0)
}

// SignWithFlags signs with the named key. RSA needs a SHA-2 flag.
func (a *Agent) SignWithFlags(key ssh.PublicKey, data []byte, flags agent.SignatureFlags) (*ssh.Signature, error) {
	a.mu.Lock()
	a.expireLocked()
	if a.locked {
		a.mu.Unlock()
		return nil, ErrLocked
	}
	var id *Identity
	want := key.Marshal()
	for i := range a.keys {
		if bytes.Equal(a.keys[i].Signer.PublicKey().Marshal(), want) {
			id = &a.keys[i]
			break
		}
	}
	confirm := a.confirm
	a.mu.Unlock()
	if id == nil {
		return nil, ErrUnknownKey
	}

	algorithm := ""
	if id.Signer.PublicKey().Type() == ssh.KeyAlgoRSA {
		switch {
		case flags&agent.SignatureFlagRsaSha512 != 0:
			algorithm = ssh.KeyAlgoRSASHA512
		case flags&agent.SignatureFlagRsaSha256 != 0:
			algorithm = ssh.KeyAlgoRSASHA256
		default:
			return nil, ErrSHA1Refused
		}
	}

	if confirm != nil && !confirm(id.Name) {
		return nil, ErrNotConfirmed
	}

	var sig *ssh.Signature
	var err error
	if algorithm != "" {
		as, ok := id.Signer.(ssh.AlgorithmSigner)
		if !ok {
			return nil, errNotSupported
		}
		sig, err = as.SignWithAlgorithm(rand.Reader, data, algorithm)
	} else {
		sig, err = id.Signer.Sign(rand.Reader, data)
	}
	if err != nil {
		return nil, err
	}
	a.mu.Lock()
	a.lastUsed = a.now()
	a.mu.Unlock()
	return sig, nil
}

// Add is refused: the vault is the only source.
func (a *Agent) Add(agent.AddedKey) error { return ErrVaultOnly }

// Remove is refused: the vault is the only source.
func (a *Agent) Remove(ssh.PublicKey) error { return ErrVaultOnly }

// RemoveAll is refused: the vault is the only source.
func (a *Agent) RemoveAll() error { return ErrVaultOnly }

// Lock answers `ssh-add -x`: every decrypted key is dropped.
func (a *Agent) Lock([]byte) error {
	a.mu.Lock()
	defer a.mu.Unlock()
	a.dropLocked()
	return nil
}

// Unlock answers `ssh-add -X`: the passphrase is the master password.
func (a *Agent) Unlock(passphrase []byte) error {
	return a.UnlockWith(string(passphrase))
}

// Signers is not offered over the protocol.
func (a *Agent) Signers() ([]ssh.Signer, error) { return nil, errNotSupported }

// Extension: none supported.
func (a *Agent) Extension(string, []byte) ([]byte, error) {
	return nil, agent.ErrExtensionUnsupported
}

// AskpassConfirmer asks through the SSH_ASKPASS program, as OpenSSH's own
// agent does for `ssh-add -c` keys: exit status 0 allows the signature.
func AskpassConfirmer(program string) (Confirmer, error) {
	if program == "" {
		return nil, ErrNoAskpass
	}
	return func(keyName string) bool {
		cmd := exec.Command(program, "Allow use of the Keepiq key \""+keyName+"\"?")
		cmd.Env = append(os.Environ(), "SSH_ASKPASS_PROMPT=confirm")
		return cmd.Run() == nil
	}, nil
}

var _ agent.ExtendedAgent = (*Agent)(nil)
