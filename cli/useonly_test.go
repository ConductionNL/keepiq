package main

import (
	"strings"
	"testing"

	"github.com/ConductionNL/keepiq/sdk/go/client"
)

// A use-only copy is never printed or copied by the CLI
// (sharing-use-only-and-expiring-shares task 4.3).
func TestUseOnlyCopyRefusesShowGetAndCopyOfTheValue(t *testing.T) {
	s := &client.Secret{ID: "copy", Name: "Supplier portal", UseOnly: true}

	for _, field := range []string{"", "key", "additionalFields"} {
		err := refuseUseOnly(s, field)
		if err == nil || err.Error() != useOnlyRefusal {
			t.Fatalf("field %q: want the use-only refusal, got %v", field, err)
		}
	}

	for _, field := range []string{"name", "url", "login", "id"} {
		if err := refuseUseOnly(s, field); err != nil {
			t.Fatalf("field %q is plain metadata and must stay readable: %v", field, err)
		}
	}
}

func TestNormalSecretIsNotRefused(t *testing.T) {
	if err := refuseUseOnly(&client.Secret{ID: "mine"}, "key"); err != nil {
		t.Fatalf("a normal secret must not be refused: %v", err)
	}
}

func TestListMarksAUseOnlyCopy(t *testing.T) {
	line := listLine(client.Secret{ID: "copy", Name: "Supplier portal", UseOnly: true})
	if !strings.HasSuffix(line, "Supplier portal [use only]") {
		t.Fatalf("want a use-only marker, got %q", line)
	}
	if strings.Contains(listLine(client.Secret{ID: "mine", Name: "Mine"}), "use only") {
		t.Fatal("a normal secret must carry no marker")
	}
}
