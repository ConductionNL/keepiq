// Package connectors sets and proves passwords at rotation targets:
// PostgreSQL, MySQL, and any system through an exec hook.
package connectors

import (
	"bytes"
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"net"
	"net/url"
	"os/exec"
	"strings"
	"time"

	"github.com/go-sql-driver/mysql"
	"github.com/jackc/pgx/v5"

	"github.com/ConductionNL/keepiq/integrations/runner/internal/config"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/rotate"
)

// New builds the connector a rotation names.
func New(r config.Rotation) (rotate.Connector, error) {
	switch r.Connector {
	case "postgres":
		return &Postgres{Host: r.Target["host"], Database: r.Target["database"], SSLMode: r.Target["sslmode"]}, nil
	case "mysql":
		return &MySQL{Host: r.Target["host"], Database: r.Target["database"], UserHost: r.Target["userHost"], TLS: r.Target["tls"]}, nil
	case "exec":
		return &Exec{Command: r.Command}, nil
	}
	return nil, fmt.Errorf("unknown connector %q", r.Connector)
}

// sqlString quotes a string literal for SQL. Generated values contain no
// quote or backslash; this is the guard for anything else.
func sqlString(v string) string {
	return "'" + strings.ReplaceAll(strings.ReplaceAll(v, `\`, `\\`), "'", "''") + "'"
}

// Postgres rotates a role's password with ALTER ROLE.
type Postgres struct {
	Host     string // host:port
	Database string
	SSLMode  string // default prefer
}

func (p *Postgres) dsn(user, password string) string {
	host, port, err := net.SplitHostPort(p.Host)
	if err != nil {
		host, port = p.Host, "5432"
	}
	db := p.Database
	if db == "" {
		db = "postgres"
	}
	mode := p.SSLMode
	if mode == "" {
		mode = "prefer"
	}
	u := url.URL{Scheme: "postgres", User: url.UserPassword(user, password), Host: net.JoinHostPort(host, port), Path: "/" + db,
		RawQuery: url.Values{"sslmode": {mode}, "connect_timeout": {"10"}}.Encode()}
	return u.String()
}

// Set runs ALTER ROLE as the admin.
func (p *Postgres) Set(ctx context.Context, admin rotate.Credential, user, _, next string) error {
	conn, err := pgx.Connect(ctx, p.dsn(admin.User, admin.Password))
	if err != nil {
		return fmt.Errorf("postgres: admin login: %w", scrub(err))
	}
	defer conn.Close(ctx)
	// ALTER ROLE takes no bind parameters; the identifier and the literal are quoted.
	_, err = conn.Exec(ctx, "ALTER ROLE "+pgx.Identifier{user}.Sanitize()+" WITH PASSWORD "+sqlString(next))
	if err != nil {
		return fmt.Errorf("postgres: ALTER ROLE %s failed: %w", user, scrub(err))
	}
	return nil
}

// Login connects as the user.
func (p *Postgres) Login(ctx context.Context, user, password string) error {
	conn, err := pgx.Connect(ctx, p.dsn(user, password))
	if err != nil {
		return fmt.Errorf("postgres: login as %s failed: %w", user, scrub(err))
	}
	defer conn.Close(ctx)
	return conn.Ping(ctx)
}

// MySQL rotates an account's password with ALTER USER.
type MySQL struct {
	Host     string // host:port
	Database string
	UserHost string // the account's host part, default %
	TLS      string // go-sql-driver tls value, default preferred
}

func (m *MySQL) open(user, password string) (*sql.DB, error) {
	cfg := mysql.NewConfig()
	cfg.User, cfg.Passwd, cfg.Net, cfg.Addr, cfg.DBName = user, password, "tcp", m.Host, m.Database
	cfg.Timeout = 10 * time.Second
	cfg.TLSConfig = m.TLS
	if cfg.TLSConfig == "" {
		cfg.TLSConfig = "preferred"
	}
	cfg.AllowNativePasswords = true
	return sql.Open("mysql", cfg.FormatDSN())
}

// Set runs ALTER USER as the admin.
func (m *MySQL) Set(ctx context.Context, admin rotate.Credential, user, _, next string) error {
	db, err := m.open(admin.User, admin.Password)
	if err != nil {
		return err
	}
	defer db.Close()
	host := m.UserHost
	if host == "" {
		host = "%"
	}
	if _, err := db.ExecContext(ctx, "ALTER USER "+sqlString(user)+"@"+sqlString(host)+" IDENTIFIED BY "+sqlString(next)); err != nil {
		return fmt.Errorf("mysql: ALTER USER %s failed: %w", user, scrub(err))
	}
	return nil
}

// Login connects as the user.
func (m *MySQL) Login(ctx context.Context, user, password string) error {
	db, err := m.open(user, password)
	if err != nil {
		return err
	}
	defer db.Close()
	if err := db.PingContext(ctx); err != nil {
		return fmt.Errorf("mysql: login as %s failed: %w", user, scrub(err))
	}
	return nil
}

// Exec rotates through a command. It gets one JSON object on stdin:
//
//	{"action":"set","user":"app","current":"…","new":"…"}
//	{"action":"login","user":"app","password":"…"}
//
// and answers with its exit code (0 is success). Its output is not read or
// logged, so a hook cannot leak a value into the runner's log.
type Exec struct{ Command []string }

func (e *Exec) run(ctx context.Context, payload map[string]string) error {
	body, _ := json.Marshal(payload)
	cmd := exec.CommandContext(ctx, e.Command[0], e.Command[1:]...)
	cmd.Stdin = bytes.NewReader(body)
	if err := cmd.Run(); err != nil {
		if ee, ok := err.(*exec.ExitError); ok {
			return fmt.Errorf("exec %s %s: exit %d", e.Command[0], payload["action"], ee.ExitCode())
		}
		return fmt.Errorf("exec %s: %w", e.Command[0], err)
	}
	return nil
}

// Set calls the hook with action set.
func (e *Exec) Set(ctx context.Context, _ rotate.Credential, user, current, next string) error {
	return e.run(ctx, map[string]string{"action": "set", "user": user, "current": current, "new": next})
}

// Login calls the hook with action login.
func (e *Exec) Login(ctx context.Context, user, password string) error {
	return e.run(ctx, map[string]string{"action": "login", "user": user, "password": password})
}

// scrub keeps a driver error but never its connection string.
func scrub(err error) error {
	if err == nil {
		return nil
	}
	msg := err.Error()
	if i := strings.Index(msg, "postgres://"); i >= 0 {
		msg = msg[:i] + "(connection string removed)"
	}
	return fmt.Errorf("%s", msg)
}
