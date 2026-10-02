// Command keepiq-runner rotates credentials at their targets and pushes
// secrets to cloud secret stores, as one Keepiq application, decrypting only
// in its own process.
//
//	keepiq-runner run [--once] [--config runner.yaml]
//	keepiq-runner check [--config runner.yaml]
//	keepiq-runner version
package main

import (
	"context"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"syscall"
	"time"

	keepiq "github.com/ConductionNL/keepiq/sdk/go"

	"github.com/ConductionNL/keepiq/integrations/runner/internal/config"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/connectors"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/destinations"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/logx"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/rotate"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/runner"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/state"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/syncer"
)

var version = "dev"

func main() {
	if len(os.Args) < 2 {
		usage()
		os.Exit(2)
	}
	switch os.Args[1] {
	case "version", "--version":
		fmt.Println("keepiq-runner", version)
	case "check", "run":
		fs := flag.NewFlagSet(os.Args[1], flag.ExitOnError)
		path := fs.String("config", "runner.yaml", "path to runner.yaml")
		once := fs.Bool("once", false, "run what is due once and exit (for cron and CronJobs)")
		_ = fs.Parse(os.Args[2:])
		cfg, err := config.Load(*path)
		if err != nil {
			fmt.Fprintln(os.Stderr, err)
			os.Exit(1)
		}
		if os.Args[1] == "check" {
			fmt.Printf("%s: %d rotation(s), %d sync set(s)\n", *path, len(cfg.Rotations), len(cfg.Syncs))
			return
		}
		os.Exit(run(cfg, *once))
	default:
		usage()
		os.Exit(2)
	}
}

func usage() {
	fmt.Fprintln(os.Stderr, "usage: keepiq-runner run [--once] [--config runner.yaml] | check [--config runner.yaml] | version")
}

func run(cfg *config.Config, once bool) int {
	red := logx.NewRedactor()
	log := logx.New(os.Stderr, red, slog.LevelInfo).With("app", cfg.Keepiq.ApplicationID)
	kc, err := client(cfg)
	if err != nil {
		log.Error("cannot start", "error", err.Error())
		return 1
	}
	dir, err := state.Open(cfg.StateDir)
	if err != nil {
		log.Error("cannot open the state directory", "error", err.Error())
		return 1
	}
	r := &runner.Runner{
		Config: cfg, Keepiq: kc, State: dir, Log: log, Now: time.Now,
		Rotator: &rotate.Rotator{Keepiq: kc, State: dir, Connectors: connectors.New, Log: log, Redactor: red},
		Syncer:  &syncer.Syncer{Keepiq: kc, State: dir, Destinations: destinations.New, Log: log, Redactor: red, Now: time.Now},
	}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	if err := r.Start(ctx); err != nil {
		log.Error("journal recovery finished with errors", "error", err.Error())
	}
	if once {
		if err := r.Tick(ctx, true); err != nil {
			log.Error("run finished with errors", "error", err.Error())
			return 1
		}
		return 0
	}
	log.Info("keepiq-runner started", "version", version, "rotations", len(cfg.Rotations), "syncs", len(cfg.Syncs))
	_ = r.Daemon(ctx)
	return 0
}

func client(cfg *config.Config) (*keepiq.Client, error) {
	key, err := os.ReadFile(cfg.Keepiq.PrivateKeyFile)
	if err != nil {
		return nil, err
	}
	var opts []keepiq.Option
	if cfg.Keepiq.CertificateFile != "" {
		cert, err := os.ReadFile(cfg.Keepiq.CertificateFile)
		if err != nil {
			return nil, err
		}
		opts = append(opts, keepiq.WithCertificate(string(cert)))
	}
	return keepiq.New(cfg.Keepiq.URL, cfg.Keepiq.ApplicationID, string(key), opts...)
}
