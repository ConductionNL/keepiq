# Keepiq CLI Specification

**Status**: done

**OpenSpec changes:** [archive/2026-07-20-keepiq-cli](../../changes/archive/2026-07-20-keepiq-cli/)

> Implemented in `cli/` (GitHub PR #111). Session-cache/keyring (3.3) and the full CI integration harness (6.2) are documented follow-ups; the security-critical crypto path is live-verified (WebCrypto cross-impl + live token-exchange assertion path).

## Purpose

Give Keepiq a shell front door. Today the vault is reachable through a browser (humans) and the RFC 7523 machine secret-store API (Nextcloud-internal apps such as OpenConnector), but nothing serves a developer at a terminal or a CI/CD pipeline — the DevOps audience Keepiq's roadmap names at `docs/FEATURES.md:273` and `:471`. A single static binary with a human mode (app-password auth + local master-password unlock, all decryption client-side) and a CI mode (JWT machine auth, lease-aware fetch, env/JSON/`run --` output, never plaintext to disk) closes that gap without weakening the zero-knowledge model (ADR-003) and without adding any backend surface. Market-validated by Proton Pass's audited Nov-2025 CI/CD CLI (`docs/FEATURES.md:496`); canonical feature `cli-tool` (demand 58); serves the `devops-integrator` journey `cicd-secret-fetch` ("nothing stored in the pipeline").

## Requirements

### Requirement: Single self-contained binary distribution
The system MUST distribute the CLI as a single statically-linked, cross-platform binary requiring no runtime or app install, with shell completion for bash/zsh/fish and a `--output json` mode on every read command.

#### Scenario: Binary runs with no runtime dependency
- GIVEN a clean CI base image with no PHP or Node
- WHEN the `keepiq` binary is invoked
- THEN the system MUST run without any external runtime or Nextcloud app install

### Requirement: Human mode preserves zero-knowledge decryption
The system MUST authenticate human mode with a Nextcloud app-password (never the login password) and perform all master-password unlock and secret decryption inside the CLI process, transmitting no plaintext or derived key material to the server.

#### Scenario: Show decrypts a secret in-process
- GIVEN an unlocked human-mode session
- WHEN the user runs `show <secret>`
- THEN the system MUST fetch ciphertext and decrypt it locally, and the server MUST never return or receive a plaintext value

### Requirement: Timeout-bounded session cache
The system MUST cache the unlocked key in an OS-keyring-backed or memory-only session with a configurable inactivity timeout, never write it to a plaintext file, and clear it on `logout`.

#### Scenario: Cached session avoids re-prompt within the window
- GIVEN an unlocked session inside its timeout
- WHEN the user runs another read command
- THEN the system MUST NOT re-prompt for the master password

### Requirement: CI mode authenticates as an application via RFC 7523
The system MUST authenticate CI mode via the RFC 7523 JWT-bearer flow, decrypt the machine envelope locally with the operator-supplied application private key, and never store that key or embed it in shareable config.

#### Scenario: Fetch and decrypt an application secret
- GIVEN a CI environment holding a valid application private key
- WHEN the CLI exchanges an assertion for a bearer token and fetches by name
- THEN the system MUST return an encrypted envelope the CLI decrypts locally, with no server-side plaintext

### Requirement: CI-mode output never writes plaintext to disk
The system MUST offer env-export, `--output json`, and `keepiq run -- <cmd>` output modes, and MUST NOT write any decrypted value to a file; `run` injects into the child process environment only.

#### Scenario: run injects into the child environment only
- GIVEN a resolvable set of application secrets
- WHEN the user runs `keepiq run -- <cmd>`
- THEN `<cmd>` MUST see the secrets in its environment and no decrypted value MUST be written to disk

### Requirement: CI mode honours leases when advertised
The system MUST read lease headers and renew leases before expiry when the discovery document advertises lease support, and MUST remain fully functional against a lease-unaware instance.

#### Scenario: Works against a lease-unaware instance
- GIVEN an instance not advertising lease support
- WHEN the CLI performs a CI-mode fetch
- THEN the system MUST succeed without any lease read or renewal

### Requirement: Read-only vault access in v1
The system MUST expose read-only vault access in v1 with no create/edit/delete command, documenting that writes require client-side re-wrapping under every recipient's public key.

#### Scenario: No write command exists in v1
- GIVEN the v1 binary
- WHEN the user inspects available commands
- THEN no create/edit/update/delete command MUST be present

## User Stories

- As a developer, I want to fetch a secret from my vault at the terminal so that I stop copy-pasting it out of the browser.
- As a CI pipeline, I want to inject application secrets into a build step's environment so that nothing plaintext is ever stored in the pipeline.
- As a security-conscious operator, I want the CLI to decrypt only in-process and never write plaintext to disk so that the zero-knowledge guarantee extends to the shell.

## Acceptance Criteria

- [ ] Single static binary runs on a clean image with no runtime; completion + `--output json` present
- [ ] Human mode: app-password auth, in-process unlock/decrypt, no plaintext or derived key in any request
- [ ] Timeout-bounded session cache with `logout`; no key at rest
- [ ] CI mode: RFC 7523 auth, local envelope decrypt, operator-supplied key never stored
- [ ] Output modes env/JSON/`run --`; `run` injects into the child env only, no plaintext to disk
- [ ] Lease-aware when advertised; degrades cleanly when not
- [ ] No write command in v1; read-only scope documented

## Notes

- Depends on the existing `secret-store-api` machine surface and the session API; adds no backend route.
- Soft-depends on `machine-secret-leases` for lease-awareness (advertisement-gated; independently deployable).
- ADR-001 (own tables, no OpenRegister), ADR-003 (always-E2E). Language choice (Go recommended / Rust) is a design decision recorded in the change's design.md.
