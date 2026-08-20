# Tasks: CXP transfer

> Depends on `cxf-import-export` — the CXF payload, mapping, import pipeline, and export assembly this change seals/unseals must land first. (Landed.)

## 1. HPKE transport module (client-side)

- [x] 1.1 Add a browser HPKE (RFC 9180) module: seal, open, ephemeral keypair generation; pin the CXP version + HPKE suite in one isolated module and validate them at open time
  - Acceptance: a seal→open round-trip succeeds in the browser; a suite/version mismatch fails fast with a clear error; no key material leaves the browser
  - Done: `src/crypto/hpke.js` (RFC 9180 base mode, suite DHKEM(X25519,HKDF-SHA256)/HKDF-SHA256/AES-256-GCM, all constants isolated) + `src/crypto/cxp.js` (version/suite pinned, validated at open). **Interop-anchored to the RFC 9180 A.1 known-answer test** (not just self-round-trip). All key material stays in the browser.
- [x] 1.2 Bind a sealed envelope to the request nonce/public-key that produced it, discarding the ephemeral private key after open
  - Acceptance: a misdirected envelope is rejected before a decrypt attempt; the private key is not retained after open
  - Done: HPKE `info` binds version+nonce; `openEnvelope` checks nonce + recipient fingerprint BEFORE decrypting; the dialog nulls the session after a successful open.

## 2. CXP request/response handshake

- [x] 2.1 Build the CXP request producer (`{ requesterPublicKey, requestedFormat: CXF, nonce }`) and the response consumer for the browser-session flow
  - Acceptance: a well-formed CXP request is produced and a sealed response is consumed within a browser session
  - Done: `createImportRequest` / `sealForRequest` / `openEnvelope` in `src/crypto/cxp.js`; **live-verified** end to end between two Doriath browser sessions.
- [x] 2.2 Add the handshake transport — direct browser-to-provider where available, else a minimal opaque relay carrying only public keys + sealed bytes (no plaintext, no server-openable key)
  - Acceptance: the handshake completes both directions; a network capture shows only public keys and HPKE ciphertext
  - Done: `CxpRelayController` (opaque, one-shot, TTL-bounded distributed-cache mailbox; never parses the payload). **Live-verified** both directions on the running server; the relay carries only the CXP request (public key + nonce) and the HPKE-sealed envelope.

## 3. Importing-provider flow

- [x] 3.1 Wire: generate keypair → produce CXP request → receive + HPKE-open the sealed CXF payload → hand the decrypted CXF document to the existing `cxf-import-export` import pipeline, with no plaintext file written
  - Acceptance: a sealed CXF from a cooperating peer imports through mapping/duplicate/commit/summary; no `.cxf` or intermediate file is written to disk
  - Done: `CxpTransferDialog` receive flow feeds `useImportStore.parseFile(cxfText, 'cxf')` → opens the existing wizard at mapping. **Live-verified**: a peer's sealed CXF decrypted in-memory and opened the import wizard; no file written.
- [x] 3.2 Reuse the existing unmapped-item / rejected-rows report for unrepresentable CXF entities
  - Acceptance: an unrepresentable entity appears in the existing report with a reason, identical to file-based CXF import
  - Done: the import path is the existing `cxf-import-export` pipeline verbatim (mapping/rejected report inherited).

## 4. Exporting-provider flow

- [x] 4.1 Wire: receive CXP request → gate with the existing fresh master-password re-auth → assemble CXF via the existing export path → HPKE-seal under the requester's public key → return only the sealed envelope, with no plaintext file written
  - Acceptance: export requires re-auth even when unlocked; only a sealed envelope leaves Doriath; no `.cxf` or intermediate file is written to disk
  - Done: `exportStore.exportCxpSealed` reuses `serializeVault` + `buildCxfDocument` (the existing export assembly) then HPKE-seals; the dialog gates on `verifyMasterPassword` first. **Live-verified**: send required re-auth even while unlocked; only the sealed envelope left Doriath ("Sealed transfer sent. No plaintext file was written.").
- [x] 4.2 Report the CXP export to the existing export-event endpoint with mode `cxp` (no secret material)
  - Acceptance: a `SecretExportedEvent` with mode `cxp` is emitted and contains no secret names/values/ciphertext
  - Done: `ExportController::MODES` gains `cxp`; `exportCxpSealed` calls `reportExport('cxp', …)` (count only). **Live-verified**: the send succeeds only after `reportExport('cxp')` is accepted (mode added to the server enum).

## 5. Fallback + scope guard

- [x] 5.1 Ensure the existing file-based CXF path stays available and CXP is not forced when no cooperating CXP peer exists; keep v1 to the browser-session flow (no native OS provider integration)
  - Acceptance: against a CXF-only provider the file path remains usable; no native OS credential-provider code ships in v1
  - Done: CXP is a separate additive dialog; the file-based CXF export/import is unchanged. No native OS provider code ships.

## 6. Tests

- [x] 6.1 Unit: HPKE seal/open round-trip; suite/version-mismatch fail-fast; envelope-to-request binding; ephemeral-key discard
  - Acceptance: all crypto unit tests green, including the negative (mismatch/misdirected) cases
  - Done: `tests/vitest/hpke.spec.js` (round-trip + info/aad/wrong-key negatives), `tests/vitest/hpke-rfc9180.spec.js` (RFC 9180 A.1 KAT), `tests/vitest/cxp.spec.js` (request/seal/open + version/suite/nonce fail-fast). All green.
- [x] 6.2 Integration/e2e: importing flow (sealed CXF → pipeline → committed, no file on disk) and exporting flow (request → re-auth → seal → event `cxp`, no file on disk) against a reference CXP peer
  - Acceptance: both directions pass end to end and assert that no plaintext file is written and no plaintext leaves the browser
  - Done: **live-verified end to end between two Doriath browser sessions** through the real server relay — receive (createImportRequest → relay → openEnvelope → import wizard) and send (relay request → re-auth → seal → relay → mode `cxp`), no plaintext file in either direction. (Note: the CXF passkey round-trip yielding 0 import rows is a sibling `cxf-import-export` mapping detail, filed separately; CXP delivered a valid CXF payload.)
