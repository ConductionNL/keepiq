package keepiq

import (
	"fmt"
	"log/slog"
	"strings"

	"github.com/ConductionNL/keepiq/sdk/go/internal/redact"
)

// secretView is Secret without its methods, so fmt prints its fields.
type secretView Secret

func (s Secret) redacted() secretView {
	v := secretView(s)
	v.Key, v.Login, v.AdditionalFields = redact.Value(v.Key), redact.Value(v.Login), redact.Value(v.AdditionalFields)
	return v
}

// String shows the metadata of the secret; Key, Login and AdditionalFields
// read as [redacted].
func (s Secret) String() string { return fmt.Sprintf("%+v", s.redacted()) }

// GoString is the %#v form of the secret with its values redacted.
func (s Secret) GoString() string {
	return strings.Replace(fmt.Sprintf("%#v", s.redacted()), "keepiq.secretView{", "keepiq.Secret{", 1)
}

// Format prints the redacted form for every verb, so no verb prints a value.
func (s Secret) Format(f fmt.State, verb rune) { redact.Format(f, verb, s) }

// LogValue gives log/slog the redacted form, so a JSON handler does not
// marshal the values. json.Marshal itself still returns them.
func (s Secret) LogValue() slog.Value { return slog.AnyValue(s.redacted()) }

// String shows the instance address and the application id only.
func (c *Client) String() string {
	return fmt.Sprintf("{BaseURL:%s ApplicationID:%s}", c.baseURL, c.applicationID)
}

// GoString is the %#v form of the client without its key or token.
func (c *Client) GoString() string {
	return fmt.Sprintf("&keepiq.Client{BaseURL:%q, ApplicationID:%q}", c.baseURL, c.applicationID)
}

// Format prints the String form for every verb, and GoString for %#v.
func (c *Client) Format(f fmt.State, verb rune) { redact.Format(f, verb, c) }
