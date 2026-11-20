# CXF Import and Export Specification

**Status**: done

**OpenSpec changes:**
- `cxf-import-export` (2026-07-16, depends on `passkey-item-type`) — Bidirectional, client-side, file-based FIDO Credential Exchange Format (CXF) import and export as a new format on the existing import/export pipelines: CXF-entity ↔ Keepiq-secret-type mapping (passwords, passkeys, TOTP, notes, API keys, SSH keys, Wi-Fi), unmapped-item reporting, folder/collection mapping and duplicate detection reusing existing machinery, and re-auth-gated CXF export. CXP (HPKE encrypted transfer) is out of scope in v1.

## Purpose

The FIDO Credential Exchange Format (CXF) reached FIDO Proposed Standard in August 2025 — the first vendor-neutral way to move a full credential set (passwords, passkeys, TOTP, notes, API keys, SSH keys, Wi-Fi) between managers without a lossy CSV. Apple ships CXF-based transfer in iOS 26 / macOS 26, and Bitwarden, 1Password, Dashlane, and Proton Pass are implementing it. Standards-based portability directly answers the lock-in objection that dominates buyer conversations after the LastPass trust collapse (ICO fine ~£1.2M, late 2025) — and no Nextcloud-native app offers it.

This feature adds CXF as a new format on Keepiq's already-shipped, always-E2E import and export pipelines: import parses and encrypts client-side; export decrypts and assembles client-side. It reuses the existing parser registry, mapping preview, folder mapping, duplicate detection, chunked commit, rejected rows, summary, and export gating — no new backend routes and no departure from the E2E contract (ADR-003). It completes the passkey story by using the `passkey-item-type` canonical schema (deliberately aligned 1:1 with the CXF passkey entity) as the passkey mapping target — hence this feature depends on `passkey-item-type`.

## Requirements

### Requirement: Client-side CXF import
The system MUST parse a CXF JSON document entirely in the browser, encrypt every sensitive field with the owner's active EncryptionSuite public certificate before persistence, require an unlocked vault, and register as a format in the existing import pipeline so it reuses mapping preview, folder mapping, duplicate detection, chunked commit, rejected rows, and summary.

#### Scenario: CXF plaintext never sent to the server
- GIVEN a user imports a CXF file with plaintext credentials
- WHEN the import flow runs
- THEN every request MUST carry only ciphertext for key/login/additional_fields; only name, url, type, and folder path may be plaintext

### Requirement: CXF entity to Keepiq type mapping
The system MUST map CXF entities to Keepiq secret types bidirectionally: passwords → `login`, notes → `note`, API keys → `api_key`, SSH keys → `ssh_key`, Wi-Fi → `note` with custom fields; export applies the reverse mapping. On export, a secret's `totp` and `passkeys` MUST be written as TOTP and passkey entities in that secret's own CXF item, while a standalone `totp` or `passkey` item keeps exporting as an item of its own.

#### Scenario: A login keeps its code and passkeys through CXF
- GIVEN a login with a `totp` seed and passkeys
- WHEN it is exported as CXF and the file is imported again
- THEN one login MUST come back holding a seed that gives the same code, and the same passkeys

### Requirement: CXF TOTP and passkey entities go on a login
A TOTP or passkey entity MUST go on the `totp` or `passkeys` field of the first accepted login in its CXF item, else of its first accepted other secret, whatever the credential order and never on a rejected row, passkeys in the `passkey-item-type` canonical schema; an item with neither MUST become a login with an empty password that carries them. Passkey entities MUST be read from `username` or the earlier `userName` and written with `username`.

#### Scenario: CXF passkey round-trips via the canonical schema
- GIVEN a CXF document with an item holding a passkey entity and no other credential
- WHEN it is imported
- THEN a login MUST be created with the credential in its `passkeys` field per the `passkey-item-type` canonical schema, encrypted in the browser, and its RP id in `url`

