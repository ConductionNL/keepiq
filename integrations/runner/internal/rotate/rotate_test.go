package rotate

import (
	"bytes"
	"context"
	"errors"
	"log/slog"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
	"time"

	keepiq "github.com/ConductionNL/keepiq/sdk/go"
	kcrypto "github.com/ConductionNL/keepiq/sdk/go/crypto"
	"github.com/ConductionNL/keepiq/sdk/go/keepiqtest"

	"github.com/ConductionNL/keepiq/integrations/runner/internal/config"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/logx"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/state"
)

// fileTarget is a fake rotation target whose password lives in a file, so a
// second process (after a crash) sees what the first one set.
type fileTarget struct {
	path       string
	refuseNew  bool   // Login refuses any value but the original
	original   string
	onSet      func() // runs inside Set, after the change
	setCalls   int
	adminSeen  Credential
}

func (f *fileTarget) Set(_ context.Context, admin Credential, _ string, current, next string) error {
	f.setCalls++
	f.adminSeen = admin
	have, _ := os.ReadFile(f.path)
	if string(have) != current {
		return errors.New("current password does not match the target")
	}
	if err := os.WriteFile(f.path, []byte(next), 0o600); err != nil {
		return err
	}
	if f.onSet != nil {
		f.onSet()
	}
	return nil
}

func (f *fileTarget) Login(_ context.Context, _ string, password string) error {
	have, _ := os.ReadFile(f.path)
	if string(have) != password || (f.refuseNew && password != f.original) {
		return errors.New("login refused")
	}
	return nil
}

func (f *fileTarget) value() string {
	b, _ := os.ReadFile(f.path)
	return string(b)
}

const oldPassword = "old-target-password-1"

type env struct {
	stub   *keepiqtest.Stub
	kc     *keepiq.Client
	dir    *state.Dir
	target *fileTarget
	logs   *bytes.Buffer
	rot    *Rotator
	cfg    config.Rotation
}

func setup(t *testing.T, stub *keepiqtest.Stub, stateDir, targetFile string) *env {
	t.Helper()
	if stub == nil {
		var err error
		if stub, err = keepiqtest.Start("ops-runner"); err != nil {
			t.Fatal(err)
		}
		t.Cleanup(stub.Close)
		if _, err := stub.Add("sec-pg", "pg-app-password", "", map[string]string{"key": oldPassword, "login": "app"}); err != nil {
			t.Fatal(err)
		}
		if _, err := stub.Add("sec-admin", "pg-admin", "", map[string]string{"key": "admin-password-9", "login": "postgres"}); err != nil {
			t.Fatal(err)
		}
	}
	kc, err := keepiq.New(stub.URL(), "ops-runner", stub.Fixture.PrivateKeyPem)
	if err != nil {
		t.Fatal(err)
	}
	if stateDir == "" {
		stateDir = t.TempDir()
	}
	dir, err := state.Open(stateDir)
	if err != nil {
		t.Fatal(err)
	}
	if targetFile == "" {
		targetFile = filepath.Join(t.TempDir(), "target")
		_ = os.WriteFile(targetFile, []byte(oldPassword), 0o600)
	}
	target := &fileTarget{path: targetFile, original: oldPassword}
	logs := &bytes.Buffer{}
	red := logx.NewRedactor()
	e := &env{stub: stub, kc: kc, dir: dir, target: target, logs: logs,
		cfg: config.Rotation{Secret: "pg-app-password", Connector: "test", AdminSecret: "pg-admin",
			Generator: config.Generator{Length: 32, Classes: []string{"lower", "upper", "digits", "symbols"}}}}
	e.rot = &Rotator{Keepiq: kc, State: dir, Log: logx.New(logs, red, slog.LevelDebug), Redactor: red,
		Connectors: func(config.Rotation) (Connector, error) { return target, nil }}
	return e
}

func (e *env) stored(t *testing.T) string {
	v, err := e.stub.Plain("sec-pg", "key")
	if err != nil {
		t.Fatal(err)
	}
	return v
}

func (e *env) noLeaks(t *testing.T, values ...string) {
	t.Helper()
	files, _ := filepath.Glob(filepath.Join(e.dir.Path, "*", "*"))
	more, _ := filepath.Glob(filepath.Join(e.dir.Path, "*"))
	for _, v := range append(values, "admin-password-9") {
		if strings.Contains(e.logs.String(), v) {
			t.Fatalf("log carries %q:\n%s", v, e.logs.String())
		}
		if e.stub.BodiesContain(v) {
			t.Fatalf("a request to Keepiq carries %q", v)
		}
		for _, f := range append(files, more...) {
			if b, err := os.ReadFile(f); err == nil && strings.Contains(string(b), v) {
				t.Fatalf("state file %s carries %q", f, v)
			}
		}
	}
}

