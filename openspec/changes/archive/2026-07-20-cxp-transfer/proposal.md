---
kind: code
depends_on: [cxf-import-export]
---

# Proposal: FIDO Credential Exchange Protocol (CXP) — encrypted provider-to-provider transfer

## Why

The sibling `cxf-import-export` change ships the FIDO Credential Exchange **Format** (CXF) as a file: import a `.cxf`/JSON document, export a `.cxf`/JSON document. It explicitly records the encrypted transport as out of scope and future work — `openspec/changes/cxf-import-export/proposal.md:28` ("**Explicitly out of scope for v1 — CXP (the encrypted Credential Exchange Protocol, HPKE)** … recorded as future work, not built here") and design decision D1 (`openspec/changes/cxf-import-export/design.md:26`). **This change is that future work.**

The gap is a real security hole in the migration story. A file-based CXF export is a **plaintext credential document** — the encryption in the FIDO stack lives in CXP, not CXF (stated at `cxf-import-export` design D5, `openspec/changes/cxf-import-export/design.md:53`). Doriath already gates CXF export like the plaintext CSV export (explicit unencrypted-file warning + fresh master-password re-auth) precisely because the artifact is plaintext. But even gated, the user still ends up with a plaintext `.cxf` file sitting in `~/Downloads` that they forget to delete — the single riskiest artifact of any password-manager migration. **CXP eliminates that artifact entirely**: the credentials travel directly from provider to provider inside an HPKE-sealed envelope, and no plaintext file ever touches disk.

The ecosystem is converging here now. **Apple ships CXF-based transfer in iOS 26 / macOS 26** (2025); **Bitwarden is the first third party to implement the encrypted CXP transfer**, with **1Password, Dashlane, and Proton Pass** publicly following (`openspec/changes/cxf-import-export/proposal.md:13`). For the security-conscious public-sector buyer — the audience that made the LastPass plaintext-vault fallout a purchasing criterion — "migrate in and out with **no plaintext file ever written**" is a materially stronger story than file-based CXF alone, and no Nextcloud-native app offers it.

Doriath is uniquely positioned to add CXP cheaply because the hard part — the CXF format layer and its client-side parse/encrypt (import) and decrypt/assemble (export) pipelines — already exists after `cxf-import-export`. CXP is the **transport wrapper** around that same CXF payload: an HPKE seal/open plus a request/response handshake. This change **depends on `cxf-import-export`** for the CXF payload it seals and unseals.

## What Changes

- **Add an HPKE crypto layer** (RFC 9180) in the browser: generate an ephemeral recipient keypair, produce and consume the HPKE-sealed envelope, all client-side (ADR-003). Import decrypts the sealed CXF payload in the browser; export seals the client-assembled CXF payload for the requester's public key in the browser.
- **Doriath as the IMPORTING provider**: generate a recipient keypair, produce a **CXP request** carrying Doriath's ephemeral public key, hand it to the exporting provider (browser-session flow — see scope), receive the HPKE-sealed CXF payload, **decrypt it client-side**, and feed the resulting CXF document straight into the **existing `cxf-import-export` import pipeline** (mapping preview → folder mapping → duplicate detection → chunked encrypted commit → unmapped-item report → summary) with **no plaintext file written to disk** at any point.
- **Doriath as the EXPORTING provider**: receive a **CXP request** carrying the requester's public key, assemble the CXF export **client-side using the existing `cxf-import-export` export path** (client-side decrypt + assemble, gated by the same fresh master-password re-auth), **HPKE-seal** the assembled CXF payload under the requester's public key in the browser, and hand back only the sealed envelope — **never a plaintext file**.
- **Reuse the existing CXF mapping and E2E contract unchanged**: CXP changes only the *transport* (sealed payload instead of a file). The CXF↔Doriath-type mapping, the unmapped-item report, and the always-client-side parse/assemble guarantee are inherited from `cxf-import-export` verbatim.
- **Emit transfer events on the existing export/audit surface**: an export via CXP MUST report to the existing export-event endpoint with a distinct transfer mode (`cxp`) so the `SecretExportedEvent` records the transfer without any secret material — the same event contract CXF export uses, distinguished only by mode.
- **Explicitly out of scope for v1 — native OS credential-provider integration**: v1 targets the **browser-session flow** only (the CXP request/response handled within a Doriath browser session against a cooperating provider). The native platform credential-provider handoff (OS-mediated app-to-app transfer as Apple/Bitwarden ship it at the OS level) needs platform-specific integration and is recorded as future work, not built here.

### Non-Goals

- **No native OS / platform credential-provider integration** — browser-session CXP flow only in v1.
- **No new CXF mapping or a second import/export pipeline** — CXP wraps the CXF payload the existing `cxf-import-export` pipelines already produce and consume.
- **No plaintext file on disk in either direction** — the entire point; if a plaintext file is wanted, that is the existing file-based CXF path, not this one.

## Capabilities

### New Capabilities

- `cxp-transfer`: FIDO Credential Exchange **Protocol** (CXP, RFC 9180 HPKE) direct provider-to-provider encrypted credential transfer — Doriath as importing provider (keypair + CXP request → receive + client-side-decrypt the sealed CXF payload → existing CXF import pipeline) and as exporting provider (receive CXP request → existing CXF export assembly → HPKE-seal for the requester → return sealed envelope). No plaintext file ever written. Browser-session flow only in v1. Canonical home for the CXP transport contract.

### Modified Capabilities

_(none — CXP is a new transport layered over `cxf-import-export`'s existing import and export pipelines; it reuses their requirements unchanged rather than modifying them.)_

## Impact

- **Database**: none — no table, column, or migration. CXP reuses the CXF import pipeline's existing commit path and the export path's existing event endpoint.
- **Backend**: none new for the crypto/transfer itself (HPKE seal/open is client-side per ADR-003); the export-event endpoint gains a `cxp` mode value on the existing `SecretExportedEvent`. A minimal relay endpoint MAY be required only to exchange the CXP request/sealed-envelope handshake with a cooperating provider — carrying opaque sealed bytes and public keys only, never plaintext.
- **Frontend**: an HPKE (RFC 9180) browser crypto module; the CXP request/response handshake UI (initiate an import request; respond to an export request); wiring the decrypted CXF payload into the existing import pipeline and the assembled CXF payload out through the existing export path.
- **API**: no new secret-bearing route; at most an opaque request/sealed-envelope relay carrying only public keys and HPKE ciphertext.
- **Cross-capability**: **depends on `cxf-import-export`** for the CXF payload, the CXF↔type mapping, the import pipeline, and the export assembly + re-auth gating; reuses `secret-export`'s event contract with a `cxp` mode.
- **Security**: strictly stronger than file-based CXF — the CXF payload is HPKE-sealed end to end and **never written to disk as plaintext** in either direction. HPKE seal/open happens client-side under ADR-003; the server (and any relay) sees only sealed bytes and public keys. Export remains gated by the existing fresh master-password re-auth.
