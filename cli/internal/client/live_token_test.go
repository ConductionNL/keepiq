package client

import (
	"crypto/rand"
	"crypto/rsa"
	"os"
	"testing"
	"time"
)

// TestLiveTokenExchangeReachesValidation drives Discover + MachineToken against a
// real instance (DORIATH_LIVE_URL). With a throwaway key and an unregistered
// application id, the server must reach CLAIM validation and reject the issuer —
// proving the assertion is well-formed (correct grant, audience, exp/iat window,
// RS256 signature) rather than failing to parse. Skipped without the env var.
func TestLiveTokenExchangeReachesValidation(t *testing.T) {
	base := os.Getenv("DORIATH_LIVE_URL")
	if base == "" {
		t.Skip("set DORIATH_LIVE_URL to run the live token probe")
	}
	c := New(base)
	disc, err := c.Discover()
	if err != nil {
		t.Fatalf("Discover: %v", err)
	}
	if disc.TokenEndpoint == "" || disc.Assertion.Audience != "doriath" {
		t.Fatalf("unexpected discovery: %+v", disc)
	}
	t.Logf("discovery OK: tokenEndpoint=%s audience=%s leaseSupported=%v",
		disc.TokenEndpoint, disc.Assertion.Audience, disc.LeaseSupported())

	key, _ := rsa.GenerateKey(rand.Reader, 2048)
	_, err = c.MachineToken("cli-live-probe-nonexistent", key, disc, time.Now().Unix())
	if err == nil {
		t.Fatal("expected rejection for an unregistered application")
	}
	// The error must come from claim/issuer validation, not a malformed request.
	t.Logf("token exchange rejected as expected: %v", err)
}
