package client

import (
	"bytes"
	"encoding/json"
	"fmt"
	"log/slog"
	"strings"
	"testing"
)

var printVerbs = []string{"%v", "%+v", "%#v", "%s", "%q", "%x", "%d", "%T %v"}

func assertHidden(t *testing.T, text string, values ...string) {
	t.Helper()
	for _, v := range values {
		if strings.Contains(text, v) {
			t.Fatalf("printed output %q shows %q", text, v)
		}
	}
}

// No fmt verb except %p prints the app password, whether the client is a
// pointer, a value or inside a slice or a struct.
func TestClientPrintsWithoutAppPassword(t *testing.T) {
	c := New("https://vault.example")
	c.WithAppPassword("ruben", "app-p4ss")
	for _, verb := range printVerbs {
		for _, arg := range []any{c, *c, []*Client{c}, struct{ C *Client }{c}} {
			assertHidden(t, fmt.Sprintf(verb, arg), "app-p4ss", "6170702d70347373")
		}
	}
	if got := fmt.Sprintf("%+v", c); got != "{BaseURL:https://vault.example}" {
		t.Fatalf("got %q", got)
	}
	if got := fmt.Sprintf("%#v", c); got != `client.Client{BaseURL:"https://vault.example"}` {
		t.Fatalf("GoString %q", got)
	}
	if c.appPass != "app-p4ss" {
		t.Fatalf("app password changed: %q", c.appPass)
	}
}

func sampleSuite() Suite {
	return Suite{ID: "su-1", Certificate: "cert-pem", PrivateKey: "wr4pped-key", Status: "active"}
}

// No fmt verb except %p prints a suite's private key, and neither slog
// handler does when the suite is the attribute value itself.
func TestSuitePrintsWithoutPrivateKey(t *testing.T) {
	s := sampleSuite()
	for _, verb := range printVerbs {
		for _, arg := range []any{s, &s, []Suite{s}, struct{ S Suite }{s}} {
			assertHidden(t, fmt.Sprintf(verb, arg), "wr4pped-key", "7772347070")
		}
	}
	for _, arg := range []any{s, &s} {
		var buf bytes.Buffer
		slog.New(slog.NewJSONHandler(&buf, nil)).Info("unlock", "suite", arg)
		slog.New(slog.NewTextHandler(&buf, nil)).Info("unlock", "suite", arg)
		assertHidden(t, buf.String(), "wr4pped-key")
		if !strings.Contains(buf.String(), `"privateKey":"[redacted]"`) {
			t.Fatalf("log %q lacks the redacted key", buf.String())
		}
	}
	text := fmt.Sprintf("%v", s)
	for _, part := range []string{"ID:su-1", "Certificate:cert-pem", "PrivateKey:[redacted]", "Status:active"} {
		if !strings.Contains(text, part) {
			t.Fatalf("%q lacks %q", text, part)
		}
	}
	if gs := fmt.Sprintf("%#v", s); !strings.HasPrefix(gs, `client.Suite{ID:"su-1"`) {
		t.Fatalf("GoString %q", gs)
	}
	s.PrivateKey = ""
	if text := fmt.Sprintf("%v", s); !strings.Contains(text, "PrivateKey: ") {
		t.Fatalf("an empty key should print empty: %q", text)
	}
}

// The CLI still reads the private key from the decoded suite and its JSON.
func TestSuiteValuesStayReadable(t *testing.T) {
	s := sampleSuite()
	_ = fmt.Sprint(s)
	raw, err := json.Marshal(s)
	if err != nil {
		t.Fatal(err)
	}
	if s.PrivateKey != "wr4pped-key" || !strings.Contains(string(raw), `"privateKey":"wr4pped-key"`) {
		t.Fatalf("key changed: %q %s", s.PrivateKey, raw)
	}
}
