// Package crypto reimplements Doriath's browser crypto recipe byte-for-byte
// (doriath-cli §2), so the CLI decrypts client-side exactly as the Vue app and
// the openconnector machine recipe do — no plaintext key material ever reaches
// the server (ADR-003).
//
// Two formats are handled:
//
//   - The EncryptionSuite private-key blob (human unlock): base64 of
//     [4-byte version][16-byte salt][12-byte IV][ciphertext+16-byte GCM tag].
//     The unlock key is PBKDF2-HMAC-SHA256(masterPassword, salt, 600000) → the
//     32-byte AES-256-GCM key that decrypts the blob to the PKCS8 private-key PEM.
//   - Secret fields (rsa-oaep-sha256-chunked-v1): base64 of
//     [4-byte chunk count big-endian][512-byte RSA-OAEP-SHA256 blocks…], each
//     block decrypted with the suite's RSA-4096 private key and concatenated.
//
// Stdlib only — PBKDF2 is implemented in-house over crypto/hmac so the CLI has
// no external dependency and builds to a single static, cross-compiled binary.
package crypto

import (
	"crypto"
	"crypto/aes"
	"crypto/cipher"
	"crypto/hmac"
	"crypto/rsa"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/binary"
	"encoding/pem"
	"errors"
	"fmt"
)

const (
	pbkdf2Iterations = 600000
	envelopeVersion  = 1
	saltLen          = 16
	ivLen            = 12
	rsaBlockSize     = 512
)

// UnlockedSuite is the in-memory result of a human unlock: the suite's RSA
// private key (used to decrypt secret fields client-side) and its certificate.
// The private key never leaves the process.
type UnlockedSuite struct {
	Key         *rsa.PrivateKey
	Certificate string
}

// pbkdf2SHA256 derives keyLen bytes from password+salt using PBKDF2-HMAC-SHA256.
// Implemented in-house (RFC 8018) to keep the CLI stdlib-only.
func pbkdf2SHA256(password, salt []byte, iterations, keyLen int) []byte {
	prf := func(data []byte) []byte {
		mac := hmac.New(sha256.New, password)
		mac.Write(data)
		return mac.Sum(nil)
	}
	hLen := sha256.Size
	blocks := (keyLen + hLen - 1) / hLen
	out := make([]byte, 0, blocks*hLen)
	for block := 1; block <= blocks; block++ {
		var idx [4]byte
		binary.BigEndian.PutUint32(idx[:], uint32(block))
		u := prf(append(append([]byte{}, salt...), idx[:]...))
		t := make([]byte, len(u))
		copy(t, u)
		for i := 1; i < iterations; i++ {
			u = prf(u)
			for j := range t {
				t[j] ^= u[j]
			}
		}
		out = append(out, t...)
	}
	return out[:keyLen]
}

// DeriveUnlockKey reproduces the browser's deriveAesKey/deriveUnlockKeyRaw: the
// raw 32-byte AES-256 unlock key from the master password and the private-key
// envelope's own salt.
func DeriveUnlockKey(masterPassword string, salt []byte) []byte {
	return pbkdf2SHA256([]byte(masterPassword), salt, pbkdf2Iterations, 32)
}

// UnwrapPrivateKey decrypts the EncryptionSuite private-key blob with the master
// password, returning the PKCS8 private-key PEM (identical to the browser's
// decryptPrivateKey).
func UnwrapPrivateKey(blobBase64, masterPassword string) (string, error) {
	raw, err := base64.StdEncoding.DecodeString(blobBase64)
	if err != nil {
		return "", fmt.Errorf("decode private-key blob: %w", err)
	}
	if len(raw) < 4+saltLen+ivLen+16 {
		return "", errors.New("private-key blob too short")
	}
	if v := binary.BigEndian.Uint32(raw[0:4]); v != envelopeVersion {
		return "", fmt.Errorf("unsupported envelope version %d", v)
	}
	salt := raw[4 : 4+saltLen]
	iv := raw[4+saltLen : 4+saltLen+ivLen]
	ct := raw[4+saltLen+ivLen:]

	key := DeriveUnlockKey(masterPassword, salt)
	block, err := aes.NewCipher(key)
	if err != nil {
		return "", err
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return "", err
	}
	pt, err := gcm.Open(nil, iv, ct, nil)
	if err != nil {
		return "", fmt.Errorf("unlock failed — wrong master password or corrupt blob: %w", err)
	}
	return string(pt), nil
}

