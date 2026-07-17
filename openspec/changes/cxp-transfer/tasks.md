# Tasks: CXP transfer

> Depends on `cxf-import-export` — the CXF payload, mapping, import pipeline, and export assembly this change seals/unseals must land first.

## 1. HPKE transport module (client-side)

- [ ] 1.1 Add a browser HPKE (RFC 9180) module: seal, open, ephemeral keypair generation; pin the CXP version + HPKE suite in one isolated module and validate them at open time
  - Acceptance: a seal→open round-trip succeeds in the browser; a suite/version mismatch fails fast with a clear error; no key material leaves the browser
- [ ] 1.2 Bind a sealed envelope to the request nonce/public-key that produced it, discarding the ephemeral private key after open
  - Acceptance: a misdirected envelope is rejected before a decrypt attempt; the private key is not retained after open

## 2. CXP request/response handshake

- [ ] 2.1 Build the CXP request producer (`{ requesterPublicKey, requestedFormat: CXF, nonce }`) and the response consumer for the browser-session flow
  - Acceptance: a well-formed CXP request is produced and a sealed response is consumed within a browser session
- [ ] 2.2 Add the handshake transport — direct browser-to-provider where available, else a minimal opaque relay carrying only public keys + sealed bytes (no plaintext, no server-openable key)
  - Acceptance: the handshake completes both directions; a network capture shows only public keys and HPKE ciphertext

## 3. Importing-provider flow

- [ ] 3.1 Wire: generate keypair → produce CXP request → receive + HPKE-open the sealed CXF payload → hand the decrypted CXF document to the existing `cxf-import-export` import pipeline, with no plaintext file written
  - Acceptance: a sealed CXF from a cooperating peer imports through mapping/duplicate/commit/summary; no `.cxf` or intermediate file is written to disk
- [ ] 3.2 Reuse the existing unmapped-item / rejected-rows report for unrepresentable CXF entities
  - Acceptance: an unrepresentable entity appears in the existing report with a reason, identical to file-based CXF import

## 4. Exporting-provider flow

- [ ] 4.1 Wire: receive CXP request → gate with the existing fresh master-password re-auth → assemble CXF via the existing export path → HPKE-seal under the requester's public key → return only the sealed envelope, with no plaintext file written
  - Acceptance: export requires re-auth even when unlocked; only a sealed envelope leaves Doriath; no `.cxf` or intermediate file is written to disk
- [ ] 4.2 Report the CXP export to the existing export-event endpoint with mode `cxp` (no secret material)
  - Acceptance: a `SecretExportedEvent` with mode `cxp` is emitted and contains no secret names/values/ciphertext

## 5. Fallback + scope guard

- [ ] 5.1 Ensure the existing file-based CXF path stays available and CXP is not forced when no cooperating CXP peer exists; keep v1 to the browser-session flow (no native OS provider integration)
  - Acceptance: against a CXF-only provider the file path remains usable; no native OS credential-provider code ships in v1

## 6. Tests

- [ ] 6.1 Unit: HPKE seal/open round-trip; suite/version-mismatch fail-fast; envelope-to-request binding; ephemeral-key discard
  - Acceptance: all crypto unit tests green, including the negative (mismatch/misdirected) cases
- [ ] 6.2 Integration/e2e: importing flow (sealed CXF → pipeline → committed, no file on disk) and exporting flow (request → re-auth → seal → event `cxp`, no file on disk) against a reference CXP peer
  - Acceptance: both directions pass end to end and assert that no plaintext file is written and no plaintext leaves the browser
