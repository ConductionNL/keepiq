// Package provider implements the keepiq Terraform provider.
package provider

import (
	"context"
	"os"
	"strings"

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
	AdminUser      types.String `tfsdk:"admin_user"`
	AdminPassword  types.String `tfsdk:"admin_password"`
}

// clients is what Configure hands to resources: the application's machine
// client for secrets, and the admin API client for applications. Either may
// be nil when its credentials are not configured.
type clients struct {
	machine Client
	admin   AdminClient
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
			"admin_user": schema.StringAttribute{
				Optional:    true,
				Description: "A Nextcloud user for the admin API (keepiq_application and keepiq_application_lease_policy), holding the Applications and machine access area. Defaults to KEEPIQ_ADMIN_USER.",
			},
			"admin_password": schema.StringAttribute{
				Optional:    true,
				Sensitive:   true,
				Description: "A Nextcloud app password of admin_user. Defaults to KEEPIQ_ADMIN_PASSWORD, or the file named in KEEPIQ_ADMIN_PASSWORD_FILE.",
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
	adminUser, adminPassword := pick(m.AdminUser, "KEEPIQ_ADMIN_USER"), pick(m.AdminPassword, "KEEPIQ_ADMIN_PASSWORD")
	if adminPassword == "" {
		adminPassword = strings.TrimSpace(fromFile("KEEPIQ_ADMIN_PASSWORD_FILE"))
	}
	machine := app != "" && key != ""
	admin := adminUser != "" && adminPassword != ""
	if url == "" || (!machine && !admin) {
		resp.Diagnostics.AddError("Keepiq provider is not configured",
			"Set url, and application_id with private_key (or KEEPIQ_URL, KEEPIQ_APP_ID and KEEPIQ_APP_KEY or KEEPIQ_APP_KEY_FILE) for secrets, and/or admin_user with admin_password (or KEEPIQ_ADMIN_USER and KEEPIQ_ADMIN_PASSWORD) for applications.")
		return
	}
	data := &clients{}
	if machine {
		var opts []keepiq.Option
		if cert != "" {
			opts = append(opts, keepiq.WithCertificate(cert))
		}
		c, err := keepiq.New(url, app, key, opts...)
		if err != nil {
			resp.Diagnostics.AddError("Keepiq provider cannot start", err.Error())
			return
		}
		data.machine = Client(c)
	}
	if admin {
		data.admin = NewAdminClient(url, adminUser, adminPassword)
	}
	resp.ResourceData = data
	resp.DataSourceData = data
	resp.EphemeralResourceData = data
}

// Resources lists the resources.
func (p *KeepiqProvider) Resources(context.Context) []func() resource.Resource {
	return []func() resource.Resource{NewSecretResource, NewApplicationResource, NewApplicationLeasePolicyResource}
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
	switch d := data.(type) {
	case nil:
		return nil
	case Client:
		return d
	case *clients:
		if d.machine == nil {
			add("Keepiq secrets are not configured", "Set application_id and private_key on the provider (or KEEPIQ_APP_ID and KEEPIQ_APP_KEY) to manage secrets.")
		}
		return d.machine
	}
	add("Unexpected provider data", "the provider passed a value that is not a Keepiq client")
	return nil
}

func adminFrom(data any, add func(string, string)) AdminClient {
	switch d := data.(type) {
	case nil:
		return nil
	case *clients:
		if d.admin == nil {
			add("Keepiq admin API is not configured", "Set admin_user and admin_password on the provider (or KEEPIQ_ADMIN_USER and KEEPIQ_ADMIN_PASSWORD) to manage applications.")
		}
		return d.admin
	}
	add("Unexpected provider data", "the provider passed a value that is not a Keepiq client")
	return nil
}
