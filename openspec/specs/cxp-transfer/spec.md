# CXP Transfer Specification

**Status**: in-progress

**OpenSpec changes:** [openspec/changes/cxp-transfer](../../changes/cxp-transfer/)

## Purpose

Close the encrypted-transport gap the `cxf-import-export` change deliberately left open (`openspec/changes/cxf-import-export/proposal.md:28`). File-based CXF export is a **plaintext** credential document — Doriath even gates it like the plaintext CSV export for that reason — and the resulting `.cxf` file forgotten in `~/Downloads` is the single riskiest artifact of any migration. The FIDO Credential Exchange **Protocol** (CXP, RFC 9180 HPKE) eliminates that artifact: the CXF payload travels provider-to-provider inside an HPKE-sealed envelope and **no plaintext file is ever written to disk**, in either direction. The ecosystem is converging here now (Apple iOS/macOS 26, Bitwarden shipped, 1Password/Dashlane/Proton following), and no Nextcloud-native app offers it — a strong story for the security-conscious public-sector buyer. CXP is only the transport wrapper around the CXF payload the sibling change already produces and consumes, so it is cheap to add and depends on `cxf-import-export`.

## Requirements

### Requirement: Client-side HPKE seal and open
The system MUST perform all CXP payload seal/open with HPKE (RFC 9180) entirely in the browser; the ephemeral opening private key MUST never leave the browser and MUST be discarded after open; the server and any relay MUST see only public keys and HPKE ciphertext.

#### Scenario: Sealed payload is opened only in the browser
- GIVEN an HPKE-sealed CXF envelope addressed to Doriath's ephemeral public key
- WHEN Doriath receives it
- THEN the system MUST open it in the browser and transmit no plaintext or opening private key to the server or relay

### Requirement: Doriath as importing provider
The system MUST let Doriath generate a keypair, produce a CXP request, receive and client-side-decrypt the sealed CXF payload, and feed it into the existing CXF import pipeline, writing no plaintext file to disk.

#### Scenario: Sealed CXF imports without a plaintext file
- GIVEN a cooperating exporting provider and a Doriath CXP import request
- WHEN Doriath opens the sealed CXF payload
- THEN the decrypted CXF MUST flow through the existing import pipeline and no plaintext file MUST be written to disk

### Requirement: Doriath as exporting provider
The system MUST let Doriath receive a CXP request, gate the export with the existing fresh master-password re-auth, assemble the CXF payload client-side via the existing export path, HPKE-seal it under the requester's public key, and return only the sealed envelope, writing no plaintext file to disk.

#### Scenario: Only a sealed envelope leaves Doriath
- GIVEN a re-authenticated CXP export
- WHEN Doriath assembles the CXF payload
- THEN the payload MUST be sealed under the requester's public key in the browser and only the sealed envelope MUST leave, with no plaintext file written to disk

### Requirement: CXP transfer emits an export event with mode cxp
The system MUST report a CXP export to the existing export-event endpoint so a `SecretExportedEvent` with mode `cxp` is emitted, carrying no secret names, values, or ciphertext.

#### Scenario: Event records the sealed transfer without secret material
- GIVEN a completed CXP export
- WHEN the export-event endpoint is called
- THEN a `SecretExportedEvent` with mode `cxp` MUST be emitted with no secret names, values, or ciphertext

### Requirement: v1 scope is the browser-session flow
The system MUST implement CXP within a browser session against a cooperating provider in v1 with no native OS credential-provider integration, and MUST leave the file-based CXF path available as the fallback when no CXP peer exists.

#### Scenario: Falls back to file-based CXF when no CXP peer exists
- GIVEN a target provider that offers only file-based CXF
- WHEN the user attempts a migration
- THEN CXP MUST NOT be forced and the existing file-based CXF path MUST remain available

## User Stories

- As a user migrating in from another manager, I want my credentials to arrive encrypted end to end so that no plaintext file of my whole vault ever lands on my disk.
- As a user leaving Doriath, I want to hand my vault directly to the destination provider sealed so that I never create a plaintext export file to forget about.
- As a security-conscious public-sector buyer, I want standards-based encrypted transfer so that migration is not the weakest link in the vault's security.

## Acceptance Criteria

- [ ] HPKE (RFC 9180) seal/open entirely client-side; ephemeral private key never leaves the browser and is discarded after open
- [ ] Importing flow: keypair → CXP request → open sealed CXF → existing import pipeline; no plaintext file on disk
- [ ] Exporting flow: CXP request → re-auth → existing CXF assembly → seal for requester → sealed envelope only; no plaintext file on disk
- [ ] Unrepresentable entities reported via the existing unmapped-item report, not dropped
- [ ] CXP export emits `SecretExportedEvent` mode `cxp` with no secret material
- [ ] Suite/version mismatch fails fast; v1 is browser-session only; file-based CXF remains the fallback

## Notes

- **Depends on `cxf-import-export`** for the CXF payload, mapping, import pipeline, and export assembly + re-auth gating — that change must land first.
- CXP version + HPKE suite are pinned in one isolated transport module (design decision), interoperable with a shipping reference peer (Bitwarden).
- ADR-001 (own tables, no OpenRegister), ADR-003 (always client-side crypto). Reuses the `secret-export` event contract with a `cxp` mode.
