# keepiq-cli

A single static binary, stdlib-only Go client for [Keepiq](../), the
zero-knowledge Nextcloud secrets manager. It talks to the **same** server
surfaces the browser app and the openconnector machine consumer already use —
nothing new server-side — and does **all** decryption client-side, so no
plaintext key material ever reaches the server (ADR-003).

`v1 is READ-ONLY.` There is no create/edit/update/delete command: a write in
Keepiq's model requires re-wrapping the value under every recipient's public
key (the share fan-out), a separate and larger surface deferred to a follow-up.

## Install

Download the binary for your platform from the release page and drop it on your
`PATH`. It needs no PHP, no Node, no runtime — a clean image runs `keepiq
--version` out of the box. Cross-compiled targets: `linux`, `darwin`, `windows`
on `amd64` and `arm64`.

Build from source:

```sh
cd cli
go build -o keepiq .
```

Shell completion:

```sh
keepiq completion bash  > /etc/bash_completion.d/keepiq
keepiq completion zsh   > "${fpath[1]}/_keepiq"
keepiq completion fish  > ~/.config/fish/completions/keepiq.fish
```

## Human mode

Interactive use. Authenticates with a Nextcloud **app-password** (never your
login password) and unlocks by deriving the master-password key and unwrapping
the EncryptionSuite private key **in this process**.

```sh
keepiq login --url https://cloud.example.org --user alice   # stores URL + app-password (0600)
keepiq list                                                 # metadata only
keepiq show <id>                                            # reveal all fields
keepiq get  <id> key                                        # print one field
keepiq copy <id> key --clear-after 30                       # to clipboard, auto-clear
```

The master password is prompted per invocation (no echo where a TTY is
available) and **never leaves the process**: it is used only to derive the AES
unlock key locally. A wrong master password, or a private key that does not
match the suite certificate, fails fast with a mismatch error before any secret
is touched.

> **Never run `keepiq` human-mode unlock on a shared or untrusted host.** The
> derived key lives in this process's memory while it runs; a host you do not
> control can read that memory. Use CI mode with a scoped application key there
> instead.

### Custody

- The **app-password** is stored in `~/.config/keepiq/config.json`, mode `0600`.
  An OS-keyring backend is a planned hardening follow-up (it needs cgo/dbus and
  would break the pure-static single-binary build).
- The **master password** and the derived unlock key are **never** written to
  disk and never sent in any request — human mode holds them in memory only, for
  the duration of a single command.

## CI mode

Non-interactive machine use. An RFC 7523 consumer that holds the **application
private key** — a credential Keepiq never stores. It self-configures from the
instance discovery document alone.

```sh
export KEEPIQ_URL=https://cloud.example.org
export KEEPIQ_APP_ID=my-pipeline
export KEEPIQ_APP_KEY_FILE=/run/secrets/keepiq-app.pem   # or KEEPIQ_APP_KEY=<PEM>

keepiq ci fetch DB_PASSWORD --output json                 # {"name":...,"value":...}
keepiq ci fetch DB_PASSWORD --output env                  # export KEEPIQ_DB_PASSWORD='…'
keepiq ci run DB_PASSWORD,API_TOKEN -- ./migrate.sh       # injected into the child env only
```

`ci run` injects the fetched secrets into the child process environment **only**
— nothing is written to disk and the parent environment is untouched. The
`--output env` form prints an `export` line and warns on stderr that exporting a
secret into the shell environment exposes it to sibling child processes.

### Leases

When the instance advertises lease support via discovery, CI fetches carry
`Doriath-Lease-*` headers; the client surfaces the lease id and expiry on
stderr. Against a lease-unaware instance the fetch still succeeds and simply
omits lease reporting.

## Crypto parity

The `internal/crypto` package reimplements the browser recipe **byte-for-byte**:

- **Private-key blob** (human unlock): base64 of `[4B version][16B salt][12B
  IV][ciphertext+16B GCM tag]`. The unlock key is
  `PBKDF2-HMAC-SHA256(masterPassword, salt, 600000)` → AES-256-GCM.
- **Secret fields** (`rsa-oaep-sha256-chunked-v1`): base64 of `[4B chunk count
  BE][512B RSA-OAEP-SHA256 blocks…]`, each block decrypted with the suite's
  RSA-4096 private key and concatenated.

PBKDF2 is implemented in-house over `crypto/hmac` (RFC 8018) so the CLI has zero
external dependencies. Byte-parity is pinned by the RFC 6070 test vectors in
`internal/crypto/crypto_test.go`.

```sh
go test ./...
```
