package main

import (
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
)

// agentFlags are the `keepiq ssh-agent` options (cli-ssh-agent D1).
type agentFlags struct {
	Socket  string
	Confirm bool
	Idle    int // minutes; 0 disables
	Folder  string
	Locked  bool
}

// parseAgentFlags reads the ssh-agent flags. Idle defaults to 60 minutes.
func parseAgentFlags(args []string) (agentFlags, error) {
	f := agentFlags{Idle: 60}
	for i := 0; i < len(args); i++ {
		a := args[i]
		value := func() (string, error) {
			if i+1 >= len(args) {
				return "", fmt.Errorf("%s needs a value", a)
			}
			i++
			return args[i], nil
		}
		var err error
		switch a {
		case "--confirm":
			f.Confirm = true
		case "--locked":
			f.Locked = true
		case "--socket":
			f.Socket, err = value()
		case "--folder":
			f.Folder, err = value()
		case "--idle":
			var v string
			if v, err = value(); err == nil {
				f.Idle, err = strconv.Atoi(v)
				if err != nil || f.Idle < 0 {
					err = fmt.Errorf("--idle takes a number of minutes (0 turns the idle lock off)")
				}
			}
		default:
			return f, fmt.Errorf("unknown ssh-agent option %q", a)
		}
		if err != nil {
			return f, err
		}
	}
	return f, nil
}

// defaultAgentSocket is $XDG_RUNTIME_DIR/keepiq/agent.sock on Linux and
// $TMPDIR/keepiq-<uid>/agent.sock elsewhere (macOS).
func defaultAgentSocket() string {
	if runtime.GOOS == "linux" {
		if dir := os.Getenv("XDG_RUNTIME_DIR"); dir != "" {
			return filepath.Join(dir, "keepiq", "agent.sock")
		}
	}
	return filepath.Join(os.TempDir(), "keepiq-"+strconv.Itoa(os.Getuid()), "agent.sock")
}
