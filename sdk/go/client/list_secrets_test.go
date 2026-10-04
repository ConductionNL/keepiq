package client

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strconv"
	"testing"
)

// listServer answers GET /apps/keepiq/api/v1/secrets the way a Nextcloud 35
// instance does: the framework refuses a `limit` above 500 with an empty 400
// (OC\AppFramework\Http\Dispatcher::DEFAULT_MAX), and Keepiq clamps the page
// to SecretService::MAX_LIMIT (100).
func listServer(t *testing.T, total int) *httptest.Server {
	t.Helper()
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/apps/keepiq/api/v1/secrets" {
			http.NotFound(w, r)
			return
		}
		limit, _ := strconv.Atoi(r.URL.Query().Get("limit"))
		if limit == 0 {
			limit = 50
		}
		if limit > 500 {
			w.WriteHeader(http.StatusBadRequest)
			return
		}
		if limit > 100 {
			limit = 100
		}
		page, _ := strconv.Atoi(r.URL.Query().Get("page"))
		if page < 1 {
			page = 1
		}
		items := []Secret{}
		for i := (page - 1) * limit; i < page*limit && i < total; i++ {
			items = append(items, Secret{ID: fmt.Sprintf("s%03d", i), Name: fmt.Sprintf("secret %d", i)})
		}
		_ = json.NewEncoder(w).Encode(map[string]any{"items": items, "total": total, "page": page, "limit": limit})
	}))
}

// ListSecrets returns every secret, also past the server's page size, and
// never asks for a page size Nextcloud refuses (keepiq#786, found running the
// SSH agent against Nextcloud 35).
func TestListSecretsReadsEveryPage(t *testing.T) {
	for _, total := range []int{0, 7, 100, 250} {
		srv := listServer(t, total)
		c := New(srv.URL)
		c.WithAppPassword("alice", "app-password")
		secrets, err := c.ListSecrets()
		srv.Close()
		if err != nil {
			t.Fatalf("total %d: %v", total, err)
		}
		if len(secrets) != total {
			t.Fatalf("total %d: got %d secrets", total, len(secrets))
		}
		seen := map[string]bool{}
		for _, s := range secrets {
			if seen[s.ID] {
				t.Fatalf("total %d: %s returned twice", total, s.ID)
			}
			seen[s.ID] = true
		}
	}
}
