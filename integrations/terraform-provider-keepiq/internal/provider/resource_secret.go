package provider

import (
	"context"
	"errors"

	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/types"

	keepiq "github.com/ConductionNL/keepiq/sdk/go"
)

// NewSecretResource is resource "keepiq_secret": a secret in the
// application's vault whose values are write-only, so plan and state never
// hold them.
func NewSecretResource() resource.Resource { return &secretResource{} }

type secretResource struct{ client Client }

var (
	_ resource.ResourceWithConfigure      = &secretResource{}
	_ resource.ResourceWithImportState    = &secretResource{}
	_ resource.ResourceWithValidateConfig = &secretResource{}
)

type secretModel struct {
	ID                 types.String `tfsdk:"id"`
	Name               types.String `tfsdk:"name"`
	URL                types.String `tfsdk:"url"`
	TypeID             types.String `tfsdk:"type_id"`
	ValueWO            types.String `tfsdk:"value_wo"`
	LoginWO            types.String `tfsdk:"login_wo"`
	AdditionalFieldsWO types.String `tfsdk:"additional_fields_wo"`
	ValueWOVersion     types.Int64  `tfsdk:"value_wo_version"`
	FolderPath         types.String `tfsdk:"folder_path"`
	ETag               types.String `tfsdk:"etag"`
	KeyUpdatedAt       types.String `tfsdk:"key_updated_at"`
	UpdatedAt          types.String `tfsdk:"updated_at"`
	ExpiresAt          types.String `tfsdk:"expires_at"`
}

func (r *secretResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_secret"
}

func (r *secretResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	keep := []planmodifier.String{stringplanmodifier.UseStateForUnknown()}
	resp.Schema = schema.Schema{
		Description: "A secret in the application's vault. Its values are write-only: the provider encrypts them to the application's key and Terraform never stores them. Change value_wo_version to write new values. Destroy removes the secret from state only; an administrator deletes it in Keepiq. Needs Terraform 1.11 or later.",
		Attributes: map[string]schema.Attribute{
			"id":       schema.StringAttribute{Computed: true, PlanModifiers: keep},
			"name":     schema.StringAttribute{Required: true, Description: "The secret name."},
			"url":      schema.StringAttribute{Optional: true, Description: "A URL stored with the secret (not secret)."},
			"type_id":  schema.StringAttribute{Optional: true, Description: "The secret type id; Keepiq picks one when empty."},
			"value_wo": schema.StringAttribute{Required: true, Sensitive: true, WriteOnly: true, Description: "The value (key field). Write-only."},
			"login_wo": schema.StringAttribute{Optional: true, Sensitive: true, WriteOnly: true, Description: "The login field. Write-only."},
			"additional_fields_wo": schema.StringAttribute{Optional: true, Sensitive: true, WriteOnly: true,
				Description: "The additional fields (JSON). Write-only."},
			"value_wo_version": schema.Int64Attribute{Required: true,
				Description: "Change this number to write the write-only values again; Terraform cannot compare values it never stores."},
			"folder_path":    schema.StringAttribute{Computed: true, PlanModifiers: keep},
			"etag":           schema.StringAttribute{Computed: true},
			"key_updated_at": schema.StringAttribute{Computed: true},
			"updated_at":     schema.StringAttribute{Computed: true},
			"expires_at":     schema.StringAttribute{Computed: true},
		},
	}
}

func (r *secretResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, resp.Diagnostics.AddError)
}

// WriteOnlyUnsupported is the diagnostic for a Terraform without write-only arguments.
const WriteOnlyUnsupported = "This Terraform version cannot keep value_wo out of state. Use Terraform 1.11 or later (or an OpenTofu release with write-only arguments); until then, read values with the keepiq_secret ephemeral resource."

// ValidateConfig refuses write-only values on a client that would store them.
func (r *secretResource) ValidateConfig(ctx context.Context, req resource.ValidateConfigRequest, resp *resource.ValidateConfigResponse) {
	if req.ClientCapabilities.WriteOnlyAttributesAllowed {
		return
	}
	var v types.String
	resp.Diagnostics.Append(req.Config.GetAttribute(ctx, path.Root("value_wo"), &v)...)
	if !v.IsNull() {
		resp.Diagnostics.AddAttributeError(path.Root("value_wo"), "Write-only arguments are not supported", WriteOnlyUnsupported)
	}
}

