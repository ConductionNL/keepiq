package keepiq

import (
	"encoding/json"
	"fmt"
	"strings"
	"testing"
)

var printVerbs = []string{"%v", "%+v", "%#v", "%s", "%q", "%x", "%d", "%T %v"}

func sampleSecret() Secret {
	return Secret{
		ID: "sec-1", Name: "db", FolderPath: "ci", ETag: `"e1"`,
		Key: "value-k3y", Login: "login-n4me", AdditionalFields: `{"pin":"pin-9137"}`,
		Lease: &Lease{ID: "l-1", Expires: "soon"},
	}
}

func assertHidden(t *testing.T, text string, values ...string) {
	t.Helper()
	for _, v := range values {
		if strings.Contains(text, v) {
			t.Fatalf("printed output %q shows %q", text, v)
		}
	}
}

// No fmt verb prints a secret's values, whether it is a value, a pointer or
// inside a slice or a struct.
func TestSecretPrintsWithoutValues(t *testing.T) {
	s := sampleSecret()
	holder := struct{ S Secret }{s}
	for _, verb := range printVerbs {
		for _, arg := range []any{s, &s, []Secret{s}, holder} {
			assertHidden(t, fmt.Sprintf(verb, arg), "value-k3y", "login-n4me", "pin-9137")
			assertHidden(t, fmt.Sprintf(verb, arg), "76616c75652d6b3379")
		}
	}
}

// The printed form keeps the metadata and says which values are set.
func TestSecretPrintKeepsTheMetadata(t *testing.T) {
	s := sampleSecret()
	s.Login = ""
	text := fmt.Sprintf("%v", s)
	for _, part := range []string{"ID:sec-1", "Name:db", "FolderPath:ci", `ETag:"e1"`, "Key:[redacted]", "Login: ", "AdditionalFields:[redacted]"} {
		if !strings.Contains(text, part) {
			t.Fatalf("%q lacks %q", text, part)
		}
	}
	gs := fmt.Sprintf("%#v", s)
	if !strings.HasPrefix(gs, `keepiq.Secret{ID:"sec-1", Name:"db"`) || !strings.Contains(gs, `Key:"[redacted]"`) {
		t.Fatalf("GoString %q", gs)
	}
}

// Redacting the printed form leaves the fields and their JSON as they are.
func TestSecretValuesStayReadable(t *testing.T) {
	s := sampleSecret()
	_ = fmt.Sprint(s)
	if s.Key != "value-k3y" || s.Login != "login-n4me" || s.AdditionalFields != `{"pin":"pin-9137"}` {
		t.Fatalf("fields changed: %q %q %q", s.Key, s.Login, s.AdditionalFields)
	}
	raw, err := json.Marshal(s)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(raw), `"Key":"value-k3y"`) {
		t.Fatalf("json %s", raw)
	}
}

// A client that holds a token prints its address and application id only.
func TestClientPrintsWithoutKeyOrToken(t *testing.T) {
	f := loadFixture(t)
	st := startStub(t)
	c := newClient(t, st, f)
	if _, err := c.GetByID("sec-cli-fixture"); err != nil {
		t.Fatal(err)
	}
	if c.token == "" {
		t.Fatal("no token cached")
	}
	d := c.key.D.String()
	for _, verb := range printVerbs {
		for _, arg := range []any{c, []*Client{c}, struct{ C *Client }{c}} {
			text := fmt.Sprintf(verb, arg)
			assertHidden(t, text, c.token, d, c.key.Primes[0].String(), "PRIVATE KEY")
		}
	}
	want := fmt.Sprintf("{BaseURL:%s ApplicationID:billing}", c.baseURL)
	if got := fmt.Sprintf("%+v", c); got != want {
		t.Fatalf("got %q, want %q", got, want)
	}
	if got := fmt.Sprintf("%#v", c); got != fmt.Sprintf("&keepiq.Client{BaseURL:%q, ApplicationID:\"billing\"}", c.baseURL) {
		t.Fatalf("GoString %q", got)
	}
}
