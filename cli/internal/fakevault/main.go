// Command fakevault is a throwaway Keepiq that serves one user's vault over
// the human session API, so `keepiq login` and `keepiq ssh-agent` can be run
// end to end without a Nextcloud server. TEST ONLY: it is not part of the
// released CLI (the release builds the cli/ package alone).
//
// It generates a fresh RSA-4096 encryption suite, wraps the suite's private
// key with the master password exactly as the browser does (PBKDF2-SHA256,
// AES-256-GCM, envelope version 1), and files the given OpenSSH private key
// as an `ssh_key` secret encrypted to the suite key, next to one login secret
// the agent must ignore. It answers only with ciphertext, checks the
// app-password on every request, and logs each request on standard error.
//
// Used by cli/scripts/ssh-agent-e2e.sh (keepiq#1042).
package main

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"crypto/rsa"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"encoding/pem"
	"flag"
	"log"
	"math/big"
	"net"
	"net/http"
	"os"
	"strconv"
	"time"

	dcrypto "github.com/ConductionNL/keepiq/sdk/go/crypto"
)

const apiBase = "/apps/keepiq/api/v1"

type secret struct {
	ID       string `json:"id"`
	Name     string `json:"name"`
	TypeID   string `json:"typeId"`
	FolderID string `json:"folderId"`
	Key      string `json:"key"`
}

func main() {
	listen := flag.String("listen", "127.0.0.1:0", "address to listen on")
	user := flag.String("user", "alice", "Nextcloud user")
	appPassword := flag.String("app-password", "", "app-password the client must send")
	master := flag.String("master", "", "master password that unlocks the suite")
	sshKeyFile := flag.String("ssh-key", "", "OpenSSH private key (no passphrase) to file as an ssh_key secret")
	name := flag.String("name", "e2e deploy key", "name of the ssh_key secret")
	urlFile := flag.String("url-file", "", "write the base URL here once listening")
	flag.Parse()
	log.SetFlags(0)
	if *appPassword == "" || *master == "" || *sshKeyFile == "" || *urlFile == "" {
		log.Fatal("fakevault: -app-password, -master, -ssh-key and -url-file are required")
	}

	suiteKey, err := rsa.GenerateKey(rand.Reader, 4096)
	if err != nil {
		log.Fatal(err)
	}
	certificate := selfSigned(suiteKey)
	wrapped := wrapPrivateKey(suiteKey, *master)

	sshPEM, err := os.ReadFile(*sshKeyFile)
	if err != nil {
		log.Fatal(err)
	}
	encrypt := func(v string) string {
		ct, err := dcrypto.EncryptField(v, &suiteKey.PublicKey)
		if err != nil {
			log.Fatal(err)
		}
		return ct
	}
	secrets := []secret{
		{ID: "s-ssh", Name: *name, TypeID: "t-ssh", Key: encrypt(string(sshPEM))},
		{ID: "s-login", Name: "Mail", TypeID: "t-login", Key: encrypt("not an ssh key")},
	}

	mux := http.NewServeMux()
	mux.HandleFunc(apiBase+"/suites", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, []map[string]any{{"id": "suite-1", "certificate": certificate, "privateKey": wrapped, "status": "active"}})
	})
	mux.HandleFunc(apiBase+"/secret-types", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, []map[string]any{{"id": "t-login", "name": "login"}, {"id": "t-ssh", "name": "ssh_key"}})
	})
	mux.HandleFunc(apiBase+"/folders", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, []any{})
	})
	mux.HandleFunc(apiBase+"/secrets", func(w http.ResponseWriter, r *http.Request) {
		limit, _ := strconv.Atoi(r.URL.Query().Get("limit"))
		page, _ := strconv.Atoi(r.URL.Query().Get("page"))
		// Nextcloud 35 refuses a limit above 500 (keepiq#786).
		if limit < 1 || limit > 500 || page < 1 {
			http.Error(w, "", http.StatusBadRequest)
			return
		}
		from := (page - 1) * limit
		items := []secret{}
		if from < len(secrets) {
			items = secrets[from:min(from+limit, len(secrets))]
		}
		writeJSON(w, map[string]any{"items": items, "total": len(secrets)})
	})

	handler := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		log.Printf("fakevault: %s %s", r.Method, r.URL.RequestURI())
		u, p, ok := r.BasicAuth()
		if !ok || u != *user || p != *appPassword {
			http.Error(w, `{"message":"unauthorised"}`, http.StatusUnauthorized)
			return
		}
		if r.Method != http.MethodGet {
			http.Error(w, `{"message":"read only"}`, http.StatusMethodNotAllowed)
			return
		}
		mux.ServeHTTP(w, r)
	})

	l, err := net.Listen("tcp", *listen)
	if err != nil {
		log.Fatal(err)
	}
	base := "http://" + l.Addr().String()
	if err := os.WriteFile(*urlFile, []byte(base+"\n"), 0o600); err != nil {
		log.Fatal(err)
	}
	log.Printf("fakevault: serving %s for %s", base, *user)
	log.Fatal(http.Serve(l, handler))
}

// wrapPrivateKey is the browser's private-key envelope:
// [uint32 version 1][16-byte salt][12-byte IV][AES-256-GCM(PKCS#8 PEM)].
func wrapPrivateKey(key *rsa.PrivateKey, master string) string {
	der, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		log.Fatal(err)
	}
	plain := pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: der})
	salt := make([]byte, 16)
	iv := make([]byte, 12)
	_, _ = rand.Read(salt)
	_, _ = rand.Read(iv)
	block, err := aes.NewCipher(dcrypto.DeriveUnlockKey(master, salt))
	if err != nil {
		log.Fatal(err)
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		log.Fatal(err)
	}
	out := make([]byte, 4, 4+len(salt)+len(iv))
	binary.BigEndian.PutUint32(out, 1)
	out = append(append(append(out, salt...), iv...), gcm.Seal(nil, iv, plain, nil)...)
	return base64.StdEncoding.EncodeToString(out)
}

func selfSigned(key *rsa.PrivateKey) string {
	tmpl := &x509.Certificate{
		SerialNumber: big.NewInt(1),
		Subject:      pkix.Name{CommonName: "keepiq fakevault"},
		NotBefore:    time.Now().Add(-time.Hour),
		NotAfter:     time.Now().Add(24 * time.Hour),
	}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, tmpl, &key.PublicKey, key)
	if err != nil {
		log.Fatal(err)
	}
	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}))
}

func writeJSON(w http.ResponseWriter, v any) {
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(v)
}
