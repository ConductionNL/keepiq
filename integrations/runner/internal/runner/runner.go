// Package runner ties rotations and syncs to one Keepiq application and runs
// them once or as a daemon.
package runner

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"time"

	keepiq "github.com/ConductionNL/keepiq/sdk/go"

	"github.com/ConductionNL/keepiq/integrations/runner/internal/config"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/rotate"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/state"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/syncer"
)

// Client is the Keepiq surface the runner needs (keepiq.Client has it).
type Client interface {
	rotate.Keepiq
	syncer.Keepiq
}

// Runner is one configured runner.
type Runner struct {
	Config  *config.Config
	Keepiq  Client
	State   *state.Dir
	Rotator *rotate.Rotator
	Syncer  *syncer.Syncer
	Log     *slog.Logger
	Now     func() time.Time

	started  time.Time
	meta     map[string]meta
	lastSync map[string]time.Time
}

type meta struct{ etag, expiresAt string }

// Start recovers interrupted rotations from the journal. Call it once before
// RunOnce or Daemon.
func (r *Runner) Start(ctx context.Context) error {
	r.started = r.Now()
	r.meta = map[string]meta{}
	r.lastSync = map[string]time.Time{}
	return r.Rotator.Recover(ctx, r.Config.Rotations)
}

// Tick runs every rotation that is due and every sync whose interval passed.
// once makes a rotation that never ran count as due (for cron or CronJob use).
func (r *Runner) Tick(ctx context.Context, once bool) error {
	now := r.Now()
	states, err := r.State.LoadRotations()
	if err != nil {
		return err
	}
	var errs []error
	for _, rot := range r.Config.Rotations {
		key := rot.Folder + "/" + rot.Secret
		st := states[key]
		since := r.started
		if once && st.LastRun.IsZero() {
			since = time.Time{}
		}
		expiresAt := ""
		if rot.FollowExpiry {
			if expiresAt, err = r.expiresAt(rot); err != nil {
				errs = append(errs, err)
				continue
			}
		}
		due, why := rotate.Due(rot, st, now, since, expiresAt)
		if once && st.LastRun.IsZero() && rot.Schedule != "" {
			due, why = true, "first run"
		}
		if !due {
			continue
		}
		r.Log.Info("rotation due", "secret", rot.Secret, "reason", why)
		st.LastRun = now
		if err := r.Rotator.Rotate(ctx, rot); err != nil {
			errs = append(errs, err)
		} else {
			st.LastRotated = now
			delete(r.meta, key)
		}
		states[key] = st
		if err := r.State.SaveRotations(states); err != nil {
			errs = append(errs, err)
		}
	}
	for _, sc := range r.Config.Syncs {
		if last, ok := r.lastSync[sc.Name]; ok && !once && now.Sub(last) < sc.Interval.Duration {
			continue
		}
		r.lastSync[sc.Name] = now
		if err := r.Syncer.RunOnce(ctx, sc); err != nil {
			errs = append(errs, err)
		}
	}
	return errors.Join(errs...)
}

// expiresAt reads a rotated secret's expiry date, reusing the last read on 304.
func (r *Runner) expiresAt(rot config.Rotation) (string, error) {
	key := rot.Folder + "/" + rot.Secret
	m := r.meta[key]
	s, err := r.Keepiq.GetByNameIfNoneMatch(rot.Secret, rot.Folder, m.etag)
	if errors.Is(err, keepiq.ErrNotModified) {
		return m.expiresAt, nil
	}
	if err != nil {
		return "", fmt.Errorf("read %s: %w", rot.Secret, err)
	}
	r.meta[key] = meta{etag: s.ETag, expiresAt: s.ExpiresAt}
	return s.ExpiresAt, nil
}

// Daemon ticks until ctx ends. Errors are logged, never fatal.
func (r *Runner) Daemon(ctx context.Context) error {
	t := time.NewTicker(r.Config.Tick.Duration)
	defer t.Stop()
	for {
		if err := r.Tick(ctx, false); err != nil {
			r.Log.Error("tick finished with errors", "error", err.Error())
		}
		select {
		case <-ctx.Done():
			return nil
		case <-t.C:
		}
	}
}
