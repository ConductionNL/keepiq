package connectors

import (
	"context"

	"github.com/jackc/pgx/v5"

	"github.com/ConductionNL/keepiq/integrations/runner/internal/rotate"
)

func pgxConnect(ctx context.Context, p *Postgres, admin rotate.Credential) (*pgx.Conn, error) {
	return pgx.Connect(ctx, p.dsn(admin.User, admin.Password))
}
