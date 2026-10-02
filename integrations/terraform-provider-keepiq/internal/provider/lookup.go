package provider

import (
	"errors"
	"fmt"
	"strings"

	"github.com/hashicorp/terraform-plugin-framework/types"

	keepiq "github.com/ConductionNL/keepiq/sdk/go"
)

// lookup reads a secret by id, or by name and optional folder.
func lookup(c Client, id, name, folder types.String) (*keepiq.Secret, error) {
	if !id.IsNull() && id.ValueString() != "" {
		return c.GetByID(id.ValueString())
	}
	if name.IsNull() || name.ValueString() == "" {
		return nil, errors.New("set either id, or name (with an optional folder)")
	}
	return c.GetByNameIfNoneMatch(name.ValueString(), folder.ValueString(), "")
}

// explain turns a library error into a diagnostic detail without any value.
func explain(err error, what string) string {
	var amb *keepiq.AmbiguousNameError
	switch {
	case errors.Is(err, keepiq.ErrNotFound):
		return fmt.Sprintf("%s was not found in the application vault.", what)
	case errors.As(err, &amb):
		parts := make([]string, 0, len(amb.Candidates))
		for _, c := range amb.Candidates {
			f := c.FolderPath
			if f == "" {
				f = "/"
			}
			parts = append(parts, c.ID+" in "+f)
		}
		return fmt.Sprintf("%d secrets are named %s: %s. Set folder or use id.", len(amb.Candidates), what, strings.Join(parts, ", "))
	case errors.Is(err, keepiq.ErrUnauthorized):
		return "Keepiq refused the application token: check application_id and private_key."
	case errors.Is(err, keepiq.ErrKeyMismatch):
		return what + " is encrypted to another certificate than the configured one."
	}
	return err.Error()
}

func strOrNull(s string) types.String {
	if s == "" {
		return types.StringNull()
	}
	return types.StringValue(s)
}
