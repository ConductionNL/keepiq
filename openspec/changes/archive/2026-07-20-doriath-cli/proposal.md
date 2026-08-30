---
kind: code
---

# Proposal: Doriath command-line client (human + CI modes)

## Why

Doriath already serves two audiences through a browser: humans (the Vue vault UI) and Nextcloud-internal machines (the RFC 7523 JWT machine secret-store API — `lib/Service/JwtAuthService.php:253`, `docs/integration-openconnector.md`). The one audience it serves through **nothing** is the shell: a developer at a terminal, or a CI/CD pipeline that needs to fetch a credential at build time. Doriath's own roadmap names this gap twice — `docs/FEATURES.md:273` ("CLI tool for secret management | Enterprise | DevOps workflow") and `docs/FEATURES.md:471` (backlog item 82, "CLI tool for secret management") — and the competitor table lists a CLI as a Bitwarden capability Doriath lacks (`docs/FEATURES.md:24`). No `openspec/changes/*` (active or archived) covers a CLI, and there is no CLI code in the repo.

The market signal is now unambiguous. **Proton Pass shipped an audited CLI (Recurity) in November 2025** aimed squarely at CI/CD fetch and rotation (recorded in Doriath's own market notes, `docs/FEATURES.md:496`) — the first consumer-vault CLI, validating the demand that Vault/OpenBao/Infisical proved on the machine-only side: DevOps teams tolerate running a second secrets system precisely *because* it has a CLI. Doriath's OpenConnector integration already serves Nextcloud-internal consumers, but nothing serves shell or CI users today (canonical feature `cli-tool`, demand 58; the `devops-integrator` stakeholder journey `cicd-secret-fetch` records "nothing stored in the pipeline" as the desired outcome).

Crucially, a CLI does **not** require weakening the zero-knowledge model (ADR-003). In human mode the CLI plays the exact role the browser plays — auth to Nextcloud with an app-password, unlock with the local master-password, and perform **every decryption client-side inside the CLI process**, so no plaintext key material ever reaches the server. In CI mode the CLI is just another RFC 7523 machine consumer — it holds the application private key, decrypts the `doriath-machine-secret-v1` envelope locally (`lib/Service/MachineSecretEnvelopeService.php:60`), and never persists plaintext. The server contract is unchanged; this change is a new *client* over surfaces that already exist.

## What Changes

- **Ship a standalone single static binary** (`doriath`) — no runtime, no Nextcloud app install, cross-compiled for Linux/macOS/Windows (amd64/arm64). Language choice (Go vs Rust) is a design decision; both give a single self-contained binary suitable for `curl | install` and CI base images.
- **Human mode** — authenticate to a Nextcloud instance with a **Nextcloud app-password** (never the login password), then **unlock** by deriving the master-password key locally and decrypting the stored EncryptionSuite private-key blob **in the CLI process**. All secret decryption happens client-side, exactly as the browser does. Commands: `login`, `unlock`, `list`, `get`, `show`, `copy` (to the OS clipboard), `logout`. A **session cache** (unlocked key held in an OS-keyring-backed or memory-only session with a configurable inactivity timeout) avoids re-entering the master password on every command.
- **CI mode** — authenticate as an **application** via the existing RFC 7523 JWT-bearer flow (sign an assertion with the application private key, exchange for the opaque 5-minute bearer token), fetch application secrets by name/id, decrypt the envelope locally. **Lease-aware when `machine-secret-leases` is present**: read the `Doriath-Lease-Id`/`Doriath-Lease-Expires` response headers, honour the TTL, and renew via the lease endpoint before expiry (self-configuring from the discovery document). Output modes: **env-var export lines** (`export FOO=…`), **`--output json`**, and **direct exec-with-injected-env** (`doriath run -- <cmd>`) that spawns a child process with the secrets in its environment and **never writes plaintext to disk**.
- **Shell completion** for bash/zsh/fish, and a global **`--output json`** for scriptable structured output on every read command.
- **Honest v1 scoping — read-only vault access.** v1 has **no write/edit/delete** commands. Rationale: a write in Doriath's model is not a simple PUT — creating or updating a shared secret requires **re-wrapping the value under every recipient's public key client-side** (the share fan-out the browser performs); doing that correctly in a headless CLI is a separate, larger surface. CI-mode write-back exists on the server (`POST/PUT /api/v1/app/secrets`) but is deferred to a follow-up so v1 ships a coherent read-only story rather than a half-built write path.

### Non-Goals

- **No human-mode secret writes/edits/deletes in v1** — read-only; write paths need the share fan-out and are deferred.
- **No new backend routes** — the CLI is a pure client over the existing session API (human mode) and the existing machine secret-store API (CI mode).
- **No plaintext at rest** — no command writes a decrypted value to a file; `doriath run` injects into a child process environment only.
- **No storage of the master password or the application private key by Doriath** — the master password is entered per-session (or held only in an OS keyring the user controls); the application private key is the operator's own credential, supplied by env/file/keyring.

## Capabilities

### New Capabilities

- `doriath-cli`: A standalone single-binary command-line client with two modes — a **human mode** (Nextcloud app-password auth + local master-password unlock, all decryption client-side in the CLI process, list/get/show/copy with a timeout-bounded session cache) and a **CI mode** (RFC 7523 JWT machine auth, lease-aware fetch, env/JSON/`run --` output, never plaintext to disk), plus shell completion and `--output json`. Read-only vault access in v1. Canonical home for the CLI contract.

### Modified Capabilities

_(none — the CLI is a new client over the existing `secret-store-api` machine surface and the existing session API; it adds no requirement to, and changes no scenario of, either. Lease-awareness consumes `machine-secret-leases` additively when present.)_

## Impact

- **Database**: none — no table, column, or migration. The CLI is a client only.
- **Backend**: none new — human mode uses the existing session-authenticated secret/unlock endpoints; CI mode uses the existing discovery → token → by-name → envelope path (`appinfo/routes.php:156`–`169`). No new controller or route.
- **New artifact**: a separate CLI codebase (Go or Rust) with its own build/release pipeline producing cross-platform static binaries; distributed via GitHub Releases / package managers, not through the Nextcloud app.
- **API**: none new. The CLI is a consumer of the discovery document, the token endpoint, and the machine/session read endpoints exactly as documented.
- **Cross-capability**: consumes `secret-store-api` (discovery, token exchange, by-name fetch, envelope, ETag/`updated_since`) in CI mode; consumes `machine-secret-leases` additively (lease headers + renew) when that change is present — both independently deployable.
- **Security**: preserves ADR-003 zero-knowledge end-to-end — the CLI process is the decryption boundary in both modes; no plaintext key material or decrypted value ever reaches the server or a disk file. App-password auth (never the login password) and per-session master-password entry keep the human-mode threat model aligned with the browser.
