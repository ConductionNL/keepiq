package keepiq

import (
	"crypto/rsa"
	"encoding/json"
	"errors"
	"os"
	"strings"
	"testing"
	"time"

	kcrypto "github.com/ConductionNL/keepiq/sdk/go/crypto"
	"github.com/ConductionNL/keepiq/sdk/go/keepiqtest"
)

// fixture is sdk/testdata/machine_envelope.json: the envelope the server's
// MachineSecretEnvelopeService::serialize() writes, guarded by
// tests/Unit/Service/MachineEnvelopeCliFixtureTest.php.
type fixture struct {
	PrivateKeyPem  string            `json:"privateKeyPem"`
	CertificatePem string            `json:"certificatePem"`
	Plaintext      map[string]string `json:"plaintext"`
	Envelope       json.RawMessage   `json:"envelope"`
}

func loadFixture(t *testing.T) fixture {
	t.Helper()
	raw, err := os.ReadFile("../testdata/machine_envelope.json")
	if err != nil {
		t.Fatal(err)
	}
	var f fixture
	if err := json.Unmarshal(raw, &f); err != nil {
		t.Fatal(err)
	}
	return f
}

func startStub(t *testing.T) *keepiqtest.Stub {
	t.Helper()
	st, err := keepiqtest.Start("billing")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(st.Close)
	return st
}

func newClient(t *testing.T, st *keepiqtest.Stub, f fixture, opts ...Option) *Client {
	t.Helper()
	c, err := New(st.URL(), "billing", f.PrivateKeyPem, opts...)
	if err != nil {
		t.Fatal(err)
	}
	return c
}

// A read by name decrypts the server's real envelope in this process, carries
// the metadata and the lease, and caches the token across calls.
func TestGetByNameDecryptsTheServerEnvelope(t *testing.T) {
	f := loadFixture(t)
	st := startStub(t)
	st.Leases = true
	c := newClient(t, st, f)

	s, err := c.GetByName("ci-fixture-db-password", "")
	if err != nil {
		t.Fatal(err)
	}
	if s.Key != f.Plaintext["key"] || s.Login != f.Plaintext["login"] || s.AdditionalFields != f.Plaintext["additionalFields"] {
		t.Fatalf("decrypted %+v", s)
	}
	if s.FolderPath != "ci/database" || s.ID != "sec-cli-fixture" {
		t.Fatalf("metadata %+v", s)
	}
	if s.Lease == nil || !strings.HasPrefix(s.Lease.ID, "lease-") || s.Lease.Expires == "" {
		t.Fatalf("lease %+v", s.Lease)
	}
	if _, err := c.GetByID("sec-cli-fixture"); err != nil {
		t.Fatal(err)
	}
	if st.Exchanges != 1 {
		t.Fatalf("token exchanged %d times, want 1 (cached)", st.Exchanges)
	}
}

// A second read of an unchanged secret sends the ETag and reports not modified.
func TestUnchangedReadIsNotModified(t *testing.T) {
	f := loadFixture(t)
	st := startStub(t)
	c := newClient(t, st, f)
	first, err := c.GetByID("sec-cli-fixture")
	if err != nil || first.ETag == "" {
		t.Fatalf("first read: %v %+v", err, first)
	}
	if _, err := c.GetByID("sec-cli-fixture"); !errors.Is(err, ErrNotModified) {
		t.Fatalf("second read: want ErrNotModified, got %v", err)
	}
}

// Two secrets with one name: the error carries both ids and folder paths.
func TestAmbiguousNameListsCandidates(t *testing.T) {
	f := loadFixture(t)
	st := startStub(t)
	if _, err := st.Add("sec-twin", "ci-fixture-db-password", "other", map[string]string{"key": "x"}); err != nil {
		t.Fatal(err)
	}
	c := newClient(t, st, f)

	_, err := c.GetByName("ci-fixture-db-password", "")
	var amb *AmbiguousNameError
	if !errors.As(err, &amb) {
		t.Fatalf("want *AmbiguousNameError, got %v", err)
	}
	got := map[string]string{}
	for _, cand := range amb.Candidates {
		got[cand.ID] = cand.FolderPath
	}
	if got["sec-cli-fixture"] != "ci/database" || got["sec-twin"] != "other" || len(got) != 2 {
		t.Fatalf("candidates %+v", amb.Candidates)
	}
}

func TestNotFoundIsTyped(t *testing.T) {
	f := loadFixture(t)
	st := startStub(t)
	c := newClient(t, st, f)
	if _, err := c.GetByName("nope", ""); !errors.Is(err, ErrNotFound) {
		t.Fatalf("want ErrNotFound, got %v", err)
	}
}

// A key the server does not know is refused at the token exchange.
func TestWrongKeyIsUnauthorized(t *testing.T) {
	st := startStub(t)
	other, _ := rsa.GenerateKey(randReader(), 2048)
	c, err := New(st.URL(), "billing", pemPKCS1(other))
	if err != nil {
		t.Fatal(err)
	}
	if _, err := c.GetByID("sec-cli-fixture"); !errors.Is(err, ErrUnauthorized) {
		t.Fatalf("want ErrUnauthorized, got %v", err)
	}
}

// A token the server stops honouring is replaced once, transparently.
func TestRevokedTokenIsRenewedOnce(t *testing.T) {
	f := loadFixture(t)
	st := startStub(t)
	c := newClient(t, st, f)
	if _, err := c.GetByID("sec-cli-fixture"); err != nil {
		t.Fatal(err)
	}
	st.RevokeNext = true
	if _, err := c.List(time.Time{}); err != nil {
		t.Fatalf("list after revoke: %v", err)
	}
	if st.Exchanges != 2 {
		t.Fatalf("exchanges = %d, want 2", st.Exchanges)
	}
}

