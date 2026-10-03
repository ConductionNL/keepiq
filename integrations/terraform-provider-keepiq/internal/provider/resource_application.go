package provider

import (
	"context"
	"errors"

	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/booldefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringdefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"
)

// NewApplicationResource is resource "keepiq_application": an application
// registered and approved through the admin API.
func NewApplicationResource() resource.Resource { return &applicationResource{} }

type applicationResource struct{ admin AdminClient }

var (
	_ resource.ResourceWithConfigure   = &applicationResource{}
	_ resource.ResourceWithImportState = &applicationResource{}
)

type applicationModel struct {
	ID                 types.String `tfsdk:"id"`
	Name               types.String `tfsdk:"name"`
	Description        types.String `tfsdk:"description"`
	Type               types.String `tfsdk:"type"`
	CSRPEM             types.String `tfsdk:"csr_pem"`
	CertificatePEM     types.String `tfsdk:"certificate_pem"`
	Status             types.String `tfsdk:"status"`
	AllowVaultDeletion types.Bool   `tfsdk:"allow_vault_deletion"`
}

// VaultDeletionRefused is the error a destroy gives without allow_vault_deletion.
const VaultDeletionRefused = "Deleting a Keepiq application deletes its vault and every secret in it. Set allow_vault_deletion = true on this resource, apply, and destroy again if that is what you want."

func (r *applicationResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_application"
}

func (r *applicationResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	replace := []planmodifier.String{stringplanmodifier.RequiresReplace()}
	keep := []planmodifier.String{stringplanmodifier.UseStateForUnknown()}
	resp.Schema = schema.Schema{
		Description: "A Keepiq application, registered and approved through the admin API. Needs admin_user and admin_password on the provider, for an account holding the Applications and machine access area. The private key stays with whoever made the CSR: generating it with tls_private_key stores it in state.",
		Attributes: map[string]schema.Attribute{
			"id":          schema.StringAttribute{Computed: true, PlanModifiers: keep},
			"name":        schema.StringAttribute{Required: true, PlanModifiers: replace, Description: "The application name."},
			"description": schema.StringAttribute{Optional: true, PlanModifiers: replace, Description: "A description."},
			"type": schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString("external"),
				PlanModifiers: replace, Validators: []validator.String{oneOf{"internal", "external"}},
				Description: "internal or external (default)."},
			"csr_pem": schema.StringAttribute{Optional: true, PlanModifiers: replace,
				Description: "A PKCS#10 CSR in PEM. Keepiq signs it and the certificate appears in certificate_pem."},
			"certificate_pem": schema.StringAttribute{Computed: true, PlanModifiers: keep, Description: "The application's certificate (PEM)."},
			"status":          schema.StringAttribute{Computed: true, Description: "The application status in Keepiq."},
			"allow_vault_deletion": schema.BoolAttribute{Optional: true, Computed: true, Default: booldefault.StaticBool(false),
				Description: "Destroy deletes the application and its vault only when this is true."},
		},
	}
}

func (r *applicationResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.admin = adminFrom(req.ProviderData, resp.Diagnostics.AddError)
}

func fillApplication(m *applicationModel, a *AdminApplication) {
	m.ID, m.Name, m.Status = types.StringValue(a.ID), types.StringValue(a.Name), types.StringValue(a.Status)
	if a.Type != "" {
		m.Type = types.StringValue(a.Type)
	}
	if !m.Description.IsNull() {
		m.Description = strOrNull(a.Description)
	}
	m.CertificatePEM = strOrNull(a.Certificate)
}

func (r *applicationResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	var plan applicationModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() || r.admin == nil {
		return
	}
	a, err := r.admin.CreateApplication(plan.Name.ValueString(), plan.Description.ValueString(), plan.Type.ValueString(), plan.CSRPEM.ValueString())
	if err != nil {
		resp.Diagnostics.AddError("Cannot register Keepiq application", err.Error())
		return
	}
	// An administrator's registration is active at once; approve only what
	// is still pending.
	if a.Status == "pending" {
		if a, err = r.admin.ApproveApplication(a.ID); err != nil {
			resp.Diagnostics.AddError("Cannot approve Keepiq application", err.Error())
			return
		}
	}
	if full, err := r.admin.GetApplication(a.ID); err == nil {
		a = full
	}
	fillApplication(&plan, a)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *applicationResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	var state applicationModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() || r.admin == nil {
		return
	}
	a, err := r.admin.GetApplication(state.ID.ValueString())
	if errors.Is(err, ErrNotFound) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		resp.Diagnostics.AddError("Cannot read Keepiq application", err.Error())
		return
	}
	fillApplication(&state, a)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

// Update changes only allow_vault_deletion; every other argument replaces.
func (r *applicationResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	var plan, state applicationModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	state.AllowVaultDeletion = plan.AllowVaultDeletion
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *applicationResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	var state applicationModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if !state.AllowVaultDeletion.ValueBool() {
		resp.Diagnostics.AddError("Keepiq application not deleted", VaultDeletionRefused)
		return
	}
	if r.admin == nil {
		return
	}
	if err := r.admin.DeleteApplication(state.ID.ValueString()); err != nil && !errors.Is(err, ErrNotFound) {
		resp.Diagnostics.AddError("Cannot delete Keepiq application", err.Error())
	}
}

func (r *applicationResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	resource.ImportStatePassthroughID(ctx, path.Root("id"), req, resp)
}
