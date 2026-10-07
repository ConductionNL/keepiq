//go:build !linux && !darwin && !windows

package sshagent

import (
	"errors"
	"net"
)

// PeerUID cannot identify the peer here, so every connection is refused.
func PeerUID(net.Conn) (int, error) {
	return -1, errors.New("peer credentials are not supported on this system")
}

// HardenProcess is not available on this system.
func HardenProcess() error {
	return errors.New("cannot disable core dumps on this system")
}