// ParsePrivateKey parses a PKCS8 RSA private-key PEM.
func ParsePrivateKey(pemStr string) (*rsa.PrivateKey, error) {
	blockPem, _ := pem.Decode([]byte(pemStr))
	if blockPem == nil {
		return nil, errors.New("no PEM block in private key")
	}
	parsed, err := x509.ParsePKCS8PrivateKey(blockPem.Bytes)
	if err != nil {
		return nil, fmt.Errorf("parse PKCS8: %w", err)
	}
	key, ok := parsed.(*rsa.PrivateKey)
	if !ok {
		return nil, errors.New("private key is not RSA")
	}
	return key, nil
}

// DecryptField reverses the browser's rsaEncrypt: base64 of
// [4-byte chunk count][512-byte RSA-OAEP-SHA256 blocks…] → plaintext.
func DecryptField(ciphertextBase64 string, key *rsa.PrivateKey) (string, error) {
	raw, err := base64.StdEncoding.DecodeString(ciphertextBase64)
	if err != nil {
		return "", fmt.Errorf("decode field: %w", err)
	}
	if len(raw) < 4 {
		return "", errors.New("field ciphertext too short")
	}
	chunks := binary.BigEndian.Uint32(raw[0:4])
	if int(chunks)*rsaBlockSize != len(raw)-4 {
		return "", fmt.Errorf("field length %d inconsistent with chunk count %d", len(raw), chunks)
	}
	var out []byte
	for i := 0; i < int(chunks); i++ {
		start := 4 + i*rsaBlockSize
		block := raw[start : start+rsaBlockSize]
		pt, err := rsa.DecryptOAEP(sha256.New(), nil, key, block, nil)
		if err != nil {
			return "", fmt.Errorf("RSA-OAEP decrypt chunk %d: %w", i, err)
		}
		out = append(out, pt...)
	}
	return string(out), nil
}

// CertificateFingerprint returns the sha256:-prefixed fingerprint of a PEM
// certificate (for the fast key/envelope mismatch pre-check, §2.2).
func CertificateFingerprint(certPem string) (string, error) {
	blockPem, _ := pem.Decode([]byte(certPem))
	if blockPem == nil {
		return "", errors.New("no PEM block in certificate")
	}
	sum := sha256.Sum256(blockPem.Bytes)
	return "sha256:" + fmt.Sprintf("%x", sum[:]), nil
}

// PublicKeyMatchesPrivate verifies the private key's public half matches the
// certificate's public key — a fast fail on a key/envelope mismatch (§2.2).
func PublicKeyMatchesPrivate(certPem string, key *rsa.PrivateKey) error {
	blockPem, _ := pem.Decode([]byte(certPem))
	if blockPem == nil {
		return errors.New("no PEM block in certificate")
	}
	cert, err := x509.ParseCertificate(blockPem.Bytes)
	if err != nil {
		return fmt.Errorf("parse certificate: %w", err)
	}
	certPub, ok := cert.PublicKey.(*rsa.PublicKey)
	if !ok {
		return errors.New("certificate public key is not RSA")
	}
	if certPub.N.Cmp(key.N) != 0 || certPub.E != key.E {
		return errors.New("key/envelope mismatch: the private key does not match the suite certificate")
	}
	return nil
}

// SignRS256 produces an RS256 (RSASSA-PKCS1-v1_5 over SHA-256) signature over
// signingInput, base64url-encoded — the primitive behind the CI-mode RFC 7523
// JWT bearer assertion.
func SignRS256(signingInput string, key *rsa.PrivateKey) (string, error) {
	digest := sha256.Sum256([]byte(signingInput))
	sig, err := rsa.SignPKCS1v15(nil, key, crypto.SHA256, digest[:])
	if err != nil {
		return "", err
	}
	return base64.RawURLEncoding.EncodeToString(sig), nil
}
