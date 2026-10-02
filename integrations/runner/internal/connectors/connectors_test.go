package connectors

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/ConductionNL/keepiq/integrations/runner/internal/config"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/rotate"
)

// The database tests need a server. Each gets an admin account and creates a
// throwaway login to rotate:
//
//	KEEPIQ_TEST_POSTGRES="host:5432 adminUser adminPassword"
//	KEEPIQ_TEST_MYSQL="host:3306 adminUser adminPassword"
func dbEnv(t *testing.T, name string) (host string, admin rotate.Credential) {
	t.Helper()
	parts := strings.Fields(os.Getenv(name))
	if len(parts) != 3 {
		t.Skipf("set %s=\"host:port adminUser adminPassword\" to run against a real server", name)
	}
	return parts[0], rotate.Credential{User: parts[1], Password: parts[2]}
}

// rotateAgainst sets a new password as the admin and proves the new one logs
// in and the old one no longer does.
func rotateAgainst(t *testing.T, c rotate.Connector, admin rotate.Credential, user, old string) {
	t.Helper()
	ctx := context.Background()
	if err := c.Login(ctx, user, old); err != nil {
		t.Fatalf("old password does not log in before the rotation: %v", err)
	}
	next, _ := rotate.Generate(config.Generator{Length: 32, Classes: []string{"lower", "upper", "digits", "symbols"}})
	if err := c.Set(ctx, admin, user, old, next); err != nil {
		t.Fatal(err)
	}
	if err := c.Login(ctx, user, next); err != nil {
		t.Fatalf("new password refused: %v", err)
	}
	if err := c.Login(ctx, user, old); err == nil {
		t.Fatal("old password still logs in")
	}
	err := c.Login(ctx, user, "wrong-"+next)
	if err == nil || strings.Contains(err.Error(), next) {
		t.Fatalf("a refused login must fail without echoing a value: %v", err)
	}
}

func TestPostgres(t *testing.T) {
	host, admin := dbEnv(t, "KEEPIQ_TEST_POSTGRES")
	ctx := context.Background()
	p := &Postgres{Host: host, SSLMode: "disable"}
	if err := p.Set(ctx, admin, admin.User, admin.Password, admin.Password); err != nil {
		t.Fatalf("admin cannot log in: %v", err)
	}
	pg, err := pgxConnect(ctx, p, admin)
	if err != nil {
		t.Fatal(err)
	}
	_, _ = pg.Exec(ctx, "DROP ROLE IF EXISTS keepiq_rotate_test")
	if _, err := pg.Exec(ctx, "CREATE ROLE keepiq_rotate_test LOGIN PASSWORD 'initial-pass-1'"); err != nil {
		t.Fatal(err)
	}
	pg.Close(ctx)
	rotateAgainst(t, p, admin, "keepiq_rotate_test", "initial-pass-1")
}

func TestMySQL(t *testing.T) {
	host, admin := dbEnv(t, "KEEPIQ_TEST_MYSQL")
	ctx := context.Background()
	m := &MySQL{Host: host, TLS: "false"}
	db, err := m.open(admin.User, admin.Password)
	if err != nil {
		t.Fatal(err)
	}
	_, _ = db.ExecContext(ctx, "DROP USER IF EXISTS 'keepiq_rotate_test'@'%'")
	if _, err := db.ExecContext(ctx, "CREATE USER 'keepiq_rotate_test'@'%' IDENTIFIED BY 'initial-pass-1'"); err != nil {
		t.Fatal(err)
	}
	db.Close()
	rotateAgainst(t, m, admin, "keepiq_rotate_test", "initial-pass-1")
}

// The exec connector passes JSON on stdin and reads only the exit code.
func TestExecConnector(t *testing.T) {
	dir := t.TempDir()
	store := filepath.Join(dir, "password")
	_ = os.WriteFile(store, []byte("old-value-1"), 0o600)
	script := filepath.Join(dir, "hook.sh")
	// A tiny target: "set" writes the new value when current matches;
	// "login" succeeds when the password matches.
	_ = os.WriteFile(script, []byte(`#!/bin/sh
in=$(cat)
field() { printf '%s' "$in" | sed -n "s/.*\"$1\":\"\([^\"]*\)\".*/\1/p"; }
case "$(field action)" in
  set) [ "$(cat `+store+`)" = "$(field current)" ] || exit 2; printf '%s' "$(field new)" > `+store+` ;;
  login) [ "$(cat `+store+`)" = "$(field password)" ] || exit 1 ;;
  *) exit 9 ;;
esac
`), 0o755)
	c, err := New(config.Rotation{Connector: "exec", Command: []string{script}})
	if err != nil {
		t.Fatal(err)
	}
	rotateAgainst(t, c, rotate.Credential{}, "app", "old-value-1")
}
