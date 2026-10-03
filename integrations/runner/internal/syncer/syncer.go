// Package syncer pushes changed Keepiq secrets to cloud secret stores. It
// polls updated_since, reads each changed secret of a sync set, decrypts it in
// the runner, and pushes it. Per destination entry it keeps only the ETag it
// last pushed, so an unchanged secret is never pushed again.
package syncer

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"time"

	keepiq "github.com/ConductionNL/keepiq/sdk/go"

	"github.com/ConductionNL/keepiq/integrations/runner/internal/config"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/logx"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/state"
)

// Keepiq is the part of the Go library the syncer uses.
type Keepiq interface {
	List(updatedSince time.Time) ([]*keepiq.Secret, error)
	GetByNameIfNoneMatch(name, folder, etag string) (*keepiq.Secret, error)
}

// Destination stores one value under one name.
type Destination interface {
	Push(ctx context.Context, name, value string) error
}

// DestinationFactory builds the destination of a sync set; creds is the
// decrypted credentials secret, or nil for the ambient identity.
type DestinationFactory func(s config.Sync, creds *keepiq.Secret) (Destination, error)

// Syncer runs sync sets.
type Syncer struct {
	Keepiq       Keepiq
	State        *state.Dir
	Destinations DestinationFactory
	Log          *slog.Logger
	Redactor     *logx.Redactor
	Now          func() time.Time
}

// Backoff after n consecutive failures: 30s, 1m, 2m … capped at 30m.
func Backoff(n int) time.Duration {
	d := 30 * time.Second
	for i := 1; i < n && d < 30*time.Minute; i++ {
		d *= 2
	}
	if d > 30*time.Minute {
		d = 30 * time.Minute
	}
	return d
}

// overlap re-reads a little before the last poll, so a write that lands in
// the same second as the poll is not missed; the ETag check makes it free.
const overlap = 5 * time.Second

// RunOnce polls and pushes one sync set. A failed push is retried later with
// backoff and never blocks the other entries.
func (s *Syncer) RunOnce(ctx context.Context, sc config.Sync) error {
	now := s.Now()
	st, err := s.State.LoadSync(sc.Name)
	if err != nil {
		return err
	}
	log := s.Log.With("sync", sc.Name, "destination", sc.Destination)

	since := time.Time{}
	if !st.LastPoll.IsZero() {
		since = st.LastPoll.Add(-overlap)
	}
	changed, err := s.Keepiq.List(since)
	if err != nil {
		return fmt.Errorf("sync %s: list: %w", sc.Name, err)
	}
	wanted := map[string]bool{}
	for _, n := range sc.Secrets {
		wanted[n] = true
	}
	for _, c := range changed {
		if wanted[c.Name] && (sc.Folder == "" || c.FolderPath == sc.Folder) {
			e := st.Entries[c.Name]
			e.Dirty = true
			st.Entries[c.Name] = e
		}
	}
	// A name never pushed yet is dirty too (first run, or newly configured).
	for _, n := range sc.Secrets {
		if e, ok := st.Entries[n]; !ok || e.PushedETag == "" {
			e.Dirty = true
			st.Entries[n] = e
		}
	}

	var dest Destination
	var errs []error
	for _, name := range sc.Secrets {
		e := st.Entries[name]
		if !e.Dirty || now.Before(e.NextAttempt) {
			continue
		}
		secret, err := s.Keepiq.GetByNameIfNoneMatch(name, sc.Folder, e.PushedETag)
		if errors.Is(err, keepiq.ErrNotModified) {
			e.Dirty = false
			st.Entries[name] = e
			continue
		}
		if err == nil && dest == nil {
			dest, err = s.destination(sc)
		}
		if err == nil {
			s.forget(secret.Key)
			err = dest.Push(ctx, name, secret.Key)
		}
		if err != nil {
			e.Failures++
			e.NextAttempt = now.Add(Backoff(e.Failures))
			st.Entries[name] = e
			log.Error("push failed; retrying later", "secret", name, "failures", e.Failures, "retryAt", e.NextAttempt.Format(time.RFC3339), "error", err.Error())
			errs = append(errs, fmt.Errorf("%s: %w", name, err))
			continue
		}
		st.Entries[name] = state.EntryState{PushedETag: secret.ETag}
		log.Info("pushed", "secret", name)
	}
	st.LastPoll = now
	if err := s.State.SaveSync(sc.Name, st); err != nil {
		errs = append(errs, err)
	}
	return errors.Join(errs...)
}

func (s *Syncer) destination(sc config.Sync) (Destination, error) {
	var creds *keepiq.Secret
	if sc.CredentialsSecret != "" {
		c, err := s.Keepiq.GetByNameIfNoneMatch(sc.CredentialsSecret, sc.Folder, "")
		if err != nil {
			return nil, fmt.Errorf("read credentials %s: %w", sc.CredentialsSecret, err)
		}
		s.forget(c.Key)
		s.forget(c.Login)
		creds = c
	}
	return s.Destinations(sc, creds)
}

func (s *Syncer) forget(v string) {
	if s.Redactor != nil {
		s.Redactor.Forget(v)
	}
}
