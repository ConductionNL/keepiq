# Design — cxf-import-export

## Context

Doriath's import and export are already shipped, always-E2E, and pluggable. Import parses and encrypts entirely in the browser through a parser registry (`src/import/parserRegistry.js` — `registerParser`/`getParser`/`listParsers`), with one parser per format under `src/import/parsers/` (`bitwarden.js`, `csv.js`, `keepassXml.js`, `ncPasswords.js`), then commits already-encrypted rows through an authenticated chunked batch endpoint (`secret-import` spec: mapping preview → folder mapping → duplicate detection → chunked commit → rejected rows → summary). Export decrypts and assembles entirely in the browser (`src/export/serializer.js` + `backup.js`/`csv.js`/`gdprPackage.js`), and the plaintext CSV export is gated by an explicit unencrypted-file warning **and** fresh master-password re-authentication, emitting a `SecretExportedEvent` (`secret-export` spec).

The FIDO Credential Exchange Format (CXF) is a JSON credential-interchange document (FIDO Proposed Standard, Aug 2025). This change adds CXF as a new format on **both** existing pipelines, bidirectionally, with no new backend and no departure from the E2E contract. The sibling `passkey-item-type` change defines a canonical passkey schema aligned 1:1 with the CXF passkey entity; this change uses it as the passkey mapping target (hence `depends_on: [passkey-item-type]`).

## Goals / Non-Goals

**Goals:**

- Bidirectional, standards-based, file-based CXF import and export, entirely client-side, reusing the existing import/export machinery.
- Map the full set of common CXF credential entities to Doriath secret types and back, with passkeys round-tripping via the `passkey-item-type` canonical schema.
- Report every unmappable/unrepresentable item to the user; never silently drop data.
- Preserve the always-E2E guarantee: import encrypts before persistence; export assembles client-side and is re-auth-gated because CXF is plaintext.

**Non-Goals:**

- CXP (the HPKE-encrypted Credential Exchange **Protocol**), device pairing, or live app-to-app handoff — file-based CXF only in v1.
- Any new backend route or server-side parse/assembly.
- A lossless guarantee for data neither side can represent — reported, not silently dropped.

## Decisions

### D1 — File-based CXF only; CXP/HPKE is future work
CXF (the *format*, a JSON document) and CXP (the *protocol*, an HPKE-encrypted device-to-device transfer) are separable. v1 ships the format: import a `.cxf`/JSON file, export a `.cxf`/JSON file. CXP would require shipping an HPKE crypto stack in the browser plus a device-pairing/handshake and mutual-attestation flow — a large, independent surface. Deferred and recorded as future work. Rejected alternative: build CXP now — rejected as disproportionate to v1 value; the file path already delivers the portability differentiator.

### D2 — CXF is a new format on the existing pipelines, not a new pipeline
The CXF import parser registers into `src/import/parserRegistry.js` and produces the same normalized row shape every other parser produces, so mapping preview, folder mapping, duplicate detection, chunked encrypted commit, rejected rows, and the summary report all apply **unchanged**. The CXF export writer sits alongside `backup.js`/`csv.js`, consuming the same client-decrypted serialized vault. This keeps the E2E contract (`secret-import`/`secret-export` specs) intact by construction — no new server code sees plaintext.

### D3 — CXF entity ↔ Doriath secret-type mapping table
A single mapping module owns the bidirectional table:

| CXF credential entity | Doriath secret type | Field mapping (import direction) |
|-----------------------|---------------------|----------------------------------|
| Basic auth / password / login | `login` | username → `login`, password → `key`, urls → `url`, notes → `additional_fields` |
| Passkey | `passkey` | credential id / rp id / user handle / private key / etc. → canonical passkey JSON in `key`, rp id → `url` (via `passkey-item-type`) |
| TOTP | `totp` | `otpauth://` URI or seed → `key` (via `add-totp-secrets`) |
| Note | `note` | content → `key`/`additional_fields` |
| API key | `api_key` | key → `key`, associated id → `login` |
| SSH key | `ssh_key` | private key → `key`, public key/metadata → `additional_fields` |
| Wi-Fi | `note` (custom fields) | SSID → `name`/`url`, passphrase → `key`, security type → `additional_fields` |

Export reverses each row. Types with no CXF entity, and CXF entities with no Doriath type, go to the unmapped-item report (D4). Passkey rows depend on the `passkey-item-type` canonical schema being present — this is the concrete reason for `depends_on`.

### D4 — Unmapped-item report reuses the rejected-rows / summary mechanism
Anything unrepresentable is surfaced, not dropped:

