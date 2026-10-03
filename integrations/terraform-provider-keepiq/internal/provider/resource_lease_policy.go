package provider

import (
	"context"

	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/types"
)

// NewApplicationLeasePolicyResource is resource
// "keepiq_application_lease_policy": an application's lease TTL override.
func NewApplicationLeasePolicyResource() resource.Resource { return &leasePolicyResource{} }

type leasePolicyResource struct{ admin AdminClient }

var (
	_ resource.ResourceWithConfigure   = &leasePolicyResource{}
	_ resource.ResourceWithImportState = &leasePolicyResource{}
)

type leasePolicyModel struct {
	ApplicationID      types.String `tfsdk:"application_id"`
	DefaultTTL         types.Int64  `tfsdk:"default_ttl"`
	MaxTTL             types.Int64  `tfsdk:"max_ttl"`
	Renewable          types.Bool   `tfsdk:"renewable"`
	EffectiveDefault   types.Int64  `tfsdk:"effective_default_ttl"`
	EffectiveMax       types.Int64  `tfsdk:"effective_max_ttl"`
	EffectiveRenewable types.Bool   `tfsdk:"effective_renewable"`
}

func (r *leasePolicyResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_application_lease_policy"
}

func (r *leasePolicyResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	resp.Schema = schema.Schema{
		Description: "The lease TTL override of one Keepiq application, through the admin API. An argument left out inherits the instance setting; destroy removes the override. Needs admin_user and admin_password on the provider.",
		Attributes: map[string]schema.Attribute{
			"application_id": schema.StringAttribute{Required: true,
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplace()}, Description: "The application."},
			"default_ttl":           schema.Int64Attribute{Optional: true, Description: "Default lease TTL in seconds, at least 60."},
			"max_ttl":               schema.Int64Attribute{Optional: true, Description: "Maximum lease TTL in seconds, at least 60."},
			"renewable":             schema.BoolAttribute{Optional: true, Description: "Whether a lease may be renewed."},
			"effective_default_ttl": schema.Int64Attribute{Computed: true, Description: "The default TTL that applies."},
			"effective_max_ttl":     schema.Int64Attribute{Computed: true, Description: "The maximum TTL that applies."},
			"effective_renewable":   schema.BoolAttribute{Computed: true, Description: "Whether renewal applies."},
		},
	}
}

func (r *leasePolicyResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.admin = adminFrom(req.ProviderData, resp.Diagnostics.AddError)
}

func int64Ptr(v types.Int64) *int64 {
	if v.IsNull() || v.IsUnknown() {
		return nil
	}
	n := v.ValueInt64()
	return &n
}

func boolPtr(v types.Bool) *bool {
	if v.IsNull() || v.IsUnknown() {
		return nil
	}
	b := v.ValueBool()
	return &b
}

func int64OrNull(p *int64) types.Int64 {
	if p == nil {
		return types.Int64Null()
	}
	return types.Int64Value(*p)
}

func boolOrNull(p *bool) types.Bool {
	if p == nil {
		return types.BoolNull()
	}
	return types.BoolValue(*p)
}

func fillLease(m *leasePolicyModel, p *LeasePolicy) {
	if p.Override != nil {
		m.DefaultTTL, m.MaxTTL, m.Renewable = int64OrNull(p.Override.DefaultTTL), int64OrNull(p.Override.MaxTTL), boolOrNull(p.Override.Renewable)
	}
	m.EffectiveDefault, m.EffectiveMax, m.EffectiveRenewable = int64OrNull(p.Effective.DefaultTTL), int64OrNull(p.Effective.MaxTTL), boolOrNull(p.Effective.Renewable)
}

func (r *leasePolicyResource) write(ctx context.Context, plan leasePolicyModel, set func(any) error, add func(string, string)) {
	if r.admin == nil {
		return
	}
	p, err := r.admin.SetLeasePolicy(plan.ApplicationID.ValueString(), LeaseValues{
		DefaultTTL: int64Ptr(plan.DefaultTTL), MaxTTL: int64Ptr(plan.MaxTTL), Renewable: boolPtr(plan.Renewable),
	})
	if err != nil {
		add("Cannot set Keepiq lease policy", err.Error())
		return
	}
	fillLease(&plan, p)
	if err := set(&plan); err != nil {
		add("Cannot store Keepiq lease policy", err.Error())
	}
	_ = ctx
}

func (r *leasePolicyResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	var plan leasePolicyModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	r.write(ctx, plan, func(v any) error { resp.Diagnostics.Append(resp.State.Set(ctx, v)...); return nil }, resp.Diagnostics.AddError)
}

func (r *leasePolicyResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	var plan leasePolicyModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	r.write(ctx, plan, func(v any) error { resp.Diagnostics.Append(resp.State.Set(ctx, v)...); return nil }, resp.Diagnostics.AddError)
}

func (r *leasePolicyResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	var state leasePolicyModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() || r.admin == nil {
		return
	}
	p, err := r.admin.GetLeasePolicy(state.ApplicationID.ValueString())
	if err == ErrNotFound {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		resp.Diagnostics.AddError("Cannot read Keepiq lease policy", err.Error())
		return
	}
	fillLease(&state, p)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

// Delete removes the override: every value inherits again.
func (r *leasePolicyResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	var state leasePolicyModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() || r.admin == nil {
		return
	}
	if _, err := r.admin.SetLeasePolicy(state.ApplicationID.ValueString(), LeaseValues{}); err != nil && err != ErrNotFound {
		resp.Diagnostics.AddError("Cannot remove Keepiq lease policy", err.Error())
	}
}

func (r *leasePolicyResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	resource.ImportStatePassthroughID(ctx, path.Root("application_id"), req, resp)
}
