package client

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

// The ssh_key type id comes from the catalogue, with the app password sent as
// basic auth (cli-ssh-agent).
func TestSSHKeyTypeID(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/apps/keepiq/api/v1/secret-types" {
			http.NotFound(w, r)
			return
		}
		if u, p, ok := r.BasicAuth(); !ok || u != "alice" || p != "app-pw" {
			w.WriteHeader(http.StatusUnauthorized)
			return
		}
		_, _ = w.Write([]byte(`[{"id":"t-login","name":"login"},{"id":"t-ssh","name":"ssh_key","scope":"global"}]`))
	}))
	defer srv.Close()

	c := New(srv.URL)
	c.WithAppPassword("alice", "app-pw")
	id, err := c.SSHKeyTypeID()
	if err != nil || id != "t-ssh" {
		t.Fatalf("SSHKeyTypeID = %q, %v; want t-ssh", id, err)
	}
}

func TestSSHKeyTypeIDMissing(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`[{"id":"t-login","name":"login"}]`))
	}))
	defer srv.Close()

	if _, err := New(srv.URL).SSHKeyTypeID(); err == nil {
		t.Fatal("expected an error when the catalogue has no ssh_key type")
	}
}

func TestFolderIDByName(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`[{"id":"f1","name":"Servers"},{"id":"f2","name":"Deploy"}]`))
	}))
	defer srv.Close()

	id, err := New(srv.URL).FolderIDByName("Deploy")
	if err != nil || id != "f2" {
		t.Fatalf("FolderIDByName = %q, %v; want f2", id, err)
	}
}
