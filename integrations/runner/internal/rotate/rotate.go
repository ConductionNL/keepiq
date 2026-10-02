// Package rotate changes a credential at its target and only then records it
// in Keepiq (prove-then-record), with a journal so a crash never loses the
// only copy of a new value.
package rotate

import (
	"context"
	"crypto/rand"
	"crypto/rsa"
	"errors"
	"fmt"
	"log/slog"
	"math/big"
	"time"

	keepiq "github.com/ConductionNL/keepiq/sdk/go"
	kcrypto "github.com/ConductionNL/keepiq/sdk/go/crypto"

	"github.com/ConductionNL/keepiq/integrations/runner/internal/config"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/logx"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/state"
)

// Keepiq is the part of the Go library the runner uses.
type Keepiq interface {
	GetByNameIfNoneMatch(name, folder, etag string) (*keepiq.Secret, error)
	UpdateIfMatch(id, etag string, fields map[string]string) (*keepiq.Secret, error)
	PublicKey() *rsa.PublicKey
	Decrypt(ciphertext string) (string, error)
}

// Credential is a target login.
type Credential struct {
	User     string
	Password string
}

// Connector changes and proves a password at one target.
type Connector interface {
	// Set changes user's password from current to next, as the admin.
	Set(ctx context.Context, admin Credential, user, current, next string) error
	// Login proves user can log in with password.
	Login(ctx context.Context, user, password string) error
}

// ConnectorFactory builds the connector of a rotation.
type ConnectorFactory func(r config.Rotation) (Connector, error)

// Errors a rotation can end with.
var (
	// ErrProofFailed: the target took the new value but refused a login with
	// it; the old value was set back and Keepiq is unchanged.
	ErrProofFailed = errors.New("the target refused a login with the new value; the old value was set back")
	// ErrConflict: the secret changed in Keepiq during the rotation (412);
	// the old value was set back at the target and the write was not retried.
	ErrConflict = errors.New("the secret changed in Keepiq during the rotation; the old value was set back at the target")
)

// Rotator runs rotations.
type Rotator struct {
	Keepiq     Keepiq
	State      *state.Dir
	Connectors ConnectorFactory
	Log        *slog.Logger
	Redactor   *logx.Redactor
	Now        func() time.Time

	// AfterTargetSet runs right after the target accepted the new value. Tests
	// use it to stop the process at the worst moment.
	AfterTargetSet func()
}

func (r *Rotator) now() time.Time {
	if r.Now != nil {
		return r.Now()
	}
	return time.Now()
}

func (r *Rotator) forget(v string) {
	if r.Redactor != nil {
		r.Redactor.Forget(v)
	}
}

// Rotate runs one rotation now.
func (r *Rotator) Rotate(ctx context.Context, rot config.Rotation) error {
	log := r.Log.With("secret", rot.Secret, "connector", rot.Connector)
	current, err := r.Keepiq.GetByNameIfNoneMatch(rot.Secret, rot.Folder, "")
	if err != nil {
		return fmt.Errorf("read %s: %w", rot.Secret, err)
	}
	r.forget(current.Key)
	user := rot.Target["user"]
	if user == "" {
		user = current.Login
	}
	if user == "" {
		return fmt.Errorf("%s: no user: set target.user or the secret's login", rot.Secret)
	}
	admin, err := r.admin(rot)
	if err != nil {
		return err
	}
	conn, err := r.Connectors(rot)
	if err != nil {
		return err
	}
	next, err := Generate(rot.Generator)
	if err != nil {
		return err
	}
	r.forget(next)

	entry, err := r.journal(current, rot, user, next)
	if err != nil {
		return fmt.Errorf("journal: %w", err)
	}
	if err := conn.Set(ctx, admin, user, current.Key, next); err != nil {
		_ = r.State.DeleteJournal(current.ID)
		return fmt.Errorf("%s: the target refused the change: %w", rot.Secret, err)
	}
	entry.Stage = "set"
	if err := r.State.PutJournal(entry); err != nil {
		log.Error("could not mark the journal entry; recovery will prove the login instead", "error", err.Error())
	}
	if r.AfterTargetSet != nil {
		r.AfterTargetSet()
	}
	if err := conn.Login(ctx, user, next); err != nil {
		if serr := conn.Set(ctx, admin, user, next, current.Key); serr != nil {
			log.Error("set-back failed; the journal keeps both values encrypted", "error", serr.Error())
			return fmt.Errorf("%s: %w; set-back also failed: %v", rot.Secret, ErrProofFailed, serr)
		}
		_ = r.State.DeleteJournal(current.ID)
		log.Warn("rotation not recorded: the new value did not log in", "error", err.Error())
		return fmt.Errorf("%s: %w", rot.Secret, ErrProofFailed)
	}
	return r.writeBack(ctx, conn, admin, entry, current.Key, next, log)
}

