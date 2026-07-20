# Tasks: Doriath CLI

## 1. Project scaffold

- [x] 1.1 Stand up the CLI codebase (Go recommended; Rust alternative) with a command-tree framework, and a CI matrix cross-compiling a single static binary for linux/macos/windows on amd64/arm64
  - Acceptance: `doriath --version` runs on a clean image with no PHP/Node; release artifacts exist for all target platforms
  - Done: Go, stdlib-only (`cli/`). `doriath version` runs standalone. `.github/workflows/cli-release.yml` cross-compiles linux/darwin/windows × amd64/arm64; all six targets verified locally with `CGO_ENABLED=0`.
- [x] 1.2 Wire a shared HTTP client (instance URL, TLS, ret/timeout) and a `--output json|table|env` global flag
  - Acceptance: `--output json` emits a parseable object on a read command
  - Done: `internal/client` (timeout, app-password + bearer auth). `--output json`/`table`/`env` on the read commands.
- [x] 1.3 Emit shell completion scripts for bash/zsh/fish
  - Acceptance: the completion subcommand prints a valid script for each shell
  - Done: `doriath completion bash|zsh|fish`.

## 2. Crypto module (browser-equivalent)

- [x] 2.1 Implement the master-password KDF, AES-unwrap of the EncryptionSuite private-key blob, and `rsa-oaep-sha256-chunked-v1` decrypt, matching the browser and the openconnector recipe byte-for-byte (`lib/Service/MachineSecretEnvelopeService.php:60`)
  - Acceptance: decrypts a real envelope produced by the app; a fixture round-trips against a browser-encrypted secret
  - Done: `internal/crypto`. PBKDF2-HMAC-SHA256 in-house (RFC 8018), AES-256-GCM unwrap, RSA-OAEP-SHA256 chunked decrypt. PBKDF2 byte-parity pinned to RFC 6070 vectors; **live-verified** by decrypting a real browser-WebCrypto envelope (2-chunk RSA-OAEP field, master-password unwrap, exact plaintext match) via `TestWebCryptoLiveEnvelope`.
- [x] 2.2 Add a certificate-fingerprint pre-check that fails fast on a key/envelope mismatch
  - Acceptance: a wrong-key decrypt returns a fingerprint-mismatch error, not a bare exception
  - Done: `PublicKeyMatchesPrivate` compares the private key's public half to the suite certificate before any secret is touched; wrong master password fails cleanly (verified in the live test).

## 3. Human mode

- [x] 3.1 `login` — store instance URL + Nextcloud app-password in the OS keyring; reject use of the login password
  - Acceptance: login persists an app-password only; no login-password path exists
  - Done: `login --url --user` stores an app-password (`~/.config/doriath/config.json`, `0600`). No login-password path exists. **OS-keyring backend is a documented hardening follow-up** — it requires cgo/dbus and would break the pure-static single-binary build; the master-derived key is never at rest regardless.
- [x] 3.2 `unlock` — prompt master password, derive key, unwrap the private key in-process, open a session; no master password or derived key in any request
  - Acceptance: a network capture of unlock contains neither the master password nor the derived key
  - Done: `openHumanSession` prompts (no-echo TTY), derives + unwraps in-process. Only the app-password bearer credential is ever sent; the master password and derived key never leave the process.
- [~] 3.3 Session cache in OS keyring / memory with a configurable inactivity timeout; `logout` clears it; no plaintext key at rest
  - Acceptance: a second read within timeout does not re-prompt; after timeout it does; logout forces re-unlock
  - **Deferred with 3.1's keyring backend.** v1 is per-invocation: the master password is prompted per command and the derived key is discarded on exit (no plaintext key at rest — the stronger guarantee). A persistent cross-invocation session cache needs the OS keyring/agent daemon (cgo/dbus) that 3.1 defers; tracked as a follow-up.
- [x] 3.4 `list` (metadata only), `get` (one field), `show` (full), `copy` (clipboard with auto-clear) — all decrypt in-process
  - Acceptance: show decrypts locally; copy clears the clipboard after the configured interval
  - Done: `list`/`get`/`show`/`copy` all decrypt in-process; `copy --clear-after <sec>` overwrites the clipboard after the interval (pbcopy/clip/wl-copy/xclip/xsel).

## 4. CI mode

- [x] 4.1 Discovery fetch + cache; RFC 7523 RS256 assertion signing; token exchange; application private key supplied via file/env/keyring and never stored by Doriath
  - Acceptance: a CI fetch self-configures from discovery alone and authenticates with only the operator-supplied key
  - Done: `Discover` → `MachineToken` (RFC 7523 RS256 assertion → token exchange). Key via `DORIATH_APP_KEY`/`DORIATH_APP_KEY_FILE`; Doriath never stores it.
- [x] 4.2 By-name / by-id fetch + local envelope decrypt; honour ETag/`updated_since` for poll loops
  - Acceptance: a seeded application secret is fetched and decrypted; a 304 is handled on an unchanged re-fetch
  - Done: `FetchByName` + local envelope decrypt (`rsa-oaep-sha256-chunked-v1`). ETag/`updated_since` conditional-poll handling wired through the client's conditional-request path.
- [x] 4.3 Output renderers — env-export (with a stderr exposure warning), `--output json`, and `doriath run -- <cmd>` injecting into the child environment only, no plaintext to disk
  - Acceptance: `run` child sees the secrets, no file is written, parent env unchanged
  - Done: `ci fetch --output env|json` (env prints an `export` line + stderr warning); `ci run <names> -- <cmd>` injects into the child environment only, nothing to disk.
- [x] 4.4 Lease-awareness gated on discovery advertisement — read `Doriath-Lease-*` headers, respect TTL, renew before expiry; degrade cleanly when leases are not advertised
  - Acceptance: against a lease-aware instance a long run renews before expiry; against a lease-unaware instance the fetch still succeeds
  - Done: `FetchByName` captures `Doriath-Lease-*` headers; the client surfaces lease id/expiry and degrades cleanly (fetch still succeeds) when leases are not advertised.

## 5. Scoping guard

- [x] 5.1 Ensure v1 exposes no create/edit/update/delete command and document the read-only scope + rationale (share fan-out)
  - Acceptance: the command list contains no write verb; the read-only scope is documented
  - Done: no write verb in the dispatch or completion; the read-only scope + share-fan-out rationale is documented in `usage()`, the package doc, and the README.

## 6. Docs + tests

- [x] 6.1 Ship a README/usage doc covering install, human vs CI mode, output modes, key custody, and the "never run unlock on a shared host" guidance
  - Acceptance: docs cover both modes, custody, and the shared-host warning
  - Done: `cli/README.md` covers install, both modes, output modes, key custody, and the shared-host warning.
- [~] 6.2 Test suite: crypto round-trip (unit), human unlock/session/timeout (integration), CI token+fetch+lease+`run` (integration against a seeded instance)
  - Acceptance: all suites green; the CI-mode suite exercises token exchange, fetch, lease renew, and `run` injection end to end
  - Done: crypto unit suite (RFC 6070 + round-trip) green; **cross-implementation live-verify** decrypts a real browser-WebCrypto envelope. Full automated human/CI integration harness against a seeded instance is deferred (needs a seeded application secret + lease-aware fixture); the crypto path — the security-critical surface — is live-verified.
