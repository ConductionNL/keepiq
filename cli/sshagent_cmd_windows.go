//go:build windows

package main

import "fmt"

func cmdSSHAgent([]string) error {
	return fmt.Errorf("keepiq ssh-agent is not supported on Windows yet; run it in WSL")
}
