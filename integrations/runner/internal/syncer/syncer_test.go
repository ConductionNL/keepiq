package syncer

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
	"github.com/ConductionNL/keepiq/integrations/runner/internal/state"
)

type recorder struct {
	pushes []string
	values map[string]string
	fail   map[string]bool
}

func (r *recorder) Push(_ context.Context, name, value string) error {
	if r.fail[name] {
		return errors.New("destination down")
	}
	r.pushes = append(r.pushes, name)
	r.values[name] = value
	return nil
}

func setup(t *testing.T) (*keepiqtest.Stub, *Syncer, *recorder, *bytes.Buffer, *time.Time) {
	stub, err := keepiqtest.Start("ops-runner")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(stub.Close)
	_, _ = stub.Add("sec-stripe", "stripe-key", "", map[string]string{"key": "sk_live_one"})
	_, _ = stub.Add("sec-deploy", "deploy-token", "", map[string]string{"key": "dt_one"})
	_, _ = stub.Add("sec-other", "not-synced", "", map[string]string{"key": "other_one"})
	kc, err := keepiq.New(stub.URL(), "ops-runner", stub.Fixture.PrivateKeyPem)
	if err != nil {
		t.Fatal(err)
	}
	dir, _ := state.Open(t.TempDir())
	rec := &recorder{values: map[string]string{}, fail: map[string]bool{}}
	logs := &bytes.Buffer{}
	red := logx.NewRedactor()
	now := time.Date(2026, 10, 2, 12, 0, 0, 0, time.UTC)
	s := &Syncer{Keepiq: kc, State: dir, Log: logx.New(logs, red, slog.LevelDebug), Redactor: red,
		Now:          func() time.Time { return now },
		Destinations: func(config.Sync, *keepiq.Secret) (Destination, error) { return rec, nil }}
	return stub, s, rec, logs, &now
}

var set = config.Sync{Name: "aws-prod", Secrets: []string{"stripe-key", "deploy-token"}, Destination: "exec"}

func TestChangedSecretIsPushedOnceAndUnchangedNever(t *testing.T) {
	stub, s, rec, logs, now := setup(t)
	ctx := context.Background()
	if err := s.RunOnce(ctx, set); err != nil {
		t.Fatal(err)
	}
	if strings.Join(rec.pushes, ",") != "stripe-key,deploy-token" || rec.values["stripe-key"] != "sk_live_one" {
		t.Fatalf("first run pushed %v %v", rec.pushes, rec.values)
	}
	*now = now.Add(time.Minute)
	if err := s.RunOnce(ctx, set); err != nil {
		t.Fatal(err)
	}
	if len(rec.pushes) != 2 {
		t.Fatalf("an unchanged secret was pushed again: %v", rec.pushes)
	}
	_ = stub.SetValue("sec-stripe", "key", "sk_live_two")
	_ = stub.SetValue("sec-other", "key", "other_two")
	*now = now.Add(time.Minute)
	if err := s.RunOnce(ctx, set); err != nil {
		t.Fatal(err)
	}
	if strings.Join(rec.pushes, ",") != "stripe-key,deploy-token,stripe-key" || rec.values["stripe-key"] != "sk_live_two" {
		t.Fatalf("after a change pushed %v", rec.pushes)
	}
	*now = now.Add(time.Minute)
	_ = s.RunOnce(ctx, set)
	if len(rec.pushes) != 3 {
		t.Fatalf("pushed again without a change: %v", rec.pushes)
	}
	for _, v := range []string{"sk_live_one", "sk_live_two", "dt_one"} {
		if strings.Contains(logs.String(), v) || stub.BodiesContain(v) {
			t.Fatalf("%q leaked into the log or a request", v)
		}
	}
}

func TestFailedPushBacksOffWithoutBlockingOthers(t *testing.T) {
	_, s, rec, _, now := setup(t)
	ctx := context.Background()
	rec.fail["stripe-key"] = true
	if err := s.RunOnce(ctx, set); err == nil {
		t.Fatal("a failed push must be reported")
	}
	if strings.Join(rec.pushes, ",") != "deploy-token" {
		t.Fatalf("the other entry was blocked: %v", rec.pushes)
	}
	st, _ := s.State.LoadSync("aws-prod")
	e := st.Entries["stripe-key"]
	if e.Failures != 1 || !e.Dirty || e.NextAttempt != now.Add(30*time.Second) {
		t.Fatalf("retry state %+v", e)
	}
	rec.fail["stripe-key"] = false
	*now = now.Add(10 * time.Second)
	_ = s.RunOnce(ctx, set)
	if len(rec.pushes) != 1 {
		t.Fatal("retried before the backoff ended")
	}
	*now = now.Add(30 * time.Second)
	if err := s.RunOnce(ctx, set); err != nil {
		t.Fatal(err)
	}
	if strings.Join(rec.pushes, ",") != "deploy-token,stripe-key" {
		t.Fatalf("not retried after the backoff: %v", rec.pushes)
	}
}

func TestBackoffGrowsAndCaps(t *testing.T) {
	if Backoff(1) != 30*time.Second || Backoff(2) != time.Minute || Backoff(20) != 30*time.Minute {
		t.Fatal("backoff steps")
	}
}