- **Import**: a CXF entity type Doriath cannot represent, or a CXF entry missing the fields its target type requires, lands in the existing import **rejected-rows list** with a human-readable reason and its source index, and is counted in the summary — reusing `secret-import`'s malformed-row mechanism exactly.
- **Export**: a Doriath value with no CXF home (e.g. a passkey's `counter`/`transports` extensions beyond CXF core, or a custom secret type) is recorded in an **export unmapped-item report** shown before download, so the user knows what will not survive the round-trip. Core credential material (usable to authenticate) always survives.

### D5 — CXF export is gated like plaintext CSV (warning + re-auth), event mode `cxf`
A raw CXF file is **plaintext** — the encryption in the FIDO stack lives in CXP, not CXF. Therefore CXF export MUST reuse the `secret-export` "Plaintext CSV Export with Re-Authentication" gating: an explicit unencrypted-file warning acknowledged first, then fresh master-password re-entry (a client-side proof that decrypts the stored private-key blob, discarded immediately after) even when the vault is already unlocked. The export reports to the existing export-event endpoint before offering the download, and the `SecretExportedEvent` records mode `cxf` (never secret names/values/ciphertext). Rejected alternative: treat CXF export like the encrypted `.doriath-backup` (no re-auth) — rejected because that backup is passphrase-encrypted whereas CXF is plaintext; the security posture must match the CSV path, not the backup path.

### D6 — Folder/collection mapping and duplicate detection reuse existing steps verbatim
CXF collections map onto Doriath folders through the existing `secret-import` folder-and-collection-mapping step (choose/create target, nested hierarchy preserved, idempotent creation, empty-folder suppression). Duplicate detection uses the existing client-side normalized name/url comparison against the vault (no decryption of existing secrets, per-row skip/import-as-copy). No new logic.

### Declarative-vs-imperative decision
Imperative, per ADR-001: Doriath owns its own tables and does **not** use OpenRegister. CXF import commits through Doriath's own authenticated batch endpoint into Doriath's own `doriath_secrets`/`doriath_folders` tables; CXF export reads client-decrypted values from those tables. There is no OR register, schema, or seed data involved.

## Decisions made under uncertainty

- **CXF document shape / entity naming.** CXF reached Proposed Standard Aug 2025; entity/field naming may still drift before final. Decision: isolate all format specifics in the single mapping module (D3) and validate a parsed document against the expected structure, failing at the parse step with a format-specific error (reusing `secret-import`'s "file does not match selected format" behaviour) rather than silently mis-mapping. A spec revision then touches only the mapping module.
- **Passkey extension round-trip.** `counter`/`transports`/`createdAt` are Doriath extensions beyond CXF passkey core (`passkey-item-type` D2). Decision: export best-effort (drop with an unmapped-item note if CXF has no home); import defaults them. The authenticating core credential always round-trips.
- **Wi-Fi credential home.** CXF Wi-Fi entries have no dedicated Doriath type. Decision: map to `note` with SSID/security-type as additional fields and the passphrase in `key`, rather than inventing a new system type in this change; a dedicated `wifi` type can be a later additive change. This keeps CXF scope to *format*, not *new types*.
- **Which export gating.** Chosen: the plaintext-CSV path (warning + re-auth), because CXF-the-file is plaintext. If a later change adds CXP, the encrypted transfer would follow the passphrase/backup posture instead.
- **`.cxf` vs `.json` extension.** Decision: accept both on import (detect by content structure, not extension) and emit `.cxf` on export with a JSON body, matching how the standard is surfacing in shipping implementations.

## Risks / Trade-offs

- **Plaintext CXF file on disk after export.** → Same risk and mitigation as the existing plaintext CSV export: explicit warning + re-auth + "delete after use" guidance. CXP (encrypted) is the future answer.
- **Standard still stabilising.** → Format specifics isolated in one mapping module; strict parse-time validation prevents silent mis-mapping.
- **Lossy round-trips for non-core fields / unrepresentable types.** → Never silent: the unmapped-item report (D4) tells the user exactly what did not map, on both directions.
- **Dependency on `passkey-item-type`.** → Explicit `depends_on`; passkey rows require the canonical schema and `passkey` type to exist. Non-passkey CXF entities do not depend on it.

## Migration / Rollout

- No data migration. CXF is an additive format on both existing pipelines; existing imports/exports are unchanged. Ships behind the normal wizard format selection. `passkey-item-type` must land first (seeds the `passkey` type and canonical schema the CXF passkey mapping targets).
