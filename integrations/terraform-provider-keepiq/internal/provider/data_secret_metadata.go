package provider

import (
	"context"

	"github.com/hashicorp/terraform-plugin-framework/datasource"
	"github.com/hashicorp/terraform-plugin-framework/datasource/schema"
	"github.com/hashicorp/terraform-plugin-framework/types"
)

// NewSecretMetadataDataSource is data "keepiq_secret_metadata": everything
// about a secret except its values (data sources are stored in state).
func NewSecretMetadataDataSource() datasource.DataSource { return &secretMetadata{} }

type secretMetadata struct{ client Client }

var _ datasource.DataSourceWithConfigure = &secretMetadata{}

type secretMetadataModel struct {
	ID                     types.String `tfsdk:"id"`
	Name                   types.String `tfsdk:"name"`
	Folder                 types.String `tfsdk:"folder"`
	FolderPath             types.String `tfsdk:"folder_path"`
	URL                    types.String `tfsdk:"url"`
	CreatedAt              types.String `tfsdk:"created_at"`
	UpdatedAt              types.String `tfsdk:"updated_at"`
	KeyUpdatedAt           types.String `tfsdk:"key_updated_at"`
	ExpiresAt              types.String `tfsdk:"expires_at"`
	CertificateFingerprint types.String `tfsdk:"certificate_fingerprint"`
}

// MetadataAttributes are the data source's attributes; a test checks none is a value.
var MetadataAttributes = []string{"id", "name", "folder", "folder_path", "url", "created_at", "updated_at", "key_updated_at", "expires_at", "certificate_fingerprint"}

func (d *secretMetadata) Metadata(_ context.Context, req datasource.MetadataRequest, resp *datasource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_secret_metadata"
}

func (d *secretMetadata) Schema(_ context.Context, _ datasource.SchemaRequest, resp *datasource.SchemaResponse) {
	resp.Schema = schema.Schema{
		Description: "Metadata of one application secret: id, timestamps, expiry and certificate fingerprint. It offers no value; use the keepiq_secret ephemeral resource for that.",
		Attributes: map[string]schema.Attribute{
			"id":                      schema.StringAttribute{Optional: true, Computed: true},
			"name":                    schema.StringAttribute{Optional: true, Computed: true},
			"folder":                  schema.StringAttribute{Optional: true},
			"folder_path":             schema.StringAttribute{Computed: true},
			"url":                     schema.StringAttribute{Computed: true},
			"created_at":              schema.StringAttribute{Computed: true},
			"updated_at":              schema.StringAttribute{Computed: true},
			"key_updated_at":          schema.StringAttribute{Computed: true},
			"expires_at":              schema.StringAttribute{Computed: true},
			"certificate_fingerprint": schema.StringAttribute{Computed: true},
		},
	}
}

func (d *secretMetadata) Configure(_ context.Context, req datasource.ConfigureRequest, resp *datasource.ConfigureResponse) {
	d.client = clientFrom(req.ProviderData, resp.Diagnostics.AddError)
}

func (d *secretMetadata) Read(ctx context.Context, req datasource.ReadRequest, resp *datasource.ReadResponse) {
	var m secretMetadataModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() || d.client == nil {
		return
	}
	s, err := lookup(d.client, m.ID, m.Name, m.Folder)
	if err != nil {
		resp.Diagnostics.AddError("Cannot read Keepiq secret", explain(err, "the secret "+m.Name.ValueString()+m.ID.ValueString()))
		return
	}
	m.ID, m.Name, m.FolderPath, m.URL = types.StringValue(s.ID), types.StringValue(s.Name), types.StringValue(s.FolderPath), strOrNull(s.URL)
	m.CreatedAt, m.UpdatedAt, m.KeyUpdatedAt = strOrNull(s.CreatedAt), strOrNull(s.UpdatedAt), strOrNull(s.KeyUpdatedAt)
	m.ExpiresAt, m.CertificateFingerprint = strOrNull(s.ExpiresAt), strOrNull(s.CertificateFingerprint)
	resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
}
