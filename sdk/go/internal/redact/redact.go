// Package redact holds the helpers the SDK packages share to keep credential
// values out of printed output.
package redact

import (
	"fmt"
	"io"
)

// Text stands in for a credential value in printed output.
const Text = "[redacted]"

// Value is Text for a non-empty value and "" for an empty one.
func Value(v string) string {
	if v == "" {
		return ""
	}
	return Text
}

// Printable is a type with a redacted String and GoString form.
type Printable interface {
	fmt.Stringer
	fmt.GoStringer
}

// Format writes v.GoString() for %#v and v.String() for every other verb, so
// no verb or flag reaches the fields behind them.
func Format(f fmt.State, verb rune, v Printable) {
	if verb == 'v' && f.Flag('#') {
		_, _ = io.WriteString(f, v.GoString())
		return
	}
	_, _ = io.WriteString(f, v.String())
}
