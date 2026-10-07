package provider

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/hashicorp/terraform-plugin-framework/provider"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/tfsdk"
	"github.com/hashicorp/terraform-plugin-go/tftypes"
)

// stubAdmin is a Keepiq admin API in memory: one application that starts
// pending, its approval and its lease override. It refuses any call without
// the app password and the OCS-APIRequest header.
type stubAdmin struct {
	t         *testing.T
	calls     []string
	status    string
	deleted   bool
	override  map[string]any
	lastLease map[string]any
}

func (s *stubAdmin) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	user, pass, ok := r.BasicAuth()
	if !ok || user != "svc-apps" || pass != "app-password" || r.Header.Get("OCS-APIRequest") != "true" {
		w.WriteHeader(http.StatusUnauthorized)
		return
	}
	path := strings.TrimPrefix(r.URL.Path, "/index.php/apps/keepiq/api/v1/admin")
	s.calls = append(s.calls, r.Method+" "+path)
	app := map[string]any{"id": "app-1", "name": "ci-runner", "type": "external", "status": s.status, "certificate": "-----BEGIN CERTIFICATE-----"}
	switch {
	case r.Method == http.MethodPost && path == "/applications":
		var body map[string]string
		_ = json.NewDecoder(r.Body).Decode(&body)
		if body["name"] != "ci-runner" || body["csr"] != "CSR" {
			s.t.Errorf("create body %v", body)
		}
		w.WriteHeader(http.StatusCreated)
		_ = json.NewEncoder(w).Encode(app)
	case r.Method == http.MethodPost && path == "/applications/app-1/approve":
		s.status = "active"
		app["status"] = "active"
		_ = json.NewEncoder(w).Encode(app)
	case r.Method == http.MethodGet && path == "/applications/app-1":
		if s.deleted {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		_ = json.NewEncoder(w).Encode(app)
	case r.Method == http.MethodDelete && path == "/applications/app-1":
		s.deleted = true
		_ = json.NewEncoder(w).Encode(map[string]string{"status": "deleted"})
	case path == "/applications/app-1/lease-policy":
		if r.Method == http.MethodPut {
			_ = json.NewDecoder(r.Body).Decode(&s.lastLease)
			s.override = s.lastLease
		}
		eff := map[string]any{"defaultTtl": 900, "maxTtl": 86400, "renewable": true}
		if v, ok := s.override["defaultTtl"]; ok && v != nil {
			eff["defaultTtl"] = v
		}
		_ = json.NewEncoder(w).Encode(map[string]any{"override": s.override, "effective": eff})
	default:
		w.WriteHeader(http.StatusNotFound)
	}
}

func newStub(t *testing.T) (*stubAdmin, AdminClient) {
	t.Helper()
	s := &stubAdmin{t: t, status: "pending", override: map[string]any{"defaultTtl": nil, "maxTtl": nil, "renewable": nil}}
	srv := httptest.NewServer(s)
	t.Cleanup(srv.Close)
	return s, NewAdminClient(srv.URL+"/index.php", "svc-apps", "app-password")
}

// The admin client sends the app password and the OCS-APIRequest header,
// and a refusal names the area the account needs.
func TestAdminClientAuthAndRefusal(t *testing.T) {
	_, c := newStub(t)
	if _, err := c.GetApplication("app-1"); err != nil {
		t.Fatal(err)
	}
	srv := httptest.NewServer(&stubAdmin{t: t})
	defer srv.Close()
	_, err := NewAdminClient(srv.URL+"/index.php", "svc-apps", "wrong").GetApplication("app-1")
	if err == nil || !strings.Contains(err.Error(), "Applications and machine access") {
		t.Fatalf("err %v", err)
	}
	if _, err := c.GetApplication("missing"); !errors.Is(err, ErrNotFound) {
		t.Fatalf("missing: %v", err)
	}
}

func applicationPlan(t *testing.T, allow bool) (tfsdk.Plan, resource.SchemaResponse) {
	return applicationValues(t, allow, tftypes.NewValue(tftypes.String, tftypes.UnknownValue))
}

// applicationValues builds the resource object with id set to id and the
// other computed values unknown.
func applicationValues(t *testing.T, allow bool, id tftypes.Value) (tfsdk.Plan, resource.SchemaResponse) {
	t.Helper()
	var rs resource.SchemaResponse
	NewApplicationResource().Schema(context.Background(), resource.SchemaRequest{}, &rs)
	typ := rs.Schema.Type().TerraformType(context.Background()).(tftypes.Object)
	vals := map[string]tftypes.Value{}
	for name, at := range typ.AttributeTypes {
		vals[name] = tftypes.NewValue(at, nil)
	}
	vals["name"] = tftypes.NewValue(tftypes.String, "ci-runner")
	vals["type"] = tftypes.NewValue(tftypes.String, "external")
	vals["csr_pem"] = tftypes.NewValue(tftypes.String, "CSR")
	vals["allow_vault_deletion"] = tftypes.NewValue(tftypes.Bool, allow)
	for _, computed := range []string{"certificate_pem", "status"} {
		vals[computed] = tftypes.NewValue(tftypes.String, tftypes.UnknownValue)
	}
	vals["id"] = id
	return tfsdk.Plan{Schema: rs.Schema, Raw: tftypes.NewValue(typ, vals)}, rs
}