// Create and update send only ciphertext for the value fields, and a later
// read returns the new value: the rotation scenario of the spec.
func TestWritesSendOnlyCiphertext(t *testing.T) {
	f := loadFixture(t)
	st := startStub(t)
	c := newClient(t, st, f)

	created, err := c.Create(map[string]string{"name": "stripe-key", "key": "sk_live_first", "login": "billing-bot"})
	if err != nil {
		t.Fatal(err)
	}
	if created.Key != "sk_live_first" || created.Login != "billing-bot" {
		t.Fatalf("created %+v", created)
	}
	if _, err := c.Update(created.ID, map[string]string{"key": "YOUR_TOKEN_HERE"}); err != nil {
		t.Fatal(err)
	}
	for _, b := range st.Bodies {
		for _, plain := range []string{"sk_live_first", "billing-bot", "YOUR_TOKEN_HERE", "PRIVATE KEY"} {
			if strings.Contains(b, plain) {
				t.Fatalf("request body carries %q: %s", plain, b)
			}
		}
	}
	got, err := c.GetByID(created.ID)
	if err != nil {
		t.Fatal(err)
	}
	if got.Key != "YOUR_TOKEN_HERE" || got.Login != "billing-bot" {
		t.Fatalf("after update %+v", got)
	}
	if _, err := c.Create(map[string]string{"name": "x", "password": "y"}); err == nil {
		t.Fatal("an unknown field must be refused, not sent")
	}
}

func TestListFiltersOnUpdatedSince(t *testing.T) {
	f := loadFixture(t)
	st := startStub(t)
	if _, err := st.Add("sec-new", "n", "", map[string]string{"key": "v"}); err != nil {
		t.Fatal(err)
	}
	c := newClient(t, st, f)
	all, err := c.List(time.Time{})
	if err != nil || len(all) != 2 {
		t.Fatalf("all: %v %d", err, len(all))
	}
	recent, err := c.List(time.Date(2026, 10, 1, 0, 0, 0, 0, time.UTC))
	if err != nil || len(recent) != 1 || recent[0].ID != "sec-new" {
		t.Fatalf("recent: %v %+v", err, recent)
	}
}

// With the certificate configured, an envelope encrypted to another
// certificate is refused before any decryption.
func TestFingerprintMismatchIsRefused(t *testing.T) {
	f := loadFixture(t)
	st := startStub(t)
	st.Envelopes["sec-cli-fixture"]["encryption"].(map[string]any)["certificateFingerprint"] = "sha256:00"
	c := newClient(t, st, f, WithCertificate(f.CertificatePem))
	if _, err := c.GetByID("sec-cli-fixture"); !errors.Is(err, ErrKeyMismatch) {
		t.Fatalf("want ErrKeyMismatch, got %v", err)
	}
	other, _ := rsa.GenerateKey(randReader(), 2048)
	if _, err := New(st.URL(), "billing", pemPKCS1(other), WithCertificate(f.CertificatePem)); err == nil {
		t.Fatal("a certificate that does not belong to the key must be refused")
	}
}

// The Go-encrypted vector in sdk/testdata decrypts here; PHPUnit
// (tests/Unit/Service/SdkEncryptedVectorsTest.php) decrypts the same file
// with DecryptService. KEEPIQ_WRITE_VECTORS=1 rewrites it.
func TestGoEncryptedVector(t *testing.T) {
	f := loadFixture(t)
	key, _ := kcrypto.ParsePrivateKey(f.PrivateKeyPem)
	const path = "../testdata/encrypted_by_go.json"
	if os.Getenv("KEEPIQ_WRITE_VECTORS") == "1" {
		writeVector(t, path, "Go (sdk/go)", &key.PublicKey)
	}
	checkVector(t, path, key)
}

// Every library's encrypted vector decrypts in Go too. A missing file is
// skipped here: the producing library's own test fails on it.
func TestEveryLibraryVector(t *testing.T) {
	f := loadFixture(t)
	key, _ := kcrypto.ParsePrivateKey(f.PrivateKeyPem)
	for _, lang := range []string{"go", "python", "js"} {
		path := "../testdata/encrypted_by_" + lang + ".json"
		if _, err := os.Stat(path); err != nil {
			continue
		}
		checkVector(t, path, key)
	}
}

// A caller-kept ETag answers not modified; an empty one reads again.
func TestGetByNameIfNoneMatch(t *testing.T) {
	f := loadFixture(t)
	st := startStub(t)
	c := newClient(t, st, f)
	first, err := c.GetByNameIfNoneMatch("ci-fixture-db-password", "", "")
	if err != nil || first.ETag == "" {
		t.Fatalf("first: %v %+v", err, first)
	}
	if _, err := c.GetByNameIfNoneMatch("ci-fixture-db-password", "", first.ETag); !errors.Is(err, ErrNotModified) {
		t.Fatalf("want ErrNotModified, got %v", err)
	}
	if again, err := c.GetByNameIfNoneMatch("ci-fixture-db-password", "", ""); err != nil || again.Key != f.Plaintext["key"] {
		t.Fatalf("unconditional read: %v", err)
	}
}

// Leases: advertised in discovery and attached to reads. There is no renew
// call: fetching again is the one renewal path (keepiq#753).
func TestLeaseOnRead(t *testing.T) {
	f := loadFixture(t)
	st := startStub(t)
	st.Leases = true
	c := newClient(t, st, f)
	if ok, err := c.LeaseSupported(); err != nil || !ok {
		t.Fatalf("LeaseSupported = %v, %v", ok, err)
	}
	s, err := c.GetByID("sec-cli-fixture")
	if err != nil || s.Lease == nil || s.Lease.ExpiresAt().IsZero() {
		t.Fatalf("lease on read: %v %+v", err, s)
	}
}
