package runner

import (
	"bytes"
	"context"
	"errors"
	"log/slog"
	"strings"
	"testing"
	"time"

	keepiq "github.com/ConductionNL/keepiq/sdk/go"
	"github.com/ConductionNL/keepiq/sdk/go/keepiqtest"

	"github.com/ConductionNL/keepiq/integrations/runner/internal/config"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/logx"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/rotate"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/state"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/syncer"
)

type memTarget struct{ pw string }

func (m *memTarget) Set(_ context.Context, _ rotate.Credential, _, current, next string) error {
	if current != m.pw {
		return errors.New("mismatch")
	}
	m.pw = next
	return nil
}
func (m *memTarget) Login(_ context.Context, _, pw string) error {
	if pw != m.pw {
		return errors.New("refused")
	}
	return nil
}

type memDest struct{ got map[string]string }

func (d *memDest) Push(_ context.Context, n, v string) error { d.got[n] = v; return nil }

func build(t *testing.T, now *time.Time) (*Runner, *keepiqtest.Stub, *memTarget, *memDest, *bytes.Buffer) {
	stub, err := keepiqtest.Start("ops-runner")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(stub.Close)
	_, _ = stub.Add("sec-pg", "pg-app-password", "", map[string]string{"key": "initial-pg-1", "login": "app"})
	_, _ = stub.Add("sec-stripe", "stripe-key", "", map[string]string{"key": "sk_live_one"})
	kc, _ := keepiq.New(stub.URL(), "ops-runner", stub.Fixture.PrivateKeyPem)
	cfg, err := config.Parse([]byte(`
keepiq: {url: x://y, applicationId: ops-runner, privateKeyFile: /k}
stateDir: /s
rotations:
  - {secret: pg-app-password, connector: exec, command: [x], schedule: "0 3 * * 0", followExpiry: true}
syncs:
  - {secrets: [stripe-key], destination: exec, command: [x]}
`))
	if err != nil {
		t.Fatal(err)
	}
	dir, _ := state.Open(t.TempDir())
	target := &memTarget{pw: "initial-pg-1"}
	dest := &memDest{got: map[string]string{}}
	logs := &bytes.Buffer{}
	red := logx.NewRedactor()
	log := logx.New(logs, red, slog.LevelDebug)
	clock := func() time.Time { return *now }
	r := &Runner{Config: cfg, Keepiq: kc, State: dir, Log: log, Now: clock,
		Rotator: &rotate.Rotator{Keepiq: kc, State: dir, Log: log, Redactor: red, Now: clock,
			Connectors: func(config.Rotation) (rotate.Connector, error) { return target, nil }},
		Syncer: &syncer.Syncer{Keepiq: kc, State: dir, Log: log, Redactor: red, Now: clock,
			Destinations: func(config.Sync, *keepiq.Secret) (syncer.Destination, error) { return dest, nil }}}
	if err := r.Start(context.Background()); err != nil {
		t.Fatal(err)
	}
	return r, stub, target, dest, logs
}

// run --once rotates a scheduled rotation that never ran and runs every sync.
func TestOnceRotatesAndSyncs(t *testing.T) {
	now := time.Date(2026, 10, 1, 12, 0, 0, 0, time.UTC)
	r, stub, target, dest, logs := build(t, &now)
	if err := r.Tick(context.Background(), true); err != nil {
		t.Fatal(err)
	}
	stored, _ := stub.Plain("sec-pg", "key")
	if target.pw == "initial-pg-1" || stored != target.pw {
		t.Fatalf("not rotated: target %q keepiq %q", target.pw, stored)
	}
	if dest.got["stripe-key"] != "sk_live_one" {
		t.Fatalf("not synced: %v", dest.got)
	}
	for _, v := range []string{target.pw, "initial-pg-1", "sk_live_one"} {
		if strings.Contains(logs.String(), v) || stub.BodiesContain(v) {
			t.Fatalf("%q leaked", v)
		}
	}
}

// The daemon does not rotate at start; it waits for the schedule, or for the
// expiry lead time.
func TestDaemonWaitsForScheduleOrExpiry(t *testing.T) {
	now := time.Date(2026, 10, 1, 12, 0, 0, 0, time.UTC) // Thursday
	r, stub, target, _, _ := build(t, &now)
	_ = r.Tick(context.Background(), false)
	if target.pw != "initial-pg-1" {
		t.Fatal("rotated at start")
	}
	stub.SetExpiry("sec-pg", "2026-10-05T00:00:00+00:00") // within 7 days
	now = now.Add(time.Minute)
	if err := r.Tick(context.Background(), false); err != nil {
		t.Fatal(err)
	}
	if target.pw == "initial-pg-1" {
		t.Fatal("not rotated ahead of expiry")
	}
	rotated := target.pw
	now = now.Add(time.Minute)
	_ = r.Tick(context.Background(), false)
	if target.pw != rotated {
		t.Fatal("rotated twice in one expiry window")
	}
	now = time.Date(2026, 10, 4, 3, 0, 10, 0, time.UTC) // Sunday 03:00
	_ = r.Tick(context.Background(), false)
	if target.pw == rotated {
		t.Fatal("schedule did not fire")
	}
}
