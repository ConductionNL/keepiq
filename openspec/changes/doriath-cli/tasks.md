# Tasks: Doriath CLI

## 1. Project scaffold

- [ ] 1.1 Stand up the CLI codebase (Go recommended; Rust alternative) with a command-tree framework, and a CI matrix cross-compiling a single static binary for linux/macos/windows on amd64/arm64
  - Acceptance: `doriath --version` runs on a clean image with no PHP/Node; release artifacts exist for all target platforms
- [ ] 1.2 Wire a shared HTTP client (instance URL, TLS, ret/timeout) and a `--output json|table|env` global flag
  - Acceptance: `--output json` emits a parseable object on a read command
- [ ] 1.3 Emit shell completion scripts for bash/zsh/fish
  - Acceptance: the completion subcommand prints a valid script for each shell

## 2. Crypto module (browser-equivalent)

- [ ] 2.1 Implement the master-password KDF, AES-unwrap of the EncryptionSuite private-key blob, and `rsa-oaep-sha256-chunked-v1` decrypt, matching the browser and the openconnector recipe byte-for-byte (`lib/Service/MachineSecretEnvelopeService.php:60`)
  - Acceptance: decrypts a real envelope produced by the app; a fixture round-trips against a browser-encrypted secret
- [ ] 2.2 Add a certificate-fingerprint pre-check that fails fast on a key/envelope mismatch
  - Acceptance: a wrong-key decrypt returns a fingerprint-mismatch error, not a bare exception

## 3. Human mode

- [ ] 3.1 `login` — store instance URL + Nextcloud app-password in the OS keyring; reject use of the login password
  - Acceptance: login persists an app-password only; no login-password path exists
- [ ] 3.2 `unlock` — prompt master password, derive key, unwrap the private key in-process, open a session; no master password or derived key in any request
  - Acceptance: a network capture of unlock contains neither the master password nor the derived key
- [ ] 3.3 Session cache in OS keyring / memory with a configurable inactivity timeout; `logout` clears it; no plaintext key at rest
  - Acceptance: a second read within timeout does not re-prompt; after timeout it does; logout forces re-unlock
- [ ] 3.4 `list` (metadata only), `get` (one field), `show` (full), `copy` (clipboard with auto-clear) — all decrypt in-process
  - Acceptance: show decrypts locally; copy clears the clipboard after the configured interval

## 4. CI mode

- [ ] 4.1 Discovery fetch + cache; RFC 7523 RS256 assertion signing; token exchange; application private key supplied via file/env/keyring and never stored by Doriath
  - Acceptance: a CI fetch self-configures from discovery alone and authenticates with only the operator-supplied key
- [ ] 4.2 By-name / by-id fetch + local envelope decrypt; honour ETag/`updated_since` for poll loops
  - Acceptance: a seeded application secret is fetched and decrypted; a 304 is handled on an unchanged re-fetch
- [ ] 4.3 Output renderers — env-export (with a stderr exposure warning), `--output json`, and `doriath run -- <cmd>` injecting into the child environment only, no plaintext to disk
  - Acceptance: `run` child sees the secrets, no file is written, parent env unchanged
- [ ] 4.4 Lease-awareness gated on discovery advertisement — read `Doriath-Lease-*` headers, respect TTL, renew before expiry; degrade cleanly when leases are not advertised
  - Acceptance: against a lease-aware instance a long run renews before expiry; against a lease-unaware instance the fetch still succeeds

## 5. Scoping guard

- [ ] 5.1 Ensure v1 exposes no create/edit/update/delete command and document the read-only scope + rationale (share fan-out)
  - Acceptance: the command list contains no write verb; the read-only scope is documented

## 6. Docs + tests

- [ ] 6.1 Ship a README/usage doc covering install, human vs CI mode, output modes, key custody, and the "never run unlock on a shared host" guidance
  - Acceptance: docs cover both modes, custody, and the shared-host warning
- [ ] 6.2 Test suite: crypto round-trip (unit), human unlock/session/timeout (integration), CI token+fetch+lease+`run` (integration against a seeded instance)
  - Acceptance: all suites green; the CI-mode suite exercises token exchange, fetch, lease renew, and `run` injection end to end
