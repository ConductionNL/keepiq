package provider

import (
	"errors"
	"fmt"
	"os"
	"testing"
	"time"
)

// TestAdminAPILive runs the admin calls behind keepiq_application and
// keepiq_application_lease_policy against a real Keepiq when KEEPIQ_LIVE_URL
// (with /index.php when needed), KEEPIQ_ADMIN_USER and KEEPIQ_ADMIN_PASSWORD
// are set: register, read, set and clear the lease override, delete.
func TestAdminAPILive(t *testing.T) {
	base, user, pass := os.Getenv("KEEPIQ_LIVE_URL"), os.Getenv("KEEPIQ_ADMIN_USER"), os.Getenv("KEEPIQ_ADMIN_PASSWORD")
	if base == "" || user == "" || pass == "" {
		t.Skip("set KEEPIQ_LIVE_URL, KEEPIQ_ADMIN_USER and KEEPIQ_ADMIN_PASSWORD to run against a live Keepiq")
	}
	c := NewAdminClient(base, user, pass)
	a, err := c.CreateApplication(fmt.Sprintf("tf-live-%d", time.Now().Unix()), "terraform provider live test", "external", "")
	if err != nil {
		t.Fatal(err)
	}
	defer func() {
		if err := c.DeleteApplication(a.ID); err != nil {
			t.Errorf("delete: %v", err)
		}
		if _, err := c.GetApplication(a.ID); !errors.Is(err, ErrNotFound) {
			t.Errorf("after delete: %v", err)
		}
	}()
	if a.Status == "pending" {
		if a, err = c.ApproveApplication(a.ID); err != nil {
			t.Fatal(err)
		}
	}
	if a.Status != "active" {
		t.Fatalf("status %q", a.Status)
	}
	ttl := int64(600)
	p, err := c.SetLeasePolicy(a.ID, LeaseValues{DefaultTTL: &ttl})
	if err != nil {
		t.Fatal(err)
	}
	if p.Effective.DefaultTTL == nil || *p.Effective.DefaultTTL != 600 {
		t.Fatalf("effective %+v", p.Effective)
	}
	if p, err = c.SetLeasePolicy(a.ID, LeaseValues{}); err != nil || (p.Override != nil && p.Override.DefaultTTL != nil) {
		t.Fatalf("clear: %v %+v", err, p.Override)
	}
}
