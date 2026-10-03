package provider

import (
	"context"
	"fmt"
	"strings"

	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
)

// oneOf accepts only the listed string values.
type oneOf []string

func (v oneOf) Description(context.Context) string {
	return "one of: " + strings.Join(v, ", ")
}

func (v oneOf) MarkdownDescription(ctx context.Context) string { return v.Description(ctx) }

func (v oneOf) ValidateString(_ context.Context, req validator.StringRequest, resp *validator.StringResponse) {
	if req.ConfigValue.IsNull() || req.ConfigValue.IsUnknown() {
		return
	}
	for _, ok := range v {
		if req.ConfigValue.ValueString() == ok {
			return
		}
	}
	resp.Diagnostics.AddAttributeError(req.Path, "Invalid value", fmt.Sprintf("%q is not %s", req.ConfigValue.ValueString(), v.Description(context.Background())))
}
