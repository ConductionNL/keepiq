package provider

import (
	"context"

	"github.com/hashicorp/terraform-plugin-framework/ephemeral"
	"github.com/hashicorp/terraform-plugin-framework/ephemeral/schema"
	"github.com/hashicorp/terraform-plugin-framework/types"
)

// NewSecretEphemeral is ephemeral "keepiq_secret": a value for one run,
// never written to plan or state.
func NewSecretEphemeral() ephemeral.EphemeralResource { return &secretEphemeral{} }

type secretEphemeral struct{ client Client }

var _ ephemeral.EphemeralResourceWithConfigure = &secretEphemeral{}

type secretEphemeralModel struct {
	ID               types.String `tfsdk:"id"`
	Name             types.String `tfsdk:"name"`
	Folder           types.String `tfsdk:"folder"`
	Value            types.String `tfsdk:"value"`
	Login            types.String `tfsdk:"login"`
	AdditionalFields types.String `tfsdk:"additional_fields"`
}

func (e *secretEphemeral) Metadata(_ context.Context, req ephemeral.MetadataRequest, resp *ephemeral.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_secret"
}

func (e *secretEphemeral) Schema(_ context.Context, _ ephemeral.SchemaRequest, resp *ephemeral.SchemaResponse) {
	resp.Schema = schema.Schema{
		Description: "Reads and decrypts one application secret for this run. Terraform never stores the result in plan or state; pass it to a provider configuration or a write-only argument.",
		Attributes: map[string]schema.Attribute{
			"id":                schema.StringAttribute{Optional: true, Computed: true, Description: "The secret id. Set it, or set name."},
			"name":              schema.StringAttribute{Optional: true, Computed: true, Description: "The exact secret name."},
			"folder":            schema.StringAttribute{Optional: true, Description: "Narrows name to a slash-separated folder path."},
			"value":             schema.StringAttribute{Computed: true, Sensitive: true, Description: "The decrypted key field."},
			"login":             schema.StringAttribute{Computed: true, Sensitive: true, Description: "The decrypted login field."},
			"additional_fields": schema.StringAttribute{Computed: true, Sensitive: true, Description: "The decrypted additional fields (JSON)."},
		},
	}
}

func (e *secretEphemeral) Configure(_ context.Context, req ephemeral.ConfigureRequest, resp *ephemeral.ConfigureResponse) {
	e.client = clientFrom(req.ProviderData, resp.Diagnostics.AddError)
}

func (e *secretEphemeral) Open(ctx context.Context, req ephemeral.OpenRequest, resp *ephemeral.OpenResponse) {
	var m secretEphemeralModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() || e.client == nil {
		return
	}
	s, err := lookup(e.client, m.ID, m.Name, m.Folder)
	if err != nil {
		resp.Diagnostics.AddError("Cannot read Keepiq secret", explain(err, "the secret "+m.Name.ValueString()+m.ID.ValueString()))
		return
	}
	m.ID, m.Name = types.StringValue(s.ID), types.StringValue(s.Name)
	m.Value, m.Login, m.AdditionalFields = types.StringValue(s.Key), types.StringValue(s.Login), types.StringValue(s.AdditionalFields)
	resp.Diagnostics.Append(resp.Result.Set(ctx, &m)...)
}