// Create registers from the CSR, approves a pending application and stores
// the certificate; read after a deletion in Keepiq drops it from state.
func TestApplicationCreateApprovesAndReadsTheCertificate(t *testing.T) {
	s, c := newStub(t)
	r := &applicationResource{admin: c}
	plan, rs := applicationPlan(t, false)
	resp := resource.CreateResponse{State: tfsdk.State{Schema: rs.Schema}}
	r.Create(context.Background(), resource.CreateRequest{Plan: plan}, &resp)
	if resp.Diagnostics.HasError() {
		t.Fatal(resp.Diagnostics)
	}
	var got applicationModel
	resp.State.Get(context.Background(), &got)
	if got.ID.ValueString() != "app-1" || got.Status.ValueString() != "active" || got.CertificatePEM.ValueString() == "" {
		t.Fatalf("state %+v", got)
	}
	want := []string{"POST /applications", "POST /applications/app-1/approve", "GET /applications/app-1"}
	if strings.Join(s.calls, ",") != strings.Join(want, ",") {
		t.Fatalf("calls %v", s.calls)
	}

	s.deleted = true
	read := resource.ReadResponse{State: resp.State}
	r.Read(context.Background(), resource.ReadRequest{State: resp.State}, &read)
	if read.Diagnostics.HasError() || !read.State.Raw.IsNull() {
		t.Fatalf("a deleted application stays in state: %v", read.Diagnostics)
	}
}

// Destroy without allow_vault_deletion is refused and deletes nothing.
func TestApplicationDestroyIsRefusedWithoutTheFlag(t *testing.T) {
	s, c := newStub(t)
	r := &applicationResource{admin: c}
	for _, allow := range []bool{false, true} {
		plan, rs := applicationValues(t, allow, tftypes.NewValue(tftypes.String, "app-1"))
		state := tfsdk.State{Schema: rs.Schema, Raw: plan.Raw}
		var del resource.DeleteResponse
		r.Delete(context.Background(), resource.DeleteRequest{State: state}, &del)
		if !allow {
			if !del.Diagnostics.HasError() || del.Diagnostics.Errors()[0].Detail() != VaultDeletionRefused {
				t.Fatalf("diagnostics %v", del.Diagnostics)
			}
			if s.deleted {
				t.Fatal("deleted without allow_vault_deletion")
			}
			continue
		}
		if del.Diagnostics.HasError() || !s.deleted {
			t.Fatalf("allowed destroy did not delete: %v", del.Diagnostics)
		}
	}
}

// The lease policy writes the override, a left-out value inherits, and
// destroy removes the override.
func TestLeasePolicyWritesAndRemovesTheOverride(t *testing.T) {
	s, c := newStub(t)
	r := &leasePolicyResource{admin: c}
	var rs resource.SchemaResponse
	r.Schema(context.Background(), resource.SchemaRequest{}, &rs)
	typ := rs.Schema.Type().TerraformType(context.Background()).(tftypes.Object)
	vals := map[string]tftypes.Value{}
	for name, at := range typ.AttributeTypes {
		vals[name] = tftypes.NewValue(at, tftypes.UnknownValue)
	}
	vals["application_id"] = tftypes.NewValue(tftypes.String, "app-1")
	vals["default_ttl"] = tftypes.NewValue(tftypes.Number, 600)
	vals["max_ttl"] = tftypes.NewValue(tftypes.Number, nil)
	vals["renewable"] = tftypes.NewValue(tftypes.Bool, nil)
	plan := tfsdk.Plan{Schema: rs.Schema, Raw: tftypes.NewValue(typ, vals)}

	resp := resource.CreateResponse{State: tfsdk.State{Schema: rs.Schema}}
	r.Create(context.Background(), resource.CreateRequest{Plan: plan}, &resp)
	if resp.Diagnostics.HasError() {
		t.Fatal(resp.Diagnostics)
	}
	if s.lastLease["defaultTtl"] != float64(600) || s.lastLease["maxTtl"] != nil {
		t.Fatalf("sent %v", s.lastLease)
	}
	var got leasePolicyModel
	resp.State.Get(context.Background(), &got)
	if got.EffectiveDefault.ValueInt64() != 600 || got.EffectiveMax.ValueInt64() != 86400 || !got.MaxTTL.IsNull() {
		t.Fatalf("state %+v", got)
	}

	var del resource.DeleteResponse
	r.Delete(context.Background(), resource.DeleteRequest{State: resp.State}, &del)
	if del.Diagnostics.HasError() || s.lastLease["defaultTtl"] != nil {
		t.Fatalf("destroy left the override: %v %v", del.Diagnostics, s.lastLease)
	}
}

// The admin password is sensitive, and the provider lists both resources.
func TestAdminSchema(t *testing.T) {
	var ps provider.SchemaResponse
	New("test")().Schema(context.Background(), provider.SchemaRequest{}, &ps)
	if !ps.Schema.Attributes["admin_password"].IsSensitive() {
		t.Fatal("admin_password is not sensitive")
	}
	names := map[string]bool{}
	for _, f := range New("test")().Resources(context.Background()) {
		var m resource.MetadataResponse
		f().Metadata(context.Background(), resource.MetadataRequest{ProviderTypeName: "keepiq"}, &m)
		names[m.TypeName] = true
	}
	for _, n := range []string{"keepiq_secret", "keepiq_application", "keepiq_application_lease_policy"} {
		if !names[n] {
			t.Fatalf("missing %s in %v", n, names)
		}
	}
}
