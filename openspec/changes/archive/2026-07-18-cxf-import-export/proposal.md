---
kind: code
depends_on: [passkey-item-type]
---

# Proposal: FIDO Credential Exchange Format (CXF) import and export

## Why

The **FIDO Credential Exchange Format (CXF)** reached FIDO Alliance **Proposed Standard in August 2025** — the first vendor-neutral, standards-based way to move a full set of credentials (passwords, passkeys, TOTP seeds, notes, API keys, SSH keys, Wi-Fi credentials) between managers without a lossy CSV. It is arriving fast in the platforms Doriath's users already run:

- **Apple ships CXF-based credential transfer in iOS 26 / macOS 26** (2025), making it the default handoff format on hundreds of millions of devices.
- **Bitwarden is the first third-party to implement the encrypted CXP transfer**, with **1Password, Dashlane, and Proton Pass** publicly following.

Standards-based portability is precisely the objection Doriath most needs to answer for orgs migrating **off** an incumbent. The **LastPass** breach fallout continued into a trust collapse capped by an **ICO fine of roughly £1.2M in late 2025**; buyers evaluating a replacement now ask "can I get my data *out* of you the same standard way I got it in?" A CXF importer/exporter kills the lock-in objection — and **no Nextcloud-native app offers it**, so it is a clean differentiator, not a catch-up.

Doriath already has the machinery: the client-side import pipeline parses and encrypts entirely in the browser via a pluggable **parser registry** (verified: `src/import/parserRegistry.js` exposes `registerParser`/`getParser`/`listParsers`; format parsers live in `src/import/parsers/` — `bitwarden.js`, `csv.js`, `keepassXml.js`, `ncPasswords.js`), and the export pipeline decrypts and assembles client-side via `src/export/serializer.js` + format writers (`backup.js`, `csv.js`, `gdprPackage.js`). Import and export are both already shipped and marked **V1 ✅ Built** in the feature matrix (`docs/FEATURES.md:72`, `:73`). CXF is a **new format registered into those existing pipelines**, bidirectional, with **zero new backend routes** and the same always-E2E guarantee (ADR-003) every other format already honours.

This change also completes the passkey story: the sibling `passkey-item-type` change defines a canonical passkey schema deliberately aligned 1:1 with the CXF passkey entity, so CXF becomes the standards-based way to move passkeys in and out of Doriath. This change **depends on** `passkey-item-type` for that mapping target.

## What Changes

- **Add a CXF import parser** registered in the existing `src/import/parserRegistry.js`, parsing a CXF JSON document entirely in the browser and mapping its credential entities to Doriath secret types — **passwords/logins → `login`, passkeys → `passkey`, TOTP → `totp`, notes → `note`, API keys → `api_key`, SSH keys → `ssh_key`, Wi-Fi → `note`/custom** — encrypting every sensitive field with the owner's active EncryptionSuite public certificate before anything is persisted (same client-side parse+encrypt contract as every existing format).
- **Add a CXF export writer** that decrypts the selected vault client-side and assembles a standards-conformant CXF JSON document (reverse mapping Doriath secret types → CXF credential entities), generated in the browser and never assembled server-side (same client-side decrypt+assemble contract as the existing encrypted/CSV export).
- **Reuse the existing import machinery for import**: field-mapping preview, **folder/collection mapping** (CXF collections → Doriath folders), **duplicate detection** against the existing vault on normalized name/url, malformed-row rejection, chunked encrypted batch commit, and the import summary report — no new mechanism.
- **Produce an unmapped-item report**: CXF entity types Doriath cannot represent (or fields with no Doriath home) MUST be surfaced to the user via the existing rejected-rows / summary mechanism with a clear reason, rather than being silently dropped, on both import (unrepresentable CXF entity) and export (Doriath value with no CXF home, e.g. passkey `counter`/`transports` extensions).
- **Gate CXF export like the existing plaintext CSV export**: a raw CXF file is a *plaintext* interchange document (the encryption lives in CXP, not CXF), so CXF export MUST require the same explicit unencrypted-file warning **and** fresh master-password re-authentication the plaintext CSV export already enforces, and the emitted `SecretExportedEvent` MUST record mode `cxf`.
- **Explicitly out of scope for v1 — CXP (the encrypted Credential Exchange Protocol, HPKE)**: v1 is **file-based CXF only** (import a `.cxf`/JSON file, export a `.cxf`/JSON file). The HPKE-encrypted device-to-device transfer protocol needs an HPKE crypto stack and a device-pairing/handshake flow; it is recorded as future work, not built here.

### Non-Goals

- **No CXP / HPKE encrypted transfer, no device pairing, no live app-to-app handoff** — file-based CXF only in v1.
- **No new backend routes and no server-side parsing/assembly** — CXF import reuses the existing authenticated chunked batch-commit endpoint; CXF export reuses the existing export-event endpoint; parsing and assembly are client-side only (ADR-003).
- **No lossless guarantee for non-representable data** — anything Doriath or CXF cannot represent is reported to the user, never silently dropped.

## Capabilities

### New Capabilities

- `cxf-import-export`: Bidirectional, client-side, file-based FIDO CXF import and export — CXF-entity ↔ Doriath-secret-type mapping (including passkeys via `passkey-item-type`), folder/collection mapping and duplicate detection reusing the existing import machinery, an unmapped-item report, and re-auth-gated CXF export. Canonical home for the CXF format contract.

### Modified Capabilities

_(none — CXF is a new format registered into the existing `secret-import` and `secret-export` pipelines; it reuses their requirements unchanged rather than modifying them.)_

## Impact

- **Database**: none — no new table, column, or migration. CXF reuses the existing secret/folder tables via the existing commit path.
- **Backend**: none new — reuses the existing authenticated chunked batch-commit endpoint (import) and the existing export-event endpoint (`SecretExportedEvent` gains a `cxf` mode value).
- **Frontend**: a CXF import parser registered in `src/import/parserRegistry.js`; a CXF export writer alongside `src/export/backup.js`/`csv.js`; the CXF↔type mapping module; wizard entries for CXF in both flows; the unmapped-item report wired into the existing rejected-rows/summary UI.
- **API**: none new.
- **Cross-capability**: **depends on `passkey-item-type`** for the passkey mapping target; reuses `secret-import` (mapping preview, folder mapping, duplicate detection, chunked commit, rejected rows, summary) and `secret-export` (client-side assembly, warning + re-auth gating, `SecretExportedEvent`) unchanged.
- **Security**: same always-E2E guarantee as every other format — CXF import encrypts client-side before persistence and never sends plaintext to the server; CXF export assembles client-side and is gated by warning + fresh master-password re-auth because a raw CXF file is plaintext (ADR-003).
