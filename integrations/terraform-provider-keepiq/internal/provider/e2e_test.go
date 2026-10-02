package provider

import (
	"archive/zip"
	"bytes"
	"encoding/json"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"testing"

	"github.com/ConductionNL/keepiq/sdk/go/keepiqtest"
)

// TestTerraformEndToEnd drives the real Terraform CLI (1.11 or later, from
// KEEPIQ_TF_BIN) against this provider, built here and loaded through
// dev_overrides, and a stub Keepiq. It checks the acceptance criteria:
// values reach Keepiq and another resource, and no plan or state file holds
// them; value_wo_version is the only write trigger; destroy warns and leaves
// the secret; import adopts a secret.
func TestTerraformEndToEnd(t *testing.T) {
	tf := os.Getenv("KEEPIQ_TF_BIN")
	if tf == "" {
		t.Skip("set KEEPIQ_TF_BIN to a terraform 1.11+ binary to run the end-to-end test")
	}
	stub, err := keepiqtest.Start("infra")
	if err != nil {
		t.Fatal(err)
	}
	defer stub.Close()
	const source = "db-password-from-keepiq-77"
	if _, err := stub.Add("sec-db", "db-password", "", map[string]string{"key": source}); err != nil {
		t.Fatal(err)
	}

	work := t.TempDir()
	bin := filepath.Join(work, "bin")
	_, self, _, _ := runtime.Caller(0)
	module := filepath.Join(filepath.Dir(self), "..", "..")
	build := exec.Command("go", "build", "-buildvcs=false", "-o", filepath.Join(bin, "terraform-provider-keepiq"), ".")
	build.Dir = module
	if out, err := build.CombinedOutput(); err != nil {
		t.Fatalf("build provider: %v\n%s", err, out)
	}
	rc := filepath.Join(work, "terraformrc")
	_ = os.WriteFile(rc, []byte(`provider_installation {
  dev_overrides { "conductionnl/keepiq" = "`+bin+`" }
  direct {}
}
`), 0o644)
	dir := filepath.Join(work, "config")
	_ = os.MkdirAll(dir, 0o755)
	env := append(os.Environ(), "TF_CLI_CONFIG_FILE="+rc, "TF_IN_AUTOMATION=1", "CHECKPOINT_DISABLE=1",
		"KEEPIQ_URL="+stub.URL(), "KEEPIQ_APP_ID=infra", "KEEPIQ_APP_KEY="+stub.Fixture.PrivateKeyPem)
	run := func(args ...string) string {
		t.Helper()
		cmd := exec.Command(tf, append([]string{args[0], "-no-color"}, args[1:]...)...)
		cmd.Dir, cmd.Env = dir, env
		out, err := cmd.CombinedOutput()
		if err != nil {
			t.Fatalf("terraform %s: %v\n%s", strings.Join(args, " "), err, out)
		}
		return string(out)
	}
	write := func(value string, version int) {
		cfg := `terraform {
  required_providers {
    keepiq = { source = "conductionnl/keepiq" }
  }
}
provider "keepiq" {}

ephemeral "keepiq_secret" "db" {
  name = "db-password"
}

resource "keepiq_secret" "api" {
  name             = "api-token"
  value_wo         = "` + value + `"
  value_wo_version = ` + itoa(version) + `
}

# The ephemeral value feeds another resource through a write-only argument.
resource "keepiq_secret" "copy" {
  name             = "db-password-copy"
  value_wo         = ephemeral.keepiq_secret.db.value
  value_wo_version = 1
}

data "keepiq_secret_metadata" "db" {
  name = "db-password"
}

output "db_fingerprint" {
  value = data.keepiq_secret_metadata.db.certificate_fingerprint
}
`
		if err := os.WriteFile(filepath.Join(dir, "main.tf"), []byte(cfg), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	noValueOnDisk := func(values ...string) {
		t.Helper()
		_ = filepath.Walk(dir, func(p string, info os.FileInfo, err error) error {
			if err != nil || info.IsDir() || strings.HasSuffix(p, "main.tf") || strings.Contains(p, ".terraform"+string(os.PathSeparator)+"providers") {
				return nil
			}
			raw, _ := os.ReadFile(p)
			for _, content := range expand(p, raw) {
				for _, v := range values {
					if strings.Contains(content, v) {
						t.Fatalf("%s holds the value %q", p, v)
					}
				}
			}
			return nil
		})
	}
	secretByName := func(name string) (string, string) {
		stub.Mu.Lock()
		var id string
		for k, e := range stub.Envelopes {
			if e["secret"].(map[string]any)["name"] == name {
				id = k
			}
		}
		stub.Mu.Unlock()
		v, _ := stub.Plain(id, "key")
		return id, v
	}

	// Create: plan to a file, apply the file.
	write("first-token-value-1", 1)
	run("plan", "-out=tfplan")
	run("apply", "tfplan")
	if _, v := secretByName("api-token"); v != "first-token-value-1" {
		t.Fatalf("api-token holds %q", v)
	}
	if _, v := secretByName("db-password-copy"); v != source {
		t.Fatalf("the ephemeral value did not reach the other resource: %q", v)
	}
	if !strings.Contains(run("output", "db_fingerprint"), "sha256:") {
		t.Fatal("metadata data source has no fingerprint")
	}
	noValueOnDisk("first-token-value-1", source)

	// A new value without a new version writes nothing.
	write("ignored-token-value-2", 1)
	if out := run("plan"); !strings.Contains(out, "No changes") {
		t.Fatalf("a value change without a version change planned a write:\n%s", out)
	}

	// Bumping the version writes the new value.
	write("second-token-value-3", 2)
	run("apply", "-auto-approve")
	if _, v := secretByName("api-token"); v != "second-token-value-3" {
		t.Fatalf("after the version bump api-token holds %q", v)
	}
	noValueOnDisk("first-token-value-1", "second-token-value-3", "ignored-token-value-2", source)

	// Destroy leaves the secrets in Keepiq and names them in a warning.
	out := strings.Join(strings.Fields(run("destroy", "-auto-approve")), " ") // Terraform wraps warnings
	if !strings.Contains(out, "api-token") || !strings.Contains(out, "still exists in the application vault") {
		t.Fatalf("destroy did not warn by name:\n%s", out)
	}
	if id, v := secretByName("api-token"); id == "" || v != "second-token-value-3" {
		t.Fatal("destroy removed the secret from Keepiq")
	}

	// Import adopts the existing secret.
	apiID, _ := secretByName("api-token")
	write("second-token-value-3", 2)
	run("import", "keepiq_secret.api", apiID)
	state := run("show")
	if !strings.Contains(state, apiID) || strings.Contains(state, "second-token-value-3") {
		t.Fatalf("import state:\n%s", state)
	}
}

// expand returns a file's content, and for a zip (a saved plan) each member's.
// A saved plan embeds a snapshot of the configuration (tfconfig/), which holds
// whatever literal the author typed into main.tf; that is the author's text,
// not something the provider stored, so it is left out. The plan proper
// (tfplan), the prior state and the config of an ephemeral value are checked.
func expand(path string, raw []byte) []string {
	if zr, err := zip.NewReader(bytes.NewReader(raw), int64(len(raw))); err == nil {
		var out []string
		for _, f := range zr.File {
			if strings.HasPrefix(f.Name, "tfconfig/") {
				continue
			}
			if rc, err := f.Open(); err == nil {
				b, _ := io.ReadAll(rc)
				rc.Close()
				out = append(out, string(b))
			}
		}
		return out
	}
	out := []string{string(raw)}
	if strings.HasSuffix(path, ".tfstate") {
		var v any
		if json.Unmarshal(raw, &v) == nil {
			b, _ := json.Marshal(v)
			out = append(out, string(b))
		}
	}
	return out
}

func itoa(i int) string {
	b, _ := json.Marshal(i)
	return string(b)
}