### Requirement: CXF TOTP and passkey entity shapes
TOTP entities follow CXF 1.0: a base32 `secret` with `period`, `digits` and `algorithm`, stored as the bare secret when they are 30, 6 and `sha1` with no `issuer` or `username`, and as an otpauth URI otherwise; a TOTP with an algorithm or digit count Keepiq cannot generate MUST be rejected with a reason (CXF: importers MUST ignore unknown algorithms). The `url` shape of earlier Keepiq exports MUST still be read.

#### Scenario: A TOTP with an unknown algorithm
- GIVEN a CXF TOTP entity whose `algorithm` is not `sha1`, `sha256` or `sha512`
- WHEN it is imported
- THEN it MUST appear in the rejected list with a reason and no seed MUST be stored

### Requirement: Unmapped-item report
The system MUST surface every unrepresentable item rather than silently dropping it — import failures into the rejected-rows list, export non-representable values into an export unmapped-item report shown before download.

#### Scenario: Unrepresentable entity is reported
- GIVEN a CXF entity Keepiq cannot represent
- WHEN the file is imported
- THEN the entity MUST appear in the rejected-rows list with a reason and be counted in the summary, never silently dropped

### Requirement: Re-auth-gated client-side CXF export
The system MUST assemble the CXF document client-side, gate export by an unencrypted-file warning AND fresh master-password re-authentication (because a raw CXF file is plaintext), report to the export-event endpoint before download, and emit a `SecretExportedEvent` with mode `cxf` carrying no secret material.

#### Scenario: CXF export requires warning and re-auth
- GIVEN a user with an unlocked vault starts a CXF export
- WHEN the flow begins
- THEN an unencrypted-file warning MUST be acknowledged and fresh master-password re-entry MUST be required before the file is generated locally

### Requirement: CXP out of scope in v1
The system's v1 CXF support MUST be file-based only; the encrypted Credential Exchange Protocol (CXP/HPKE), device pairing, and live app-to-app handoff MUST NOT be implemented and are recorded as future work.

#### Scenario: Only file-based CXF is offered
- GIVEN a user uses CXF import or export
- WHEN they select the format
- THEN the only mechanism MUST be a local CXF file; no encrypted CXP transfer or device pairing MUST be offered

## User Stories

- As an org migrating off LastPass/1Password, I want to import a standards-based CXF export so that I am not locked in and do not lose data to a lossy CSV.
- As a user, I want to export my whole vault as CXF so that I can move to any CXF-supporting manager and prove Keepiq does not trap my data.
- As a user, I want my passkeys and TOTP seeds to move through CXF, not just passwords, so that my full credential set migrates.
- As a user, I want to be told exactly what will not survive a CXF round-trip so that I am not surprised by silent data loss.
- As a security-conscious user, I want CXF export to warn me it is plaintext and re-check my master password so that I do not accidentally leak my vault.

## Acceptance Criteria

- [ ] CXF import parses client-side, encrypts before persistence, requires an unlocked vault, and reuses all existing import wizard steps.
- [ ] CXF entities map to Keepiq types bidirectionally, with passkeys via the `passkey-item-type` canonical schema and TOTP via the existing seed format.
- [ ] Every unrepresentable item is reported (import rejected rows; export unmapped-item report), never silently dropped.
- [ ] CXF export assembles client-side, is gated by warning + fresh master-password re-auth, reports before download, and emits `SecretExportedEvent` mode `cxf` with no secret material.
- [ ] A CXF export → import round-trip reproduces core credentials and folders; only documented extensions are lossy.
- [ ] v1 is file-based only; CXP/HPKE, device pairing, and live handoff are not implemented.
- [ ] No new backend route and no DB migration are introduced.

## Notes

- Depends on `passkey-item-type` (passkey mapping target: canonical schema + `passkey` type must exist first).
- Reuses `secret-import` (mapping preview, folder mapping, duplicate detection, chunked commit, rejected rows, summary) and `secret-export` (client-side assembly, warning + re-auth gating, `SecretExportedEvent`) unchanged.
- Related ADRs: ADR-001 (own DB tables, imperative — no OpenRegister), ADR-003 (always-E2E encryption).
- Future work: CXP (HPKE-encrypted device-to-device transfer) as a separate change; a dedicated `wifi` secret type as an additive change.
