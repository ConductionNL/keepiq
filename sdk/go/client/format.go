package client

import (
	"fmt"
	"log/slog"
	"strings"

	"github.com/ConductionNL/keepiq/sdk/go/internal/redact"
)

// String shows the instance address only, never the app password.
func (c Client) String() string { return fmt.Sprintf("{BaseURL:%s}", c.BaseURL) }

// GoString is the %#v form of the client without its credentials.
func (c Client) GoString() string { return fmt.Sprintf("client.Client{BaseURL:%q}", c.BaseURL) }

// Format prints the String form for every verb, and GoString for %#v.
func (c Client) Format(f fmt.State, verb rune) { redact.Format(f, verb, c) }

// suiteView is Suite without its methods, so fmt prints its fields.
type suiteView Suite

func (s Suite) redacted() suiteView {
	v := suiteView(s)
	v.PrivateKey = redact.Value(v.PrivateKey)
	return v
}

// String shows the suite with PrivateKey read as [redacted].
func (s Suite) String() string { return fmt.Sprintf("%+v", s.redacted()) }

// GoString is the %#v form of the suite with PrivateKey redacted.
func (s Suite) GoString() string {
	return strings.Replace(fmt.Sprintf("%#v", s.redacted()), "client.suiteView{", "client.Suite{", 1)
}

// Format prints the redacted form for every verb.
func (s Suite) Format(f fmt.State, verb rune) { redact.Format(f, verb, s) }

// LogValue gives log/slog the redacted form, so a JSON handler does not
// marshal PrivateKey. json.Marshal itself still returns it.
func (s Suite) LogValue() slog.Value { return slog.AnyValue(s.redacted()) }
