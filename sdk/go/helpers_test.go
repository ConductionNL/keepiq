package keepiq

import (
	"crypto/rand"
	"crypto/rsa"
	"crypto/x509"
	"encoding/json"
	"encoding/pem"
	"io"
	"os"
	"testing"

	kcrypto "github.com/ConductionNL/keepiq/sdk/go/crypto"
)

func randReader() io.Reader { return rand.Reader }

func pemPKCS1(k *rsa.PrivateKey) string {
	return string(pem.EncodeToMemory(&pem.Block{Type: "RSA PRIVATE KEY", Bytes: x509.MarshalPKCS1PrivateKey(k)}))
}

// vector is the shape of sdk/testdata/encrypted_by_<language>.json.
type vector struct {
	Comment    string            `json:"_comment"`
	Plaintext  map[string]string `json:"plaintext"`
	Ciphertext map[string]string `json:"ciphertext"`
}

func writeVector(t *testing.T, path, producer string, pub *rsa.PublicKey) {
	t.Helper()
	raw, err := os.ReadFile("../testdata/vector_plaintexts.json")
	if err != nil {
		t.Fatal(err)
	}
	var in struct {
		Plaintext map[string]string `json:"plaintext"`
	}
	if err := json.Unmarshal(raw, &in); err != nil {
		t.Fatal(err)
	}
	v := vector{
		Comment:    "TEST ONLY. Values encrypted by " + producer + " to the machine_envelope.json test key. Decrypted by every library and by DecryptService in tests/Unit/Service/SdkEncryptedVectorsTest.php.",
		Plaintext:  in.Plaintext,
		Ciphertext: map[string]string{},
	}
	for name, value := range in.Plaintext {
		ct, err := kcrypto.EncryptField(value, pub)
		if err != nil {
			t.Fatal(err)
		}
		v.Ciphertext[name] = ct
	}
	out, _ := json.MarshalIndent(v, "", "    ")
	if err := os.WriteFile(path, append(out, '\n'), 0o644); err != nil {
		t.Fatal(err)
	}
}

func checkVector(t *testing.T, path string, key *rsa.PrivateKey) {
	t.Helper()
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("%s: %v (write it with KEEPIQ_WRITE_VECTORS=1)", path, err)
	}
	var v vector
	if err := json.Unmarshal(raw, &v); err != nil {
		t.Fatal(err)
	}
	if len(v.Plaintext) == 0 || len(v.Plaintext) != len(v.Ciphertext) {
		t.Fatalf("%s: %d plaintexts, %d ciphertexts", path, len(v.Plaintext), len(v.Ciphertext))
	}
	for name, want := range v.Plaintext {
		got, err := kcrypto.DecryptField(v.Ciphertext[name], key)
		if err != nil {
			t.Fatalf("%s %s: %v", path, name, err)
		}
		if got != want {
			t.Fatalf("%s %s: got %q", path, name, got)
		}
	}
}