// writeBack records the proven value in Keepiq with If-Match.
func (r *Rotator) writeBack(ctx context.Context, conn Connector, admin Credential, entry state.JournalEntry, old, next string, log *slog.Logger) error {
	_, err := r.Keepiq.UpdateIfMatch(entry.SecretID, entry.ETag, map[string]string{"key": next})
	switch {
	case err == nil:
		if derr := r.State.DeleteJournal(entry.SecretID); derr != nil {
			log.Error("could not remove the journal entry", "error", derr.Error())
		}
		log.Info("rotated", "secretId", entry.SecretID)
		return nil
	case errors.Is(err, keepiq.ErrPreconditionFailed):
		if serr := conn.Set(ctx, admin, entry.User, next, old); serr != nil {
			log.Error("conflict, and the set-back failed; the journal keeps both values encrypted", "error", serr.Error())
			return fmt.Errorf("%s: %w; set-back failed: %v", entry.Name, ErrConflict, serr)
		}
		_ = r.State.DeleteJournal(entry.SecretID)
		log.Error("conflict: the secret changed in Keepiq during the rotation", "secretId", entry.SecretID)
		return fmt.Errorf("%s: %w", entry.Name, ErrConflict)
	default:
		// Keep the journal entry: the next start retries the write-back.
		log.Error("write-back failed; the journal entry stays for the next start", "error", err.Error())
		return fmt.Errorf("%s: write-back: %w", entry.Name, err)
	}
}

func (r *Rotator) journal(current *keepiq.Secret, rot config.Rotation, user, next string) (state.JournalEntry, error) {
	pub := r.Keepiq.PublicKey()
	newCT, err := kcrypto.EncryptField(next, pub)
	if err != nil {
		return state.JournalEntry{}, err
	}
	oldCT, err := kcrypto.EncryptField(current.Key, pub)
	if err != nil {
		return state.JournalEntry{}, err
	}
	e := state.JournalEntry{
		SecretID: current.ID, Name: rot.Secret, Folder: rot.Folder, ETag: current.ETag, User: user,
		NewValue: newCT, OldValue: oldCT, Stage: "pending", CreatedAt: r.now().UTC(),
	}
	return e, r.State.PutJournal(e)
}

func (r *Rotator) admin(rot config.Rotation) (Credential, error) {
	if rot.AdminSecret == "" {
		return Credential{}, nil
	}
	s, err := r.Keepiq.GetByNameIfNoneMatch(rot.AdminSecret, rot.Folder, "")
	if err != nil {
		return Credential{}, fmt.Errorf("read admin secret %s: %w", rot.AdminSecret, err)
	}
	r.forget(s.Key)
	return Credential{User: s.Login, Password: s.Key}, nil
}

// Recover completes the rotations a previous run left in the journal. An
// entry whose new value logs in at the target is written back; one whose new
// value does not (the target never changed, or was set back) is dropped.
func (r *Rotator) Recover(ctx context.Context, rotations []config.Rotation) error {
	entries, err := r.State.Journal()
	if err != nil {
		return err
	}
	var errs []error
	for _, e := range entries {
		log := r.Log.With("secret", e.Name, "secretId", e.SecretID, "recovery", true)
		rot, ok := find(rotations, e.Name, e.Folder)
		if !ok {
			log.Error("journal entry for a rotation no longer configured; left in place")
			errs = append(errs, fmt.Errorf("%s: no rotation configured for the journal entry", e.Name))
			continue
		}
		next, err := r.Keepiq.Decrypt(e.NewValue)
		if err != nil {
			errs = append(errs, fmt.Errorf("%s: journal does not decrypt with this key: %w", e.Name, err))
			continue
		}
		old, err := r.Keepiq.Decrypt(e.OldValue)
		if err != nil {
			errs = append(errs, fmt.Errorf("%s: journal does not decrypt with this key: %w", e.Name, err))
			continue
		}
		r.forget(next)
		r.forget(old)
		conn, err := r.Connectors(rot)
		if err != nil {
			errs = append(errs, err)
			continue
		}
		if err := conn.Login(ctx, e.User, next); err != nil {
			log.Info("the new value never reached the target; dropping the journal entry")
			_ = r.State.DeleteJournal(e.SecretID)
			continue
		}
		admin, err := r.admin(rot)
		if err != nil {
			errs = append(errs, err)
			continue
		}
		log.Info("completing an interrupted rotation")
		if err := r.writeBack(ctx, conn, admin, e, old, next, log); err != nil {
			errs = append(errs, err)
		}
	}
	return errors.Join(errs...)
}

func find(rotations []config.Rotation, name, folder string) (config.Rotation, bool) {
	for _, r := range rotations {
		if r.Secret == name && r.Folder == folder {
			return r, true
		}
	}
	return config.Rotation{}, false
}

const (
	lower   = "abcdefghijkmnopqrstuvwxyz"
	upper   = "ABCDEFGHJKLMNPQRSTUVWXYZ"
	digits  = "23456789"
	symbols = "!#%+,-.:=@^_~"
)

// Generate makes a password from crypto/rand with at least one character of
// every class. The symbol set avoids quotes, backslashes and spaces, so a
// value never needs escaping in a shell, SQL or a connection string.
func Generate(g config.Generator) (string, error) {
	sets := map[string]string{"lower": lower, "upper": upper, "digits": digits, "symbols": symbols}
	var alphabet string
	var required []string
	for _, c := range g.Classes {
		alphabet += sets[c]
		required = append(required, sets[c])
	}
	if alphabet == "" || g.Length < len(required) {
		return "", errors.New("generator: no character classes, or length below the number of classes")
	}
	out := make([]byte, g.Length)
	for i := range out {
		set := alphabet
		if i < len(required) {
			set = required[i]
		}
		n, err := rand.Int(rand.Reader, big.NewInt(int64(len(set))))
		if err != nil {
			return "", err
		}
		out[i] = set[n.Int64()]
	}
	// Shuffle so the required characters are not always first.
	for i := len(out) - 1; i > 0; i-- {
		n, err := rand.Int(rand.Reader, big.NewInt(int64(i+1)))
		if err != nil {
			return "", err
		}
		j := n.Int64()
		out[i], out[j] = out[j], out[i]
	}
	return string(out), nil
}
