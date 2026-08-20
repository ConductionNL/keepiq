# Passkey Item Type Specification

**Status**: done

**OpenSpec changes:**
- `passkey-item-type` (2026-07-16) — Adds the `passkey` eighth system secret type: canonical CXF-aligned WebAuthn credential schema stored ciphertext in the existing `key` field (RP id mirrored into plaintext `url`), passkey listing/filtering and site-associated presentation, creation via the secret CRUD API and Bitwarden `fido2Credentials` import, password-health exclusion, and unchanged carry-through via sharing/export/audit. Storage/schema/presentation only — the WebAuthn authenticator/provider (extension interception) is a later change.

## Purpose

Passkeys (WebAuthn/FIDO2 discoverable credentials) are 2026 table stakes for a credential manager: FIDO Alliance data puts ~75% of consumers with at least one passkey, Microsoft Entra ID defaults new tenants to passkeys from 1 Sept 2026, and every serious competitor (Bitwarden, Vaultwarden, AliasVault) already stores them — while the incumbent Nextcloud "Passwords" app has three open passkey issues and no support. Doriath's own feature matrix names FIDO2/WebAuthn as an unaddressed Bitwarden gap (`docs/FEATURES.md:24`, `:308`, `:356`).

This feature lets Doriath store, organise, present, share, and export WebAuthn credentials with the same always-E2E guarantees as any other secret. A passkey's private key is just another secret value: RSA-encrypted with the owner's EncryptionSuite public certificate (ADR-003), it rides in the existing encrypted `key` field, so all existing paths carry it unchanged. The canonical field schema aligns 1:1 with the FIDO Credential Exchange Format (CXF) passkey entity so the sibling `cxf-import-export` feature maps without translation loss.

## Requirements

### Requirement: Passkey system secret type
The system MUST seed a `passkey` system secret type ("Passkey") as an eighth built-in system type with a stable deterministic UUID, without adding any database column, table, or migration — the credential lives in the existing encrypted `key` field.

#### Scenario: Passkey is a seeded system type
- GIVEN the app's secret-type seeding has run
- WHEN the system secret types are listed
- THEN `passkey` ("Passkey") MUST be present as a system type alongside the other seven and MUST NOT be modifiable or deletable

### Requirement: Canonical CXF-aligned credential schema
The system MUST store a passkey as one canonical JSON object in the encrypted `key` field (credential id, RP id, RP name, user name, user display name, user handle, private key, COSE algorithm, counter, transports, created-at), with the core fields mapping 1:1 to the FIDO CXF passkey entity and `counter`/`transports`/`createdAt` as documented Doriath extensions. Only the RP id MAY additionally be mirrored into the plaintext `url` field.

#### Scenario: Credential stored ciphertext, RP id in url
- GIVEN a user creates a `passkey` secret with a canonical credential JSON for `example.com`
- WHEN the secret is stored
- THEN the entire credential MUST be encrypted in `key`, `example.com` MUST be in the plaintext `url`, and no credential material MUST appear in any plaintext field or request body

### Requirement: Listing, filtering, and site-associated presentation
The system MUST let users filter the vault by the `passkey` type and MUST present a passkey with its associated site, user name, truncated credential id, transports, and creation date, masking the private key and never rendering it in full. An unparseable credential MUST show an explicit invalid state and MUST NOT fabricate fields.

#### Scenario: Passkey view masks the private key
- GIVEN the vault is unlocked and a `passkey` secret is opened
- WHEN the view renders
- THEN it MUST show the associated site and metadata and MUST mask the private key material (reveal/copy gated)

### Requirement: Creation via API and Bitwarden import
The system MUST allow passkey creation through the existing secret CRUD API and MUST route Bitwarden `login.fido2Credentials[]` entries into `passkey`-typed secrets during import, encrypting every field in the browser; a Bitwarden entry lacking credential id, RP id, or private key MUST be rejected, not partially created.

#### Scenario: Bitwarden passkey imports client-side
- GIVEN a Bitwarden JSON export with a `fido2Credentials` entry
- WHEN the user imports it
- THEN a `passkey` secret MUST be created with its credential encrypted client-side and its RP id in `url`, with no plaintext credential sent to the server

### Requirement: Unchanged carry-through and health exclusion
The system MUST carry `passkey` secrets through user sharing, link sharing, export, and audit using the existing paths unchanged (credential stays ciphertext) and MUST exclude `passkey`-typed secrets from password-health analysis.

#### Scenario: Passkey excluded from password-health
- GIVEN the vault contains a `passkey` secret and password-health runs
- WHEN the health engine processes the vault
- THEN the passkey's private key MUST NOT be scored, counted for reuse, or breach-checked

## User Stories

- As a user, I want to store my passkeys next to my passwords so that all my credentials for a service live in one vault.
- As a user, I want to migrate the passkeys I exported from Bitwarden so that switching to Doriath does not lose them.
- As a user, I want to see which site a passkey belongs to and search for it so that I can find the right credential.
- As a user, I want my passkey private key to stay encrypted end-to-end and masked in the UI so that it is never exposed to the server or over my shoulder.
- As a user, I want to share a stored passkey with a teammate without the server ever seeing its private key.

## Acceptance Criteria

- [ ] `passkey` is a seeded eighth system type with a stable deterministic UUID; no schema/migration change.
- [ ] The credential is stored as canonical JSON ciphertext in `key`; only RP id is mirrored into plaintext `url`; the server cannot distinguish it from any other secret.
- [ ] The canonical schema aligns 1:1 with the FIDO CXF passkey entity on the core fields; extensions are documented.
- [ ] Passkeys can be listed/filtered by type and presented with associated site + metadata, private key masked and never rendered in full.
- [ ] An unparseable credential shows an explicit invalid state and never fabricated fields.
- [ ] Passkeys are created via the secret CRUD API and Bitwarden `fido2Credentials` import; partial Bitwarden entries are rejected.
- [ ] Passkeys carry through sharing, link sharing, export, and audit unchanged and are excluded from password-health.
- [ ] No WebAuthn authenticator/provider role and no passkey vault-login are introduced.

## Notes

- The WebAuthn authenticator/provider (browser-extension interception of `navigator.credentials.create/get`) is a scoped later change that writes through this feature's secret-create seam and canonical schema.
- `cxf-import-export` depends on this feature: it uses the canonical passkey schema as its passkey mapping target.
- Related ADRs: ADR-001 (own DB tables, imperative — no OpenRegister), ADR-003 (always-E2E RSA/AES encryption).
- Mirrors the archived `add-totp-secrets` change: new system type whose sensitive value rides in the existing encrypted `key` field, reusing all secret paths unchanged.
