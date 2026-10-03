package provider

import (
	"context"
	"testing"

	"github.com/hashicorp/terraform-plugin-framework/datasource"
	dsschema "github.com/hashicorp/terraform-plugin-framework/datasource/schema"
	"github.com/hashicorp/terraform-plugin-framework/ephemeral"
	ephschema "github.com/hashicorp/terraform-plugin-framework/ephemeral/schema"
	"github.com/hashicorp/terraform-plugin-framework/provider"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	rschema "github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/tfsdk"
	"github.com/hashicorp/terraform-plugin-go/tftypes"
)

// The provider's own schema: the key is sensitive, every value attribute of
// the resource is write-only and sensitive, and the ephemeral values are
// sensitive.
func TestSchemas(t *testing.T) {
	ctx := context.Background()
	p := New("test")()
	var ps provider.SchemaResponse
	p.Schema(ctx, provider.SchemaRequest{}, &ps)
	if !ps.Schema.Attributes["private_key"].IsSensitive() {
		t.Fatal("private_key is not sensitive")
	}

	var rs resource.SchemaResponse
	NewSecretResource().Schema(ctx, resource.SchemaRequest{}, &rs)
	if rs.Diagnostics.HasError() {
		t.Fatal(rs.Diagnostics)
	}
	for _, n := range []string{"value_wo", "login_wo", "additional_fields_wo"} {
		a := rs.Schema.Attributes[n].(rschema.StringAttribute)
		if !a.WriteOnly || !a.Sensitive {
			t.Fatalf("%s: write-only %v sensitive %v", n, a.WriteOnly, a.Sensitive)
		}
	}
	for n, a := range rs.Schema.Attributes {
		if a.IsComputed() && (n == "value" || n == "login" || n == "additional_fields") {
			t.Fatalf("resource stores a value attribute %s", n)
		}
	}

	var es ephemeral.SchemaResponse
	NewSecretEphemeral().Schema(ctx, ephemeral.SchemaRequest{}, &es)
	for _, n := range []string{"value", "login", "additional_fields"} {
		if !es.Schema.Attributes[n].(ephschema.StringAttribute).Sensitive {
			t.Fatalf("ephemeral %s is not sensitive", n)
		}
	}
}

// 1.5: the metadata data source has no value attribute.
func TestMetadataHasNoValue(t *testing.T) {
	var ds datasource.SchemaResponse
	NewSecretMetadataDataSource().Schema(context.Background(), datasource.SchemaRequest{}, &ds)
	if len(ds.Schema.Attributes) != len(MetadataAttributes) {
		t.Fatalf("attributes %d, want %d", len(ds.Schema.Attributes), len(MetadataAttributes))
	}
	for _, n := range MetadataAttributes {
		if _, ok := ds.Schema.Attributes[n].(dsschema.StringAttribute); !ok {
			t.Fatalf("missing %s", n)
		}
	}
	for _, banned := range []string{"value", "login", "additional_fields", "key"} {
		if _, ok := ds.Schema.Attributes[banned]; ok {
			t.Fatalf("data source offers %s", banned)
		}
	}
}

func configWith(t *testing.T, value tftypes.Value) tfsdk.Config {
	t.Helper()
	var rs resource.SchemaResponse
	NewSecretResource().Schema(context.Background(), resource.SchemaRequest{}, &rs)
	typ := rs.Schema.Type().TerraformType(context.Background()).(tftypes.Object)
	vals := map[string]tftypes.Value{}
	for name, at := range typ.AttributeTypes {
		vals[name] = tftypes.NewValue(at, nil)
	}
	vals["name"] = tftypes.NewValue(tftypes.String, "api-token")
	vals["value_wo"] = value
	vals["value_wo_version"] = tftypes.NewValue(tftypes.Number, 1)
	return tfsdk.Config{Schema: rs.Schema, Raw: tftypes.NewValue(typ, vals)}
}

// 1.6: a client without write-only support is refused with a clear message.
func TestWriteOnlyRefusedOnOlderTerraform(t *testing.T) {
	r := &secretResource{}
	var resp resource.ValidateConfigResponse
	r.ValidateConfig(context.Background(), resource.ValidateConfigRequest{
		Config: configWith(t, tftypes.NewValue(tftypes.String, "s3cret")),
	}, &resp)
	if !resp.Diagnostics.HasError() || resp.Diagnostics.Errors()[0].Detail() != WriteOnlyUnsupported {
		t.Fatalf("diagnostics %v", resp.Diagnostics)
	}
	var ok resource.ValidateConfigResponse
	req := resource.ValidateConfigRequest{Config: configWith(t, tftypes.NewValue(tftypes.String, "s3cret"))}
	req.ClientCapabilities.WriteOnlyAttributesAllowed = true
	r.ValidateConfig(context.Background(), req, &ok)
	if ok.Diagnostics.HasError() {
		t.Fatalf("a capable client was refused: %v", ok.Diagnostics)
	}
}

// 1.4: destroy warns and names the secret.
func TestDestroyWarnsAndNamesTheSecret(t *testing.T) {
	var rs resource.SchemaResponse
	NewSecretResource().Schema(context.Background(), resource.SchemaRequest{}, &rs)
	cfg := configWith(t, tftypes.NewValue(tftypes.String, nil))
	var resp resource.DeleteResponse
	(&secretResource{}).Delete(context.Background(), resource.DeleteRequest{State: tfsdk.State{Schema: rs.Schema, Raw: cfg.Raw}}, &resp)
	if resp.Diagnostics.HasError() || resp.Diagnostics.WarningsCount() != 1 {
		t.Fatalf("diagnostics %v", resp.Diagnostics)
	}
	if d := resp.Diagnostics.Warnings()[0].Detail(); d != DestroyWarning("api-token") {
		t.Fatalf("warning %q", d)
	}
}