// writeFields builds the write from config (write-only values live only there).
func writeFields(cfg secretModel, withValues bool) map[string]string {
	f := map[string]string{"name": cfg.Name.ValueString()}
	if !cfg.URL.IsNull() {
		f["url"] = cfg.URL.ValueString()
	}
	if !cfg.TypeID.IsNull() {
		f["typeId"] = cfg.TypeID.ValueString()
	}
	if withValues {
		f["key"] = cfg.ValueWO.ValueString()
		if !cfg.LoginWO.IsNull() {
			f["login"] = cfg.LoginWO.ValueString()
		}
		if !cfg.AdditionalFieldsWO.IsNull() {
			f["additionalFields"] = cfg.AdditionalFieldsWO.ValueString()
		}
	}
	return f
}

// fill copies metadata into the state model; write-only values stay null.
func fill(m *secretModel, s *keepiq.Secret) {
	m.ID, m.Name, m.FolderPath = types.StringValue(s.ID), types.StringValue(s.Name), types.StringValue(s.FolderPath)
	// url is optional and not computed: follow Keepiq only when the
	// configuration manages it, so an unmanaged url never shows as a diff.
	if !m.URL.IsNull() {
		m.URL = strOrNull(s.URL)
	}
	m.ETag, m.KeyUpdatedAt, m.UpdatedAt, m.ExpiresAt = strOrNull(s.ETag), strOrNull(s.KeyUpdatedAt), strOrNull(s.UpdatedAt), strOrNull(s.ExpiresAt)
	m.ValueWO, m.LoginWO, m.AdditionalFieldsWO = types.StringNull(), types.StringNull(), types.StringNull()
}

func (r *secretResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	var cfg, plan secretModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &cfg)...)
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	s, err := r.client.Create(writeFields(cfg, true))
	if err != nil {
		resp.Diagnostics.AddError("Cannot create Keepiq secret", explain(err, cfg.Name.ValueString()))
		return
	}
	fill(&plan, s)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

// Read records what Keepiq holds now. A value rotated outside Terraform moves
// key_updated_at and etag, which are computed, so Terraform shows no diff:
// only value_wo_version triggers a Terraform write.
func (r *secretResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	var st secretModel
	resp.Diagnostics.Append(req.State.Get(ctx, &st)...)
	if resp.Diagnostics.HasError() {
		return
	}
	s, err := r.client.GetByID(st.ID.ValueString())
	if errors.Is(err, keepiq.ErrNotFound) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		resp.Diagnostics.AddError("Cannot read Keepiq secret", explain(err, st.Name.ValueString()))
		return
	}
	fill(&st, s)
	resp.Diagnostics.Append(resp.State.Set(ctx, &st)...)
}

func (r *secretResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	var cfg, plan, st secretModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &cfg)...)
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &st)...)
	if resp.Diagnostics.HasError() {
		return
	}
	withValues := !plan.ValueWOVersion.Equal(st.ValueWOVersion)
	// The ETag from the last refresh guards the write: a change made in
	// Keepiq since then is refused instead of overwritten.
	s, err := r.client.UpdateIfMatch(st.ID.ValueString(), st.ETag.ValueString(), writeFields(cfg, withValues))
	if errors.Is(err, keepiq.ErrPreconditionFailed) {
		resp.Diagnostics.AddError("Keepiq secret changed during this run",
			"The secret "+st.Name.ValueString()+" changed in Keepiq after Terraform read it. Run terraform apply again.")
		return
	}
	if err != nil {
		resp.Diagnostics.AddError("Cannot update Keepiq secret", explain(err, st.Name.ValueString()))
		return
	}
	plan.ID = st.ID
	fill(&plan, s)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

// DestroyWarning names the secret and says who deletes it.
func DestroyWarning(name string) string {
	return "Terraform no longer manages the Keepiq secret " + name + ", but it still exists in the application vault: the machine API cannot delete secrets, by design. An administrator deletes it in Keepiq."
}

// Delete removes the secret from state only and warns.
func (r *secretResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	var st secretModel
	resp.Diagnostics.Append(req.State.Get(ctx, &st)...)
	resp.Diagnostics.AddWarning("Keepiq secret left in the vault", DestroyWarning(st.Name.ValueString()))
}

// ImportState adopts an existing secret by id. Set value_wo and
// value_wo_version afterwards; the first apply then writes the value.
func (r *secretResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	resource.ImportStatePassthroughID(ctx, path.Root("id"), req, resp)
}