func TestRotationProvesThenRecords(t *testing.T) {
	e := setup(t, nil, "", "")
	if err := e.rot.Rotate(context.Background(), e.cfg); err != nil {
		t.Fatal(err)
	}
	next := e.target.value()
	if next == oldPassword || len(next) != 32 {
		t.Fatalf("target value %q", next)
	}
	if got := e.stored(t); got != next {
		t.Fatalf("Keepiq holds %q, target %q", got, next)
	}
	if e.target.Login(context.Background(), "app", oldPassword) == nil {
		t.Fatal("the old password still logs in")
	}
	if e.target.adminSeen.User != "postgres" || e.target.adminSeen.Password != "admin-password-9" {
		t.Fatalf("admin credential %+v", e.target.adminSeen)
	}
	if j, _ := e.dir.Journal(); len(j) != 0 {
		t.Fatalf("journal left: %+v", j)
	}
	e.noLeaks(t, next, oldPassword)
}

func TestFailedProofKeepsTheOldCredential(t *testing.T) {
	e := setup(t, nil, "", "")
	e.target.refuseNew = true
	etag := e.stub.ETagOf("sec-pg")
	err := e.rot.Rotate(context.Background(), e.cfg)
	if !errors.Is(err, ErrProofFailed) {
		t.Fatalf("want ErrProofFailed, got %v", err)
	}
	if e.target.value() != oldPassword {
		t.Fatalf("target not set back: %q", e.target.value())
	}
	if e.stub.PutCount != 0 || e.stub.ETagOf("sec-pg") != etag || e.stored(t) != oldPassword {
		t.Fatal("Keepiq changed after a failed proof")
	}
	if j, _ := e.dir.Journal(); len(j) != 0 {
		t.Fatal("journal left after a set-back")
	}
}

func TestConcurrentChangeIsNeverOverwritten(t *testing.T) {
	e := setup(t, nil, "", "")
	e.target.onSet = func() {
		e.target.onSet = nil // only during the rotation's own change
		if err := e.stub.SetValue("sec-pg", "key", "changed-by-a-human"); err != nil {
			t.Fatal(err)
		}
	}
	err := e.rot.Rotate(context.Background(), e.cfg)
	if !errors.Is(err, ErrConflict) {
		t.Fatalf("want ErrConflict, got %v", err)
	}
	if e.target.value() != oldPassword {
		t.Fatalf("target not restored: %q", e.target.value())
	}
	if e.stored(t) != "changed-by-a-human" || e.stub.PutCount != 0 {
		t.Fatal("the runner overwrote the concurrent change")
	}
	if !strings.Contains(e.logs.String(), "conflict") || !strings.Contains(e.logs.String(), "pg-app-password") {
		t.Fatalf("no conflict logged for the secret: %s", e.logs.String())
	}
}

// TestCrashAfterTargetChangeIsCompletedOnNextStart runs the rotation in a
// child process that exits right after the target accepted the new value,
// then starts again here and checks the journal completes the write-back.
func TestCrashAfterTargetChangeIsCompletedOnNextStart(t *testing.T) {
	if os.Getenv("KEEPIQ_RUNNER_CRASH_CHILD") == "1" {
		return
	}
	e := setup(t, nil, "", "")
	cmd := exec.Command(os.Args[0], "-test.run", "^TestCrashChild$")
	cmd.Env = append(os.Environ(), "KEEPIQ_RUNNER_CRASH_CHILD=1", "KEEPIQ_STUB_URL="+e.stub.URL(),
		"KEEPIQ_STATE_DIR="+e.dir.Path, "KEEPIQ_TARGET_FILE="+e.target.path)
	out, err := cmd.CombinedOutput()
	var exit *exec.ExitError
	if !errors.As(err, &exit) || exit.ExitCode() != 3 {
		t.Fatalf("child did not crash as planned: %v\n%s", err, out)
	}
	next := e.target.value()
	if next == oldPassword {
		t.Fatal("the child never changed the target")
	}
	if e.stored(t) != oldPassword {
		t.Fatal("the child wrote back before crashing")
	}
	j, _ := e.dir.Journal()
	if len(j) != 1 || j[0].Stage != "set" {
		t.Fatalf("journal after the crash: %+v", j)
	}
	e.noLeaks(t, next)

	// Next start.
	if err := e.rot.Recover(context.Background(), []config.Rotation{e.cfg}); err != nil {
		t.Fatal(err)
	}
	if e.stored(t) != next {
		t.Fatalf("recovery did not write back: Keepiq %q, target %q", e.stored(t), next)
	}
	if j, _ := e.dir.Journal(); len(j) != 0 {
		t.Fatal("journal entry not removed after recovery")
	}
}

