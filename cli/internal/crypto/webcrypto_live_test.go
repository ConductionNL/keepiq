package crypto

import (
	"encoding/json"
	"os"
	"strings"
	"testing"
)

// TestWebCryptoLiveEnvelope decrypts an envelope produced by the BROWSER's real
// WebCrypto (not Go), proving the CLI recipe matches byte-for-byte beyond the
// RFC 6070 PBKDF2 anchor. Fixture path via DORIATH_LIVE_ENV; skipped otherwise.
func TestWebCryptoLiveEnvelope(t *testing.T) {
	// Defaults to the committed fixture so the cross-implementation parity check
	// runs in CI; DORIATH_LIVE_ENV overrides it with a freshly-captured one.
	path := os.Getenv("DORIATH_LIVE_ENV")
	if path == "" {
		path = "testdata/webcrypto_envelope.json"
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	// The fixture is a JSON string containing the JSON object (double-encoded).
	inner := string(raw)
	if strings.HasPrefix(inner, "\"") {
		if err := json.Unmarshal(raw, &inner); err != nil {
			t.Fatal(err)
		}
	}
	var env struct {
		MasterPw   string `json:"masterPw"`
		BlobB64    string `json:"blobB64"`
		FieldB64   string `json:"fieldB64"`
		Plaintext  string `json:"plaintext"`
		ChunkCount int    `json:"chunkCount"`
	}
	if err := json.Unmarshal([]byte(inner), &env); err != nil {
		t.Fatal(err)
	}

	pem, err := UnwrapPrivateKey(env.BlobB64, env.MasterPw)
	if err != nil {
		t.Fatalf("UnwrapPrivateKey: %v", err)
	}
	key, err := ParsePrivateKey(pem)
	if err != nil {
		t.Fatalf("ParsePrivateKey: %v", err)
	}
	got, err := DecryptField(env.FieldB64, key)
	if err != nil {
		t.Fatalf("DecryptField: %v", err)
	}
	if got != env.Plaintext {
		t.Fatalf("plaintext mismatch:\n got %q\nwant %q", got, env.Plaintext)
	}
	t.Logf("OK: decrypted %d-chunk WebCrypto field (%d bytes) via master-password unwrap", env.ChunkCount, len(got))

	// Wrong password must fail cleanly, not panic.
	if _, err := UnwrapPrivateKey(env.BlobB64, "wrong-password"); err == nil {
		t.Fatal("expected wrong-password unlock to fail")
	}
}
