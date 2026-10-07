package client

import (
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"
)

// Keepiq's OCS routes refuse with 428 and an `error` code, because Nextcloud
// turns a 403 there into an HTTP 200 OCS envelope (keepiq#673, measured live
// 4 Oct 2026). The client names the refusal and its code, and never takes an
// OCS failure envelope for data.

func serve(t *testing.T, status int, body string) *Client {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(status)
		_, _ = w.Write([]byte(body))
	}))
	t.Cleanup(srv.Close)
	c := New(srv.URL)
	c.WithAppPassword("ann", "app-pw")
	return c
}

func TestARefusalCarriesItsCode(t *testing.T) {
	c := serve(t, 428, `{"error":"org_ownership_required","code":"org_ownership_required","message":"Save work logins in a team folder"}`)
	_, err := c.GetSecret("s-1")
	var refusal *RefusalError
	if !errors.As(err, &refusal) {
		t.Fatalf("want a RefusalError, got %T %v", err, err)
	}
	if refusal.Status != 428 || refusal.Code != "org_ownership_required" || refusal.Message != "Save work logins in a team folder" {
		t.Fatalf("unexpected refusal %+v", refusal)
	}
	if !IsRefusal(err) {
		t.Fatal("IsRefusal must be true for a 428")
	}
}

func TestA403IsARefusalToo(t *testing.T) {
	c := serve(t, 403, `{"message":"Not yours"}`)
	_, err := c.GetSecret("s-1")
	if !IsRefusal(err) {
		t.Fatalf("a 403 is a refusal, got %v", err)
	}
}

func TestAnOcsFailureEnvelopeIsNotData(t *testing.T) {
	c := serve(t, 200, `{"ocs":{"meta":{"status":"failure","statuscode":403,"message":"Not allowed"},"data":[]}}`)
	s, err := c.GetSecret("s-1")
	if err == nil {
		t.Fatalf("an OCS failure envelope read as data: %+v", s)
	}
	if !IsRefusal(err) {
		t.Fatalf("the envelope's 403 is a refusal, got %v", err)
	}
}

func TestOtherStatusesAreNotRefusals(t *testing.T) {
	c := serve(t, 404, `{"message":"not found"}`)
	_, err := c.GetSecret("s-1")
	if err == nil || IsRefusal(err) {
		t.Fatalf("a 404 is an error but not a refusal, got %v", err)
	}
}
