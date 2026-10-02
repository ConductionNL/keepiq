// Package provider implements the keepiq Terraform provider.
package provider

import (
	"context"
	"os"

	"github.com/hashicorp/terraform-plugin-framework/datasource"
	"github.com/hashicorp/terraform-plugin-framework/ephemeral"
	"github.com/hashicorp/terraform-plugin-framework/provider"
	"github.com/hashicorp/terraform-plugin-framework/provider/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/types"

	keepiq "github.com/ConductionNL/keepiq/sdk/go"
)

// Client is the part of the Go library the provider uses.
type Client interface {
	GetByNameIfNoneMatch(name, folder, etag string) (*keepiq.Secret, error)
	GetByID(id string) (*keepiq.Secret, error)
	Create(fields map[string]string) (*keepiq.Secret, error)
	Update(id string, fields map[string]string) (*keepiq.Secret, error)
	UpdateIfMatch(id, etag string, fields map[string]string) (*keepiq.Secret, error)
}

// KeepiqProvider is the provider.
type KeepiqProvider struct{ version string }

// New returns the provider factory.
func New(version string) func() provider.Provider {
	return func() provider.Provider { return &KeepiqProvider{version: version} }
}

var (
	_ provider.Provider                       = &KeepiqProvider{}
	_ provider.ProviderWithEphemeralResources = &KeepiqProvider{}
)

type providerModel struct {
	URL            types.String `tfsdk:"url"`
	ApplicationID  types.String `tfsdk:"application_id"`
	PrivateKey     types.String `tfsdk:"private_key"`
	CertificatePEM types.String `tfsdk:"certificate"`
}

// Metadata names the provider.
func (p *KeepiqProvider) Metadata(_ context.Context, _ provider.MetadataRequest, resp *provider.MetadataResponse) {
	resp.TypeName = "keepiq"
	resp.Version = p.version
}

// Schema is the provider configuration.
func (p *KeepiqProvider) Schema(_ context.Context, _ provider.SchemaRequest, resp *provider.SchemaResponse) {
	resp.Schema = schema.Schema{
		Description: "Manage Keepiq application secrets as code. Values are decrypted and encrypted in the provider and never written to plan or state.",
		Attributes: map[string]schema.Attribute{
			"url": schema.StringAttribute{
				Optional:    true,
				Description: "The Keepiq (Nextcloud) address; include /index.php without pretty URLs. Defaults to KEEPIQ_URL.",
			},
			"application_id": schema.StringAttribute{
				Optional:    true,
				Description: "The approved Keepiq application. Defaults to KEEPIQ_APP_ID.",
			},
			"private_key": schema.StringAttribute{
				Optional:    true,
				Sensitive:   true,
				Description: "The application's private key (PEM). Defaults to KEEPIQ_APP_KEY, or the file named in KEEPIQ_APP_KEY_FILE. It never leaves the provider.",
			},
			"certificate": schema.StringAttribute{
				Optional:    true,
				Description: "The application's certificate (PEM). With it, the provider checks the key belongs to it and refuses any secret encrypted to another certificate. Defaults to the file named in KEEPIQ_APP_CERT_FILE.",
			},
		},
	}
}

func pick(v types.String, env string) string {
	if !v.IsNull() && !v.IsUnknown() && v.ValueString() != "" {
		return v.ValueString()
	}
	return os.Getenv(env)
}

func fromFile(env string) string {
	if f := os.Getenv(env); f != "" {
		if b, err := os.ReadFile(f); err == nil {
			return string(b)
		}
	}
	return ""
}

// Configure builds the Keepiq client.
func (p *KeepiqProvider) Configure(ctx context.Context, req provider.ConfigureRequest, resp *provider.ConfigureResponse) {
	var m providerModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() {
		return
	}
	url, app := pick(m.URL, "KEEPIQ_URL"), pick(m.ApplicationID, "KEEPIQ_APP_ID")
	key := pick(m.PrivateKey, "KEEPIQ_APP_KEY")
	if key == "" {
		key = fromFile("KEEPIQ_APP_KEY_FILE")
	}
	cert := pick(m.CertificatePEM, "")
	if cert == "" {
		cert = fromFile("KEEPIQ_APP_CERT_FILE")
	}
	if url == "" || app == "" || key == "" {
		resp.Diagnostics.AddError("Keepiq provider is not configured",
			"Set url, application_id and private_key, or KEEPIQ_URL, KEEPIQ_APP_ID and KEEPIQ_APP_KEY (or KEEPIQ_APP_KEY_FILE).")
		return
	}
	var opts []keepiq.Option
	if cert != "" {
		opts = append(opts, keepiq.WithCertificate(cert))
	}
	c, err := keepiq.New(url, app, key, opts...)
	if err != nil {
		resp.Diagnostics.AddError("Keepiq provider cannot start", err.Error())
		return
	}
	resp.ResourceData = Client(c)
	resp.DataSourceData = Client(c)
	resp.EphemeralResourceData = Client(c)
}

// Resources lists the resources.
func (p *KeepiqProvider) Resources(context.Context) []func() resource.Resource {
	return []func() resource.Resource{NewSecretResource}
}

// DataSources lists the data sources.
func (p *KeepiqProvider) DataSources(context.Context) []func() datasource.DataSource {
	return []func() datasource.DataSource{NewSecretMetadataDataSource}
}

// EphemeralResources lists the ephemeral resources.
func (p *KeepiqProvider) EphemeralResources(context.Context) []func() ephemeral.EphemeralResource {
	return []func() ephemeral.EphemeralResource{NewSecretEphemeral}
}

func clientFrom(data any, add func(string, string)) Client {
	if data == nil {
		return nil
	}
	c, ok := data.(Client)
	if !ok {
		add("Unexpected provider data", "the provider passed a value that is not a Keepiq client")
		return nil
	}
	return c
}
