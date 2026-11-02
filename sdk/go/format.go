package keepiq

import (
	"fmt"
	"io"
	"strings"
)

// redactedValue stands in for a credential value in printed output.
const redactedValue = "[redacted]"

// redact is redactedValue for a non-empty value and "" for an empty one.
func redact(v string) string {
	if v == "" {
		return ""
	}
	return redactedValue
}

// secretView is Secret without its methods, so fmt prints its fields.
type secretView Secret

func (s Secret) redacted() secretView {
	v := secretView(s)
	v.Key, v.Login, v.AdditionalFields = redact(v.Key), redact(v.Login), redact(v.AdditionalFields)
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
func (s Secret) Format(f fmt.State, verb rune) { writeFormatted(f, verb, s) }

// String shows the instance address and the application id only.
func (c *Client) String() string {
	return fmt.Sprintf("{BaseURL:%s ApplicationID:%s}", c.baseURL, c.applicationID)
}

// GoString is the %#v form of the client without its key or token.
func (c *Client) GoString() string {
	return fmt.Sprintf("&keepiq.Client{BaseURL:%q, ApplicationID:%q}", c.baseURL, c.applicationID)
}

// Format prints the String form for every verb, and GoString for %#v.
func (c *Client) Format(f fmt.State, verb rune) { writeFormatted(f, verb, c) }

type goStringer interface {
	fmt.Stringer
	fmt.GoStringer
}

func writeFormatted(f fmt.State, verb rune, v goStringer) {
	if verb == 'v' && f.Flag('#') {
		_, _ = io.WriteString(f, v.GoString())
		return
	}
	_, _ = io.WriteString(f, v.String())
}
