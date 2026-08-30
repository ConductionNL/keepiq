package crypto

import (
	stdcrypto "crypto"
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"crypto/rsa"
	"crypto/sha256"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/binary"
	"encoding/hex"
	"encoding/pem"
	"math/big"
	"testing"
	"time"
)

// TestPBKDF2RFC6070 anchors the in-house PBKDF2-HMAC-SHA256 to the RFC 6070
// reference vectors — the same algorithm WebCrypto uses, so matching the vector
// proves byte-for-byte parity with the browser's deriveAesKey.
func TestPBKDF2RFC6070(t *testing.T) {
	cases := []struct {
		password, salt string
		iter, keyLen   int
		wantHex        string
	}{
		{"password", "salt", 1, 32, "120fb6cffcf8b32c43e7225256c4f837a86548c92ccc35480805987cb70be17b"},
		{"password", "salt", 2, 32, "ae4d0c95af6b46d32d0adff928f06dd02a303f8ef3c251dfd6e2d85a95474c43"},
		{"password", "salt", 4096, 32, "c5e478d59288c841aa530db6845c4c8d962893a001ce4e11a4963873aa98134a"},
	}
	for _, c := range cases {
		got := pbkdf2SHA256([]byte(c.password), []byte(c.salt), c.iter, c.keyLen)
		if hex.EncodeToString(got) != c.wantHex {
			t.Fatalf("PBKDF2(%q,%q,%d): got %s want %s", c.password, c.salt, c.iter, hex.EncodeToString(got), c.wantHex)
		}
	}
}

// TestUnwrapPrivateKeyRoundTrip builds a private-key blob in the exact browser
// envelope format (version+salt+iv+GCM) and confirms UnwrapPrivateKey recovers
// the PEM — and rejects a wrong password.
func TestUnwrapPrivateKeyRoundTrip(t *testing.T) {
	pemStr := "-----BEGIN PRIVATE KEY-----\nMOCK\n-----END PRIVATE KEY-----"
	password := "TfMaster2026!vault"
	salt := make([]byte, saltLen)
	iv := make([]byte, ivLen)
	rand.Read(salt)
	rand.Read(iv)

	key := DeriveUnlockKey(password, salt)
	block, _ := aes.NewCipher(key)
	gcm, _ := cipher.NewGCM(block)
	ct := gcm.Seal(nil, iv, []byte(pemStr), nil)

	buf := make([]byte, 4+saltLen+ivLen+len(ct))
	binary.BigEndian.PutUint32(buf[0:4], envelopeVersion)
	copy(buf[4:], salt)
	copy(buf[4+saltLen:], iv)
	copy(buf[4+saltLen+ivLen:], ct)
	blob := base64.StdEncoding.EncodeToString(buf)

	got, err := UnwrapPrivateKey(blob, password)
	if err != nil {
		t.Fatalf("unwrap: %v", err)
	}
	if got != pemStr {
		t.Fatalf("unwrap round-trip: got %q", got)
	}
	if _, err := UnwrapPrivateKey(blob, "wrong-password"); err == nil {
		t.Fatal("expected wrong-password unwrap to fail")
	}
}

// TestDecryptFieldRoundTrip encrypts a value in the browser's chunked
// RSA-OAEP-SHA256 format and confirms DecryptField recovers it, incl. a payload
// spanning multiple 446-byte chunks.
func TestDecryptFieldRoundTrip(t *testing.T) {
	key, err := rsa.GenerateKey(rand.Reader, 4096)
	if err != nil {
		t.Fatal(err)
	}
	for _, plaintext := range []string{"s3cr3t", string(make([]byte, 900))} {
		ct := encryptFieldBrowserFormat(t, []byte(plaintext), &key.PublicKey)
		got, err := DecryptField(ct, key)
		if err != nil {
			t.Fatalf("decrypt: %v", err)
		}
		if got != plaintext {
			t.Fatalf("field round-trip mismatch (len %d)", len(plaintext))
		}
	}
}

// TestPublicKeyMatchesPrivate confirms the fingerprint pre-check accepts a
// matching cert and rejects a mismatched one.
func TestPublicKeyMatchesPrivate(t *testing.T) {
	key, _ := rsa.GenerateKey(rand.Reader, 2048)
	certPem := selfSignedCert(t, key)
	if err := PublicKeyMatchesPrivate(certPem, key); err != nil {
		t.Fatalf("matching key rejected: %v", err)
	}
	other, _ := rsa.GenerateKey(rand.Reader, 2048)
	if err := PublicKeyMatchesPrivate(certPem, other); err == nil {
		t.Fatal("expected mismatched key to be rejected")
	}
}

// TestSignRS256 verifies the assertion signature against the public key.
func TestSignRS256(t *testing.T) {
	key, _ := rsa.GenerateKey(rand.Reader, 2048)
	input := "eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJhcHAtMSJ9"
	sig, err := SignRS256(input, key)
	if err != nil {
		t.Fatal(err)
	}
	raw, _ := base64.RawURLEncoding.DecodeString(sig)
	digest := sha256.Sum256([]byte(input))
	if err := rsa.VerifyPKCS1v15(&key.PublicKey, stdcrypto.SHA256, digest[:], raw); err != nil {
		t.Fatalf("signature does not verify: %v", err)
	}
}

// --- test helpers ---

func encryptFieldBrowserFormat(t *testing.T, plaintext []byte, pub *rsa.PublicKey) string {
	t.Helper()
	const chunkSize = 446
	var chunks [][]byte
	for i := 0; i < len(plaintext); i += chunkSize {
		end := i + chunkSize
		if end > len(plaintext) {
			end = len(plaintext)
		}
		chunks = append(chunks, plaintext[i:end])
	}
	if len(chunks) == 0 {
		chunks = append(chunks, []byte{})
	}
	out := make([]byte, 4)
	binary.BigEndian.PutUint32(out[0:4], uint32(len(chunks)))
	for _, c := range chunks {
		enc, err := rsa.EncryptOAEP(sha256.New(), rand.Reader, pub, c, nil)
		if err != nil {
			t.Fatal(err)
		}
		out = append(out, enc...)
	}
	return base64.StdEncoding.EncodeToString(out)
}

func selfSignedCert(t *testing.T, key *rsa.PrivateKey) string {
	t.Helper()
	tmpl := &x509.Certificate{
		SerialNumber: big.NewInt(1),
		Subject:      pkix.Name{CommonName: "keepiq-cli-test"},
		NotBefore:    time.Now().Add(-time.Hour),
		NotAfter:     time.Now().Add(time.Hour),
	}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, tmpl, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}))
}