// TestCrashChild is the child half of the crash test.
func TestCrashChild(t *testing.T) {
	if os.Getenv("KEEPIQ_RUNNER_CRASH_CHILD") != "1" {
		t.Skip("child process of TestCrashAfterTargetChangeIsCompletedOnNextStart")
	}
	f, err := keepiqtest.LoadFixture()
	if err != nil {
		t.Fatal(err)
	}
	kc, err := keepiq.New(os.Getenv("KEEPIQ_STUB_URL"), "ops-runner", f.PrivateKeyPem)
	if err != nil {
		t.Fatal(err)
	}
	dir, _ := state.Open(os.Getenv("KEEPIQ_STATE_DIR"))
	target := &fileTarget{path: os.Getenv("KEEPIQ_TARGET_FILE"), original: oldPassword}
	r := &Rotator{Keepiq: kc, State: dir, Log: slog.New(slog.NewTextHandler(&bytes.Buffer{}, nil)),
		Connectors:     func(config.Rotation) (Connector, error) { return target, nil },
		AfterTargetSet: func() { os.Exit(3) }}
	_ = r.Rotate(context.Background(), config.Rotation{Secret: "pg-app-password", Connector: "test", AdminSecret: "pg-admin",
		Generator: config.Generator{Length: 24, Classes: []string{"lower", "digits"}}})
	t.Fatal("the child should have exited inside the rotation")
}

// A journal entry whose new value never reached the target is dropped, and
// Keepiq keeps the old value.
func TestRecoveryDropsAnEntryThatNeverReachedTheTarget(t *testing.T) {
	e := setup(t, nil, "", "")
	e.target.onSet = func() { panic("no set expected") }
	ct, _ := keepiqEncrypt(e.kc, "never-set-value")
	old, _ := keepiqEncrypt(e.kc, oldPassword)
	_ = e.dir.PutJournal(state.JournalEntry{SecretID: "sec-pg", Name: "pg-app-password", ETag: e.stub.ETagOf("sec-pg"), User: "app",
		NewValue: ct, OldValue: old, Stage: "pending", CreatedAt: time.Now()})
	if err := e.rot.Recover(context.Background(), []config.Rotation{e.cfg}); err != nil {
		t.Fatal(err)
	}
	if e.stored(t) != oldPassword || e.stub.PutCount != 0 {
		t.Fatal("recovery wrote a value the target never took")
	}
	if j, _ := e.dir.Journal(); len(j) != 0 {
		t.Fatal("entry not dropped")
	}
}

func TestGenerate(t *testing.T) {
	seen := map[string]bool{}
	for i := 0; i < 50; i++ {
		v, err := Generate(config.Generator{Length: 20, Classes: []string{"lower", "upper", "digits", "symbols"}})
		if err != nil {
			t.Fatal(err)
		}
		if len(v) != 20 || seen[v] {
			t.Fatalf("value %q", v)
		}
		seen[v] = true
		if !strings.ContainsAny(v, lower) || !strings.ContainsAny(v, upper) || !strings.ContainsAny(v, digits) || !strings.ContainsAny(v, symbols) {
			t.Fatalf("%q misses a class", v)
		}
		if strings.ContainsAny(v, "'\"\\ `$") {
			t.Fatalf("%q holds a character that needs escaping", v)
		}
	}
}

func TestDueBySchedule(t *testing.T) {
	rot := config.Rotation{Schedule: "0 3 * * 0"} // Sundays 03:00
	start := time.Date(2026, 10, 1, 12, 0, 0, 0, time.UTC) // a Thursday
	if due, _ := Due(rot, stateAt(time.Time{}), start.Add(time.Hour), start, ""); due {
		t.Fatal("due before the first Sunday")
	}
	sunday := time.Date(2026, 10, 4, 3, 0, 30, 0, time.UTC)
	if due, why := Due(rot, stateAt(time.Time{}), sunday, start, ""); !due || why != "schedule" {
		t.Fatal("not due on Sunday 03:00")
	}
	if due, _ := Due(rot, stateAt(sunday), sunday.Add(time.Hour), start, ""); due {
		t.Fatal("due twice in one week")
	}
}

func TestDueByExpiryLeadTime(t *testing.T) {
	rot := config.Rotation{FollowExpiry: true, LeadTime: config.Duration{Duration: 7 * 24 * time.Hour}}
	now := time.Date(2026, 11, 26, 0, 0, 0, 0, time.UTC)
	if due, why := Due(rot, stateAt(time.Time{}), now, now, "2026-12-01T00:00:00+00:00"); !due || why != "expiry" {
		t.Fatal("not due 5 days before expiry with a 7-day lead")
	}
	if due, _ := Due(rot, stateAt(time.Time{}), now, now, "2026-12-10T00:00:00+00:00"); due {
		t.Fatal("due 14 days before expiry")
	}
	st := state.RotationState{LastRotated: now}
	if due, _ := Due(rot, st, now.Add(time.Hour), now, "2026-12-01T00:00:00+00:00"); due {
		t.Fatal("due again in the same expiry window")
	}
}

func stateAt(lastRun time.Time) state.RotationState { return state.RotationState{LastRun: lastRun} }

func keepiqEncrypt(kc *keepiq.Client, v string) (string, error) {
	return kcrypto.EncryptField(v, kc.PublicKey())
}
